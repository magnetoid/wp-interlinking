<?php
/**
 * AI-powered interlinking features using multiple LLM providers.
 *
 * Supported providers:
 *  - OpenAI (gpt-4o-mini, gpt-4o)
 *  - Anthropic / Claude (claude-sonnet-4-20250514, claude-haiku-4-5-20251001)
 *  - Ollama — self-hosted, no API key required
 *  - Google Gemini (gemini-2.0-flash, gemini-2.0-flash-lite)
 *  - Mistral AI (mistral-small-latest, mistral-large-latest)
 *  - DeepSeek (deepseek-chat, deepseek-reasoner)
 *
 * Provides four AI capabilities:
 *  1. Keyword Extraction — Analyze post content to extract SEO-relevant key phrases.
 *  2. Relevance Scoring — Score how well a keyword matches candidate posts for linking.
 *  3. Content Gap Analysis — Discover posts that should link to each other but don't.
 *  4. Auto-Generate Mappings — One-click AI scan to propose a complete interlinking strategy.
 *
 * Security:
 *  - API key stored encrypted in wp_options (sodium if available, base64 fallback).
 *  - All inputs sanitised; all outputs escaped.
 *  - No API key is ever exposed in HTML or JS (only a masked preview).
 *
 * @since   2.0.0
 * @package FPP_Interlinking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FPP_Interlinking_AI {

	/**
	 * Option names for AI settings.
	 */
	const OPTION_API_KEY    = 'fpp_interlinking_ai_api_key';
	const OPTION_MODEL      = 'fpp_interlinking_ai_model';
	const OPTION_PROVIDER   = 'fpp_interlinking_ai_provider';
	const OPTION_MAX_TOKENS = 'fpp_interlinking_ai_max_tokens';
	const OPTION_BASE_URL   = 'fpp_interlinking_ai_base_url';

	/**
	 * Provider metadata: label, whether an API key is required, model suggestions.
	 *
	 * @since 4.0.0
	 */
	const PROVIDER_INFO = array(
		'openai'    => array(
			'label'        => 'OpenAI',
			'requires_key' => true,
			'models'       => 'gpt-4o-mini, gpt-4o',
		),
		'anthropic' => array(
			'label'        => 'Anthropic (Claude)',
			'requires_key' => true,
			'models'       => 'claude-sonnet-4-20250514, claude-haiku-4-5-20251001',
		),
		'ollama'    => array(
			'label'        => 'Ollama (Self-hosted)',
			'requires_key' => false,
			'models'       => 'llama3.2, mistral, gemma2, qwen2.5',
		),
		'gemini'    => array(
			'label'        => 'Google Gemini',
			'requires_key' => true,
			'models'       => 'gemini-2.0-flash, gemini-2.0-flash-lite',
		),
		'mistral'   => array(
			'label'        => 'Mistral AI',
			'requires_key' => true,
			'models'       => 'mistral-small-latest, mistral-large-latest',
		),
		'deepseek'  => array(
			'label'        => 'DeepSeek',
			'requires_key' => true,
			'models'       => 'deepseek-chat, deepseek-reasoner',
		),
	);

	/**
	 * Default model per provider.
	 */
	const DEFAULT_MODELS = array(
		'openai'    => 'gpt-4o-mini',
		'anthropic' => 'claude-sonnet-4-20250514',
		'ollama'    => 'llama3.2',
		'gemini'    => 'gemini-2.0-flash',
		'mistral'   => 'mistral-small-latest',
		'deepseek'  => 'deepseek-chat',
	);

	/**
	 * API endpoints per provider.
	 */
	const API_ENDPOINTS = array(
		'openai'    => 'https://api.openai.com/v1/chat/completions',
		'anthropic' => 'https://api.anthropic.com/v1/messages',
		'gemini'    => 'https://generativelanguage.googleapis.com/v1beta/models/',
		'mistral'   => 'https://api.mistral.ai/v1/chat/completions',
		'deepseek'  => 'https://api.deepseek.com/v1/chat/completions',
	);

	/* ── API Key Encryption ──────────────────────────────────────────── */

	/**
	 * Encrypt and store the API key.
	 *
	 * Uses sodium_crypto_secretbox when the PHP sodium extension is loaded,
	 * otherwise falls back to a basic base64 encoding (not secure against
	 * DB-level access, but prevents casual exposure in the options table).
	 *
	 * @since 2.0.0
	 *
	 * @param string $api_key Plain-text API key.
	 * @return bool Whether the option was saved.
	 */
	public static function save_api_key( $api_key ) {
		$api_key = sanitize_text_field( $api_key );

		if ( empty( $api_key ) ) {
			delete_option( self::OPTION_API_KEY );
			return true;
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			try {
				$key       = self::get_encryption_key();
				$nonce     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$encrypted = sodium_crypto_secretbox( $api_key, $nonce, $key );
				$stored    = base64_encode( $nonce . $encrypted );
			} catch ( \Exception $e ) {
				// Sodium or random_bytes failed; fall back to base64.
				$stored = 'b64:' . base64_encode( $api_key );
			}
		} else {
			$stored = 'b64:' . base64_encode( $api_key );
		}

		return update_option( self::OPTION_API_KEY, $stored, false );
	}

	/**
	 * Retrieve and decrypt the API key.
	 *
	 * Handles three storage formats:
	 *  1. 'b64:…' — base64-encoded fallback (no sodium extension).
	 *  2. Binary blob — sodium-encrypted (nonce + ciphertext).
	 *  3. Empty string — no key configured.
	 *
	 * @since 2.0.0
	 *
	 * @return string Plain-text API key, or empty string.
	 */
	public static function get_api_key() {
		$stored = get_option( self::OPTION_API_KEY, '' );

		if ( empty( $stored ) || ! is_string( $stored ) ) {
			return '';
		}

		// Base64 fallback.
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			$decoded = base64_decode( substr( $stored, 4 ), true );
			return ( false !== $decoded ) ? $decoded : '';
		}

		// Sodium decryption.
		if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$decoded = base64_decode( $stored, true );

			if ( false === $decoded ) {
				return '';
			}

			// Verify the decoded data is long enough to contain nonce + ciphertext.
			if ( strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$key    = self::get_encryption_key();
			$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			try {
				$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			} catch ( \SodiumException $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[WP Interlinking] Sodium decryption failed: ' . $e->getMessage() );
				}
				return '';
			}

			return ( false !== $plain ) ? $plain : '';
		}

		return '';
	}

	/**
	 * Get a masked version of the API key for display.
	 *
	 * @since 2.0.0
	 *
	 * @return string e.g. "sk-...abc123" or empty.
	 */
	public static function get_masked_key() {
		$key = self::get_api_key();
		if ( empty( $key ) ) {
			return '';
		}
		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . '...' . substr( $key, -4 );
	}

	/**
	 * Derive a 32-byte encryption key from AUTH_KEY or a fallback.
	 *
	 * @since 2.0.0
	 *
	 * @return string 32-byte key.
	 */
	private static function get_encryption_key() {
		$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'fpp-interlinking-default-key';
		return hash( 'sha256', $secret, true );
	}

	/* ── Provider / Model Helpers ────────────────────────────────────── */

	/**
	 * Get the configured AI provider.
	 *
	 * @since 2.0.0
	 *
	 * @return string Provider key (e.g. 'openai', 'anthropic', 'ollama', 'gemini', 'mistral', 'deepseek').
	 */
	public static function get_provider() {
		$provider = get_option( self::OPTION_PROVIDER, 'openai' );
		return isset( self::PROVIDER_INFO[ $provider ] ) ? $provider : 'openai';
	}

	/**
	 * Get the configured model name.
	 *
	 * @since 2.0.0
	 *
	 * @return string Model identifier.
	 */
	public static function get_model() {
		$provider = self::get_provider();
		$default  = isset( self::DEFAULT_MODELS[ $provider ] ) ? self::DEFAULT_MODELS[ $provider ] : 'gpt-4o-mini';
		return get_option( self::OPTION_MODEL, $default );
	}

	/**
	 * Get max tokens for API calls.
	 *
	 * @since 2.0.0
	 *
	 * @return int
	 */
	public static function get_max_tokens() {
		return (int) get_option( self::OPTION_MAX_TOKENS, 2000 );
	}

	/**
	 * Get the Ollama base URL.
	 *
	 * @since 4.0.0
	 *
	 * @return string Base URL (e.g. 'http://localhost:11434').
	 */
	public static function get_base_url() {
		return get_option( self::OPTION_BASE_URL, 'http://localhost:11434' );
	}

	/* ── Rate Limiting ──────────────────────────────────────────────── */

	/**
	 * Minimum seconds between AI API calls (per user).
	 *
	 * @since 2.1.0
	 */
	const RATE_LIMIT_SECONDS = 10;

	/**
	 * Check if the current user is rate-limited.
	 *
	 * Uses a transient keyed by user ID to prevent rapid-fire API calls
	 * that could result in excessive costs.
	 *
	 * @since 2.1.0
	 *
	 * @return true|WP_Error True if allowed, WP_Error if rate-limited.
	 */
	public static function check_rate_limit() {
		$user_id       = get_current_user_id();
		$transient_key = 'fpp_ai_rate_limit_' . $user_id;

		if ( get_transient( $transient_key ) ) {
			return new \WP_Error(
				'rate_limited',
				sprintf(
					/* translators: %d: number of seconds to wait. */
					__( 'Please wait %d seconds between AI requests to avoid excessive API costs.', 'fpp-interlinking' ),
					self::RATE_LIMIT_SECONDS
				)
			);
		}

		set_transient( $transient_key, 1, self::RATE_LIMIT_SECONDS );
		return true;
	}

	/* ── Core API Call ───────────────────────────────────────────────── */

	/**
	 * Send a prompt to the configured AI provider and return the text response.
	 *
	 * Routes to the correct provider-specific method based on the configured provider.
	 * OpenAI-compatible providers (OpenAI, Mistral, DeepSeek) share the same handler.
	 *
	 * @since 2.0.0
	 *
	 * @param string $system_prompt System/context prompt.
	 * @param string $user_prompt   User message.
	 * @param float  $temperature   Sampling temperature (0.0 – 1.0).
	 * @return string|WP_Error The AI response text or WP_Error.
	 */
	public static function call_api( $system_prompt, $user_prompt, $temperature = 0.3 ) {
		$provider   = self::get_provider();
		$model      = self::get_model();
		$max_tokens = self::get_max_tokens();

		// Ollama does not require an API key.
		if ( 'ollama' === $provider ) {
			$api_key  = '';
			$endpoint = trailingslashit( self::get_base_url() ) . 'api/chat';
			return self::call_ollama( $endpoint, $model, $system_prompt, $user_prompt, $max_tokens, $temperature );
		}

		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'AI API key is not configured. Please add your API key in the AI Settings section.', 'fpp-interlinking' ) );
		}

		$endpoint = isset( self::API_ENDPOINTS[ $provider ] ) ? self::API_ENDPOINTS[ $provider ] : self::API_ENDPOINTS['openai'];

		switch ( $provider ) {
			case 'anthropic':
				return self::call_anthropic( $endpoint, $api_key, $model, $system_prompt, $user_prompt, $max_tokens, $temperature );

			case 'gemini':
				return self::call_gemini( $endpoint, $api_key, $model, $system_prompt, $user_prompt, $max_tokens, $temperature );

			case 'mistral':
			case 'deepseek':
			case 'openai':
			default:
				return self::call_openai_compatible( $endpoint, $api_key, $model, $system_prompt, $user_prompt, $max_tokens, $temperature );
		}
	}

	/**
	 * OpenAI-compatible API call (works for OpenAI, Mistral, DeepSeek).
	 *
	 * @since 2.0.0
	 *
	 * @param string $endpoint      API endpoint URL.
	 * @param string $api_key       Plain-text API key.
	 * @param string $model         Model identifier.
	 * @param string $system_prompt System/context prompt.
	 * @param string $user_prompt   User message.
	 * @param int    $max_tokens    Maximum response tokens.
	 * @param float  $temperature   Sampling temperature (0.0–1.0).
	 * @return string|WP_Error The AI response text or WP_Error.
	 */
	private static function call_openai_compatible( $endpoint, $api_key, $model, $system_prompt, $user_prompt, $max_tokens, $temperature ) {
		$body = array(
			'model'       => $model,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown API error.', 'fpp-interlinking' );
			return new \WP_Error( 'api_error', sprintf( __( 'AI API error (%d): %s', 'fpp-interlinking' ), $code, $error_msg ) );
		}

		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			return trim( $data['choices'][0]['message']['content'] );
		}

		return new \WP_Error( 'parse_error', __( 'Could not parse AI response.', 'fpp-interlinking' ) );
	}

	/**
	 * Anthropic (Claude) API call.
	 *
	 * @since 2.0.0
	 *
	 * @param string $endpoint      API endpoint URL.
	 * @param string $api_key       Plain-text API key.
	 * @param string $model         Model identifier.
	 * @param string $system_prompt System/context prompt.
	 * @param string $user_prompt   User message.
	 * @param int    $max_tokens    Maximum response tokens.
	 * @param float  $temperature   Sampling temperature (0.0–1.0).
	 * @return string|WP_Error The AI response text or WP_Error.
	 */
	private static function call_anthropic( $endpoint, $api_key, $model, $system_prompt, $user_prompt, $max_tokens, $temperature ) {
		$body = array(
			'model'       => $model,
			'system'      => $system_prompt,
			'messages'    => array(
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown API error.', 'fpp-interlinking' );
			return new \WP_Error( 'api_error', sprintf( __( 'AI API error (%d): %s', 'fpp-interlinking' ), $code, $error_msg ) );
		}

		if ( isset( $data['content'][0]['text'] ) ) {
			return trim( $data['content'][0]['text'] );
		}

		return new \WP_Error( 'parse_error', __( 'Could not parse AI response.', 'fpp-interlinking' ) );
	}

	/**
	 * Google Gemini API call.
	 *
	 * @since 4.0.0
	 *
	 * @param string $base_endpoint Base API endpoint URL.
	 * @param string $api_key       Plain-text API key.
	 * @param string $model         Model identifier (e.g. 'gemini-2.0-flash').
	 * @param string $system_prompt System/context prompt.
	 * @param string $user_prompt   User message.
	 * @param int    $max_tokens    Maximum response tokens.
	 * @param float  $temperature   Sampling temperature (0.0–1.0).
	 * @return string|WP_Error The AI response text or WP_Error.
	 */
	private static function call_gemini( $base_endpoint, $api_key, $model, $system_prompt, $user_prompt, $max_tokens, $temperature ) {
		$endpoint = $base_endpoint . $model . ':generateContent?key=' . $api_key;

		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $system_prompt . "\n\n" . $user_prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'     => $temperature,
				'maxOutputTokens' => $max_tokens,
			),
		);

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown API error.', 'fpp-interlinking' );
			return new \WP_Error( 'api_error', sprintf( __( 'AI API error (%d): %s', 'fpp-interlinking' ), $code, $error_msg ) );
		}

		if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return trim( $data['candidates'][0]['content']['parts'][0]['text'] );
		}

		return new \WP_Error( 'parse_error', __( 'Could not parse AI response.', 'fpp-interlinking' ) );
	}

	/**
	 * Ollama API call (self-hosted, no API key required).
	 *
	 * Uses the /api/chat endpoint which accepts an OpenAI-like messages format
	 * but returns the response directly at message.content.
	 *
	 * @since 4.0.0
	 *
	 * @param string $endpoint      Full API endpoint URL.
	 * @param string $model         Model identifier (e.g. 'llama3.2').
	 * @param string $system_prompt System/context prompt.
	 * @param string $user_prompt   User message.
	 * @param int    $max_tokens    Maximum response tokens.
	 * @param float  $temperature   Sampling temperature (0.0–1.0).
	 * @return string|WP_Error The AI response text or WP_Error.
	 */
	private static function call_ollama( $endpoint, $model, $system_prompt, $user_prompt, $max_tokens, $temperature ) {
		$body = array(
			'model'    => $model,
			'messages' => array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
			'stream'   => false,
			'options'  => array(
				'temperature' => $temperature,
				'num_predict' => $max_tokens,
			),
		);

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 180,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$error_msg = isset( $data['error'] ) ? $data['error'] : __( 'Unknown API error.', 'fpp-interlinking' );
			return new \WP_Error( 'api_error', sprintf( __( 'AI API error (%d): %s', 'fpp-interlinking' ), $code, $error_msg ) );
		}

		if ( isset( $data['message']['content'] ) ) {
			return trim( $data['message']['content'] );
		}

		return new \WP_Error( 'parse_error', __( 'Could not parse AI response.', 'fpp-interlinking' ) );
	}

	/* ── Feature 1: AI Keyword Extraction ────────────────────────────── */

	/**
	 * Extract SEO keywords from a post's content using AI.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID to analyse.
	 * @return array|WP_Error Array of keyword suggestions or WP_Error.
	 */
	public static function extract_keywords( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', __( 'Post not found.', 'fpp-interlinking' ) );
		}

		$content = wp_strip_all_tags( $post->post_content );
		$title   = $post->post_title;

		// Truncate content to avoid exceeding token limits.
		$content = self::truncate_text( $content, 3000 );

		$system_prompt = 'You are an SEO expert specializing in internal linking strategies. '
			. 'Extract the most important keywords and key phrases from the given content that would '
			. 'be valuable for internal linking. Focus on: '
			. '1. Topic-specific terms and phrases (2-4 words are ideal for interlinking) '
			. '2. Product/service names '
			. '3. Industry terminology '
			. '4. Concepts that likely have dedicated pages on the site '
			. 'Return ONLY a valid JSON array of objects with "keyword" and "relevance" (1-10) fields. '
			. 'Order by relevance descending. Return 10-20 keywords maximum. '
			. 'Example: [{"keyword":"wordpress seo","relevance":9},{"keyword":"internal linking","relevance":8}]';

		$user_prompt = sprintf(
			"Title: %s\n\nContent:\n%s\n\nExtract the best keywords for internal linking from this content.",
			$title,
			$content
		);

		$response = self::call_api( $system_prompt, $user_prompt, 0.2 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::parse_json_response( $response );
	}

	/* ── Feature 2: AI Relevance Scoring ─────────────────────────────── */

	/**
	 * Score how relevant candidate posts are for a given keyword.
	 *
	 * @since 2.0.0
	 *
	 * @param string $keyword   The keyword to evaluate.
	 * @param array  $candidates Array of posts [{id, title, excerpt, url}].
	 * @return array|WP_Error Scored candidates or WP_Error.
	 */
	public static function score_relevance( $keyword, $candidates ) {
		if ( empty( $candidates ) ) {
			return array();
		}

		$candidates_text = '';
		foreach ( $candidates as $i => $c ) {
			$candidates_text .= sprintf(
				"%d. Title: %s | URL: %s | Excerpt: %s\n",
				$i + 1,
				$c['title'],
				$c['url'],
				self::truncate_text( $c['excerpt'], 200 )
			);
		}

		$system_prompt = 'You are an SEO expert. You will be given a keyword and a list of candidate pages. '
			. 'Score each candidate on how relevant it is as a link target for the keyword (1-100). '
			. 'Consider: topical match, content authority, user intent alignment, and SEO value. '
			. 'Return ONLY a valid JSON array of objects with "index" (1-based), "score" (1-100), and "reason" (short explanation) fields. '
			. 'Order by score descending. '
			. 'Example: [{"index":1,"score":92,"reason":"Directly covers the topic"},{"index":3,"score":45,"reason":"Tangentially related"}]';

		$user_prompt = sprintf(
			"Keyword: \"%s\"\n\nCandidate pages:\n%s\nScore each candidate's relevance for this keyword.",
			$keyword,
			$candidates_text
		);

		$response = self::call_api( $system_prompt, $user_prompt, 0.2 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$scores = self::parse_json_response( $response );

		if ( is_wp_error( $scores ) ) {
			return $scores;
		}

		// Map scores back to candidates with safe array access.
		$result = array();
		foreach ( $scores as $score ) {
			$idx = ( (int) ( $score['index'] ?? 0 ) ) - 1;
			if ( $idx >= 0 && isset( $candidates[ $idx ] ) ) {
				$result[] = array_merge( $candidates[ $idx ], array(
					'score'  => (int) ( $score['score'] ?? 0 ),
					'reason' => sanitize_text_field( $score['reason'] ?? '' ),
				) );
			}
		}

		return $result;
	}

	/* ── Feature 3: AI Content Gap Analysis ──────────────────────────── */

	/**
	 * Analyse published posts and find interlinking gaps using AI.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $batch_size Number of posts to analyse.
	 * @param int   $offset     Offset for pagination.
	 * @param array $post_types Post types to analyse.
	 * @return array|WP_Error Gap analysis results or WP_Error.
	 */
	public static function analyse_content_gaps( $batch_size = 20, $offset = 0, $post_types = array() ) {
		if ( empty( $post_types ) ) {
			$post_types = FPP_Interlinking_DB::get_configured_post_types();
		}
		$query = new \WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		) );

		if ( ! $query->have_posts() ) {
			return new \WP_Error( 'no_posts', __( 'No published posts found to analyse.', 'fpp-interlinking' ) );
		}

		$posts_data = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$posts_data[] = array(
				'id'      => get_the_ID(),
				'title'   => get_the_title(),
				'url'     => get_permalink(),
				'excerpt' => self::truncate_text( wp_strip_all_tags( get_the_content() ), 300 ),
			);
		}
		wp_reset_postdata();

		// Get existing keyword mappings.
		$existing = FPP_Interlinking_DB::get_all_keywords();
		$existing_text = '';
		if ( ! empty( $existing ) ) {
			foreach ( array_slice( $existing, 0, 50 ) as $kw ) {
				$existing_text .= sprintf( "- \"%s\" -> %s\n", $kw['keyword'], $kw['target_url'] );
			}
		}

		$posts_text = '';
		foreach ( $posts_data as $p ) {
			$posts_text .= sprintf(
				"ID: %d | Title: %s | URL: %s | Content preview: %s\n",
				$p['id'],
				$p['title'],
				$p['url'],
				$p['excerpt']
			);
		}

		$system_prompt = 'You are an SEO internal linking strategist. Analyse the given posts and identify '
			. 'interlinking opportunities that are currently missing. '
			. 'For each gap you find, suggest a keyword phrase that should be linked, '
			. 'the source post (where the keyword appears), and the target post (where it should link to). '
			. 'Focus on high-value connections that improve site structure and user navigation. '
			. 'Return ONLY a valid JSON array of objects with fields: '
			. '"keyword" (phrase to link), "source_id" (post ID where keyword appears), '
			. '"target_id" (post ID to link to), "target_url" (URL to link to), '
			. '"confidence" (1-100), "reason" (short explanation). '
			. 'Return up to 15 suggestions, ordered by confidence descending. '
			. 'Example: [{"keyword":"wordpress seo","source_id":42,"target_id":17,"target_url":"https://example.com/seo-guide","confidence":85,"reason":"Source discusses SEO and target is the main SEO guide"}]';

		$user_prompt = "Published content:\n" . $posts_text;
		if ( ! empty( $existing_text ) ) {
			$user_prompt .= "\nExisting keyword mappings (avoid duplicates):\n" . $existing_text;
		}
		$user_prompt .= "\n\nFind the interlinking gaps and opportunities.";

		$response = self::call_api( $system_prompt, $user_prompt, 0.3 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$gaps = self::parse_json_response( $response );

		if ( is_wp_error( $gaps ) ) {
			return $gaps;
		}

		// Enrich with post titles for display.
		foreach ( $gaps as &$gap ) {
			$source_post = get_post( $gap['source_id'] ?? 0 );
			$target_post = get_post( $gap['target_id'] ?? 0 );
			$gap['source_title'] = $source_post ? $source_post->post_title : __( 'Unknown', 'fpp-interlinking' );
			$gap['target_title'] = $target_post ? $target_post->post_title : __( 'Unknown', 'fpp-interlinking' );
		}
		unset( $gap );

		return array(
			'gaps'        => $gaps,
			'total_posts' => $query->found_posts,
			'analysed'    => count( $posts_data ),
			'offset'      => $offset,
		);
	}

	/* ── Feature 4: AI Auto-Generate Mappings ────────────────────────── */

	/**
	 * Automatically generate a complete interlinking strategy using AI.
	 *
	 * Scans a batch of posts and proposes keyword-to-URL mappings.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $batch_size Number of posts to process.
	 * @param int   $offset     Offset for batch processing.
	 * @param array $post_types Post types to process.
	 * @return array|WP_Error Proposed mappings or WP_Error.
	 */
	public static function auto_generate_mappings( $batch_size = 20, $offset = 0, $post_types = array() ) {
		if ( empty( $post_types ) ) {
			$post_types = FPP_Interlinking_DB::get_configured_post_types();
		}
		$query = new \WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		) );

		if ( ! $query->have_posts() ) {
			return new \WP_Error( 'no_posts', __( 'No published posts found.', 'fpp-interlinking' ) );
		}

		$posts_data = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$posts_data[] = array(
				'id'      => get_the_ID(),
				'title'   => get_the_title(),
				'url'     => get_permalink(),
				'excerpt' => self::truncate_text( wp_strip_all_tags( get_the_content() ), 300 ),
			);
		}
		wp_reset_postdata();

		// Get existing keywords to avoid duplicates.
		$existing     = FPP_Interlinking_DB::get_all_keywords();
		$existing_kws = array();
		foreach ( $existing as $ek ) {
			$existing_kws[] = strtolower( $ek['keyword'] );
		}

		$posts_text = '';
		foreach ( $posts_data as $p ) {
			$posts_text .= sprintf(
				"ID: %d | Title: %s | URL: %s | Preview: %s\n",
				$p['id'],
				$p['title'],
				$p['url'],
				$p['excerpt']
			);
		}

		$system_prompt = 'You are an SEO expert building an internal linking strategy for a website. '
			. 'Given a list of published posts, generate keyword-to-URL mappings that will create '
			. 'a strong internal linking structure. Each mapping defines a keyword/phrase that, '
			. 'when found in any post content, should be replaced with a link to the target URL. '
			. 'Guidelines: '
			. '1. Use natural 2-4 word phrases that commonly appear in content. '
			. '2. Each keyword should map to the most authoritative/relevant page for that topic. '
			. '3. Prefer specific phrases over generic single words. '
			. '4. Avoid keywords that are too common (e.g., "the", "click here"). '
			. '5. Consider the user intent — what would someone reading that keyword want to learn more about? '
			. 'Return ONLY a valid JSON array of objects with fields: '
			. '"keyword" (phrase to auto-link), "target_url" (URL to link to), '
			. '"target_title" (title of target page), "confidence" (1-100). '
			. 'Return up to 20 mappings, ordered by confidence descending.';

		$user_prompt = "Published content:\n" . $posts_text;
		if ( ! empty( $existing_kws ) ) {
			$user_prompt .= "\n\nAlready mapped keywords (DO NOT suggest these again): " . implode( ', ', array_slice( $existing_kws, 0, 50 ) );
		}
		$user_prompt .= "\n\nGenerate the best keyword-to-URL mappings for internal linking.";

		$response = self::call_api( $system_prompt, $user_prompt, 0.3 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$mappings = self::parse_json_response( $response );

		if ( is_wp_error( $mappings ) ) {
			return $mappings;
		}

		// Filter out any that already exist.
		$filtered = array();
		foreach ( $mappings as $m ) {
			$kw_lower = strtolower( $m['keyword'] ?? '' );
			if ( ! empty( $kw_lower ) && ! in_array( $kw_lower, $existing_kws, true ) ) {
				$filtered[] = $m;
			}
		}

		return array(
			'mappings'    => $filtered,
			'total_posts' => $query->found_posts,
			'analysed'    => count( $posts_data ),
			'offset'      => $offset,
		);
	}

	/* ── Helpers ─────────────────────────────────────────────────────── */

	/**
	 * Truncate text to a maximum number of words.
	 *
	 * @since 2.0.0
	 *
	 * @param string $text      Text to truncate.
	 * @param int    $max_words Maximum words.
	 * @return string Truncated text.
	 */
	private static function truncate_text( $text, $max_words = 500 ) {
		$words = preg_split( '/\s+/', trim( $text ) );
		if ( count( $words ) <= $max_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $words, 0, $max_words ) ) . '...';
	}

	/**
	 * Parse a JSON array from the AI response text.
	 *
	 * Handles cases where the response is wrapped in markdown code blocks.
	 * Validates that the result is a non-empty sequential array of objects
	 * and sanitises every string value to prevent stored XSS.
	 *
	 * @since 2.0.0
	 *
	 * @param string $response Raw AI response text.
	 * @return array|WP_Error Parsed array or WP_Error.
	 */
	private static function parse_json_response( $response ) {
		// Strip markdown code fences if present.
		$response = preg_replace( '/^```(?:json)?\s*/i', '', $response );
		$response = preg_replace( '/\s*```\s*$/', '', $response );
		$response = trim( $response );

		// Try to extract JSON array from the response.
		if ( preg_match( '/\[[\s\S]*\]/', $response, $matches ) ) {
			$response = $matches[0];
		}

		$decoded = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[WP Interlinking AI] JSON decode error (%s): %s',
					json_last_error_msg(),
					substr( $response, 0, 500 )
				) );
			}
			return new \WP_Error(
				'json_parse_error',
				__( 'Failed to parse AI response. Please try again.', 'fpp-interlinking' )
			);
		}

		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return new \WP_Error(
				'json_empty',
				__( 'AI returned an empty result set. Please try again.', 'fpp-interlinking' )
			);
		}

		// Ensure we have a sequential array of associative arrays.
		if ( ! isset( $decoded[0] ) || ! is_array( $decoded[0] ) ) {
			return new \WP_Error(
				'json_format_error',
				__( 'AI response format is unexpected. Please try again.', 'fpp-interlinking' )
			);
		}

		// Sanitise every string value in the decoded data to prevent stored XSS.
		$decoded = self::sanitise_parsed_data( $decoded );

		return $decoded;
	}

	/**
	 * Recursively sanitise parsed AI response data.
	 *
	 * String values are passed through sanitize_text_field(); integers and
	 * floats are cast to their respective types. URLs are sanitised with
	 * esc_url_raw(). This prevents stored XSS from AI hallucinations or
	 * prompt-injection attempts.
	 *
	 * @since 2.0.0
	 *
	 * @param array $data Parsed JSON data.
	 * @return array Sanitised data.
	 */
	private static function sanitise_parsed_data( $data ) {
		$url_keys = array( 'url', 'target_url', 'permalink' );

		foreach ( $data as &$item ) {
			if ( is_array( $item ) ) {
				foreach ( $item as $key => &$value ) {
					if ( is_string( $value ) ) {
						if ( in_array( $key, $url_keys, true ) ) {
							$value = esc_url_raw( $value );
						} else {
							$value = sanitize_text_field( $value );
						}
					} elseif ( is_int( $value ) || is_float( $value ) ) {
						// Leave numeric values as-is; they are safe.
						continue;
					} elseif ( is_array( $value ) ) {
						$value = self::sanitise_parsed_data( array( $value ) );
						$value = $value[0];
					}
				}
				unset( $value );
			}
		}
		unset( $item );

		return $data;
	}

	/**
	 * Test the API connection with a simple prompt.
	 *
	 * @since 2.0.0
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function test_connection() {
		$response = self::call_api(
			'You are a helpful assistant.',
			'Respond with exactly: {"status":"ok"}',
			0.0
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
