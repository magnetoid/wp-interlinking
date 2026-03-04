<div align="center">

# WP Interlinking

### AI-Powered SEO Internal Linking for WordPress

The smartest way to build your site's internal linking structure.<br>
TF-IDF analysis. Site health scoring. 6 AI providers. Zero manual work.

<br>

[![Version](https://img.shields.io/badge/version-5.0.0-0073aa?style=for-the-badge)](https://github.com/magnetoid/wp-interlinking/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-green?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen?style=for-the-badge)](https://github.com/magnetoid/wp-interlinking/pulls)

<br>

[Features](#-features) · [AI Providers](#-ai-providers) · [Installation](#-installation) · [Getting Started](#-getting-started) · [Changelog](#-changelog)

<br>

</div>

---

<br>

## Why WP Interlinking?

Internal links are one of the most powerful — and most neglected — SEO levers. They help search engines understand your site structure, distribute page authority, and keep visitors engaged longer.

**WP Interlinking automates the entire process:**

<table>
<tr>
<td width="25%" valign="top">

**Map Keywords to URLs**<br>
Define keyword-to-URL mappings once. The plugin finds and links them across your entire site automatically.

</td>
<td width="25%" valign="top">

**Smart SEO Analysis**<br>
TF-IDF keyword extraction, site health scoring, orphan page detection, and link distribution analysis — no AI required.

</td>
<td width="25%" valign="top">

**Let AI Do the Work**<br>
Connect any of 6 AI providers to extract keywords, score relevance, find content gaps, and generate a complete strategy.

</td>
<td width="25%" valign="top">

**Track Everything**<br>
Built-in click & impression tracking with CTR, period comparisons, Chart.js visualizations, and CSV exports.

</td>
</tr>
</table>

<br>

---

<br>

## Features

<table>
<tr>
<td width="50%" valign="top">

### Smart SEO Analysis Engine

- **TF-IDF Keyword Extraction** — Porter stemmer + inverse document frequency for statistically significant keyword discovery
- **Site Health Score** — Weighted composite score (0-100) with A-F grade across 6 SEO signals
- **Orphan Page Detection** — Find pages with zero inbound internal links that search engines can't discover
- **Link Distribution Analysis** — Per-page inbound/outbound counts with over-linked and under-linked detection
- **SEO Content Scoring** — Analyses keyword placement in headings, first paragraph, meta descriptions, and image alt text
- **Smart Recommendations** — Prioritized action items based on your site's health breakdown

</td>
<td width="50%" valign="top">

### AI-Powered Analysis

- **Keyword Extraction** — AI analyses any post and returns 10-20 SEO keywords ranked by relevance
- **Relevance Scoring** — Score posts 1-100 as link targets for any keyword with detailed explanations
- **Content Gap Analysis** — Discover posts that should link to each other but don't
- **Auto-Generate Mappings** — One-click AI scan proposes a complete interlinking strategy
- **One-Click Add** — Every AI suggestion has an instant "Add Mapping" button
- **6 Providers** — OpenAI, Anthropic, Google Gemini, Mistral AI, DeepSeek, Ollama

</td>
</tr>
<tr>
<td width="50%" valign="top">

### Deep Analytics & Tracking

- **Click Tracking** — Zero-impact `sendBeacon()` tracking, no external scripts
- **Impression Tracking** — Batched writes on shutdown hook, zero page load impact
- **CTR Calculation** — Clicks / impressions per keyword, per post
- **Period Comparisons** — Today / 7d / 30d / custom with % change badges
- **Chart.js Visualizations** — Line charts, doughnut charts, dashboard sparklines
- **CSV Export** — Top keywords, clicks by post, daily trend, top links

</td>
<td width="50%" valign="top">

### Smart Replacement Engine

- **Longest-match-first** processing prevents partial conflicts
- **Content protection** — Never modifies existing links, `<script>`, `<style>`, `<code>`, `<pre>`, headings, or HTML comments
- **Self-link prevention** — Skips when target URL matches current post
- **Per-keyword overrides** — nofollow, new-tab, max replacements
- **Transient caching** — 1-hour cache for minimal DB overhead
- **All post types** — Posts, pages, WooCommerce products, custom post types

</td>
</tr>
<tr>
<td width="50%" valign="top">

### Modern Admin Interface

- **5-Tab Layout** — Dashboard, Keywords, Analysis, Analytics, Settings
- **6 Analysis Sub-Tabs** — Extract, Score, Gaps, Generate, Orphans, Distribution
- **Health Dashboard** — CSS gauge with animated score, breakdown bars, metric cards
- **Toast Notifications** — Slide-in notifications with auto-dismiss
- **AJAX Tab Switching** — No page reloads, browser back/forward supported
- **Responsive** — Clean layouts on desktop, tablet, and mobile

</td>
<td width="50%" valign="top">

### Developer-Friendly

- **Transient Caching** — IDF index (24h), link graph (6h), replacement cache (1h)
- **Porter Stemmer** — Built-in English word stemmer with memoization
- **350+ Stop Words** — Comprehensive list including web/content boilerplate terms
- **SEO Plugin Compatibility** — Reads meta from Yoast, Rank Math, and AIOSEO
- **Bulk Operations** — Enable/disable/delete with CSV import/export
- **i18n Ready** — Full translation support with `.pot` template

</td>
</tr>
</table>

<br>

---

<br>

## AI Providers

Choose from **6 providers** — cloud APIs or fully self-hosted with Ollama. Switch anytime from the Settings tab.

<table>
<tr>
<th>Provider</th>
<th>Default Model</th>
<th>API Key</th>
<th>Available Models</th>
</tr>
<tr>
<td><strong>OpenAI</strong></td>
<td><code>gpt-4o-mini</code></td>
<td>Required</td>
<td>GPT-4o, GPT-4o-mini, GPT-4-turbo</td>
</tr>
<tr>
<td><strong>Anthropic</strong></td>
<td><code>claude-sonnet-4-20250514</code></td>
<td>Required</td>
<td>Claude Sonnet, Claude Haiku</td>
</tr>
<tr>
<td><strong>Google Gemini</strong></td>
<td><code>gemini-2.0-flash</code></td>
<td>Required</td>
<td>Gemini Pro, Gemini Flash</td>
</tr>
<tr>
<td><strong>Mistral AI</strong></td>
<td><code>mistral-small-latest</code></td>
<td>Required</td>
<td>Mistral Small, Mistral Large</td>
</tr>
<tr>
<td><strong>DeepSeek</strong></td>
<td><code>deepseek-chat</code></td>
<td>Required</td>
<td>DeepSeek Chat, DeepSeek Reasoner</td>
</tr>
<tr>
<td><strong>Ollama</strong></td>
<td><code>llama3.2</code></td>
<td>Not required</td>
<td>Llama 3.2, Mistral, Gemma 2, Qwen 2.5, any local model</td>
</tr>
</table>

> All API keys are stored with **sodium encryption** using a key derived from your WordPress `AUTH_KEY`. On servers without sodium, a base64 encoding fallback is used. Keys are never exposed in HTML or JavaScript — only a masked preview is shown.

<br>

---

<br>

## Requirements

| | Requirement | Minimum |
|---|---|---|
| WordPress | Core platform | **5.8** or higher |
| PHP | Server runtime | **7.2** or higher |
| AI features | Optional | API key from any supported provider *(not needed for Ollama)* |
| WooCommerce | Optional | Any version *(for product post type support)* |

> The Smart SEO Analysis Engine (TF-IDF, health scoring, orphan detection, link distribution) works entirely with PHP — no AI provider or API key needed.

<br>

---

<br>

## Installation

### Option 1: Clone from GitHub

```bash
git clone https://github.com/magnetoid/wp-interlinking.git wp-content/plugins/fpp-interlinking
```

### Option 2: Upload via WordPress Admin

1. Download the latest release as a ZIP
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP file and click **Install Now**

### Then:

1. Activate through **Plugins > Installed Plugins**
2. Go to **Settings > WP Interlinking**
3. You're ready to go!

<br>

---

<br>

## Getting Started

### 1. Check Your Site Health

1. Open the **Analysis** tab — the health check runs automatically
2. Review your **Site Health Score** (0-100, graded A through F)
3. Read the **Smart Recommendations** for prioritized action items
4. Use the metric cards to spot orphan pages, link gaps, and coverage issues

### 2. Configure AI *(optional but recommended)*

1. Open the **Settings** tab
2. Set the Analysis Engine to **AI-Powered**
3. Select your preferred provider from the dropdown
4. Enter your API key *(skip for Ollama)*
5. For **Ollama**: enter your server base URL (e.g., `http://localhost:11434`)
6. Click **Save All Settings**, then **Test Connection**

### 3. Add Keyword Mappings

There are three ways to add keywords:

| Method | How |
|---|---|
| **Quick Search** | Type in the search bar to find posts — click to auto-fill keyword + URL |
| **Manual** | Enter a keyword and target URL, set per-keyword overrides, click Add Keyword |
| **CSV Import** | Upload a CSV with `keyword,url` columns for bulk import |

### 4. Use the Analysis Tools

Open the **Analysis** tab and navigate between 6 sub-tabs:

| Sub-Tab | What It Does |
|---|---|
| **Extract Keywords** | TF-IDF extraction from any post — or AI-powered with your chosen provider |
| **Score Relevance** | Score your posts 1-100 as link targets for any keyword |
| **Content Gaps** | Find posts that should cross-link but don't |
| **Auto-Generate** | One-click scan proposes a complete interlinking strategy |
| **Orphan Pages** | Detect pages with zero inbound internal links |
| **Link Distribution** | Analyse inbound/outbound link counts with over-linked and under-linked detection |

### 5. Monitor with Analytics

Open the **Analytics** tab to track performance:

- **Stat cards** with total clicks, impressions, CTR, and comparison badges
- **Line chart** showing daily click trends
- **Doughnut chart** breaking down clicks by post type
- **Top Keywords** and **Top Links** tables with CTR data
- **Period selector**: Today, 7 Days, 30 Days, or custom date range
- **Export CSV** for external reporting

<br>

---

<br>

## Global Settings

| Setting | Default | Description |
|---|---|---|
| Max replacements per keyword | `1` | Times each keyword is linked per post *(per-keyword overrides available)* |
| Add `rel="nofollow"` | Off | Adds `nofollow` to all generated interlinks |
| Open in new tab | On | Adds `target="_blank"` with `noopener noreferrer` |
| Case sensitive | Off | When off, "WordPress" matches "wordpress", "WORDPRESS", etc. |
| Max links per post | `0` (unlimited) | Cap total interlinks inserted per post |
| Enabled post types | Posts, Pages | Posts, pages, products, and all registered custom post types |
| Excluded posts/pages | *—* | Comma-separated post IDs to skip entirely |

<br>

---

<br>

## Security

WP Interlinking follows WordPress security best practices at every layer:

| Layer | Implementation |
|---|---|
| **CSRF Protection** | Nonce verification on every AJAX request |
| **Access Control** | All admin actions require `manage_options` capability |
| **Input Sanitization** | `sanitize_text_field()`, `esc_url_raw()`, `absint()` on all inputs |
| **Output Escaping** | `esc_html()`, `esc_url()`, `esc_attr()` at the point of output |
| **SQL Injection** | `$wpdb->prepare()` on every query with user input |
| **API Key Encryption** | Sodium encryption with `AUTH_KEY`-derived key |
| **Data Privacy** | All analytics data stays in your WordPress database — no external calls |

<br>

---

<br>

## File Structure

```
fpp-interlinking/
├── fpp-interlinking.php                          Main plugin bootstrap (v5.0.0)
├── uninstall.php                                 Multisite-safe cleanup on delete
├── readme.txt                                    WordPress.org readme
│
├── includes/
│   ├── class-fpp-interlinking-activator.php      DB tables + default options
│   ├── class-fpp-interlinking-deactivator.php    Cache cleanup on deactivation
│   ├── class-fpp-interlinking-db.php             CRUD + duplicate detection
│   ├── class-fpp-interlinking-ai.php             6-provider AI integration
│   ├── class-fpp-interlinking-analyzer.php       TF-IDF, stemmer, health score, link graph
│   ├── class-fpp-interlinking-analytics.php      Click / impression / CTR engine
│   ├── class-fpp-interlinking-admin.php          Admin UI + 34 AJAX endpoints
│   └── class-fpp-interlinking-replacer.php       Front-end filter + impressions
│
├── assets/
│   ├── js/
│   │   ├── fpp-interlinking-admin.js             Admin UI (60+ methods)
│   │   ├── fpp-interlinking-tracker.js            Front-end click tracker
│   │   └── chart.min.js                          Chart.js v4.4.7 (bundled)
│   └── css/
│       └── fpp-interlinking-admin.css            Health gauge, sub-tabs, toasts, responsive
│
└── languages/
    └── fpp-interlinking.pot                      Translation template (i18n ready)
```

<br>

---

<br>

## Changelog

<details open>
<summary><strong>5.0.0</strong> — Smart SEO Analysis Engine & UI Overhaul</summary>

<br>

**Analysis Engine**
- **TF-IDF keyword extraction** — Porter stemmer + inverse document frequency replaces raw term frequency
- **Site Health Score** — weighted composite (0-100) with A-F grade across 6 signals: orphan ratio, avg links/post, keyword coverage, link distribution evenness, active keyword ratio, content structure
- **Orphan page detection** — finds pages with zero inbound internal links
- **Link distribution analysis** — per-page inbound/outbound counts, identifies over-linked and under-linked pages
- **SEO content scoring** — analyses keyword placement in headings, first paragraph, meta descriptions (Yoast, Rank Math, AIOSEO), and image alt text
- **Internal link graph** — builds full site link map with URL normalization, cached as 6-hour transient
- **Porter stemmer** — English word root reduction with memoization cache
- **350+ stop words** — expanded from ~200 with web/content boilerplate terms
- **IDF index caching** — document-frequency map cached as 24-hour transient

**Improved Existing Analysis**
- `score_relevance()` — new signals: heading match (+15), category/tag match (+10), stemmed word matching
- `analyse_content_gaps()` — stemmed inverted index, configurable max results (default 25)
- `auto_generate_mappings()` — raised threshold to 55, extracts 8 keywords/post, max 30 proposals

**Admin UI**
- **Health dashboard** — CSS-only circular gauge with animated score, grade badge, breakdown bars with fill animation
- **6 analysis sub-tabs** — Extract, Score, Gaps, Generate, Orphans, Distribution with smooth switching
- **Metric cards** — 4-column grid showing orphan pages, avg links, coverage %, keyword utilization
- **Smart recommendations** — prioritized action items (high/medium/low) based on health breakdown
- **Toast notifications** — fixed-position slide-in toasts replace inline WordPress notices
- **Button spinners** — reusable loading state for all async operations
- **Tooltips** — dark background with arrow pointer on hover

**Under the Hood**
- 4 new AJAX endpoints: health check, orphan detection, link distribution, SEO content analysis
- Analysis transient cache invalidation when post type settings change
- Number formatting with locale-aware comma separators
- Responsive health dashboard — stacks on tablet and mobile

</details>

<details>
<summary><strong>4.0.0</strong> — Multi-Provider AI, Deep Analytics, Modern UI</summary>

<br>

- 4 new AI providers: **Google Gemini**, **Mistral AI**, **DeepSeek**, **Ollama** (self-hosted)
- Configurable base URL for Ollama with optional authentication
- **Impression tracking** — records every keyword impression per post per day
- **CTR calculation** — clicks / impressions displayed per keyword
- **Period comparison** — percentage-change badges (current vs prior period)
- **Custom date range** picker for analytics filtering
- **Chart.js integration** — line chart for daily trends, doughnut for post type breakdown
- **Sparkline mini-charts** on dashboard stat cards (7-day trend)
- **Top Links** table in analytics (keyword + URL pairs by clicks)
- **Analytics CSV export** (top keywords, clicks by post, daily trend, top links)
- **AJAX tab switching** with `pushState` — browser back/forward supported
- **Skeleton loaders**, **sortable tables**, **progress bars**, **empty states**
- **Color-coded stat cards** with dashicons and hover effects
- Dynamic provider registry, provider-specific UI toggling
- Impressions table with compound unique index

</details>

<details>
<summary><strong>3.0.0</strong> — Internal Analysis Engine, Tabbed UI, Click Analytics</summary>

<br>

- Internal PHP analysis engine (no AI required) — orphan pages, link distribution, opportunities
- 5-tab admin interface: Dashboard, Keywords, Analysis, Analytics, Settings
- Click tracking with `navigator.sendBeacon()`
- Analytics dashboard with stat cards, daily trend chart, top keywords table
- Period filtering (today, 7 days, 30 days)
- Clicks database table with post type tracking
- Automatic data purge for old analytics (configurable retention)
- Rate limiting for click tracking endpoint

</details>

<details>
<summary><strong>2.1.0</strong> — Performance, Bulk Operations, CSV, Post Type Filtering</summary>

<br>

- CSV import/export for keyword mappings
- Bulk enable/disable/delete operations
- Post type filtering setting
- Max links per post setting
- Heading tag exclusion option
- Pagination for keyword table
- Performance optimizations for large keyword sets

</details>

<details>
<summary><strong>2.0.0</strong> — AI-Powered Interlinking</summary>

<br>

- AI Keyword Extraction — analyse post content to extract SEO keywords
- AI Relevance Scoring — score posts as link targets (1-100)
- AI Content Gap Analysis — discover posts that should link to each other
- AI Auto-Generate Mappings — one-click complete interlinking strategy
- Multi-provider support: OpenAI and Anthropic (Claude)
- Encrypted API key storage (sodium + AUTH_KEY derivation)
- AI Settings panel with provider, model, API key, and max tokens config
- Test Connection, one-click Add Mapping, bulk Add All Mappings
- Score badges with color-coded confidence levels

</details>

<details>
<summary><strong>1.2.0</strong> — Content Discovery Tools</summary>

<br>

- Quick-Add Post Search with live autocomplete
- Scan per Keyword — find matching posts and assign URL in one click
- Suggest Keywords from Content — paginated title scanner
- Already-mapped detection in suggestions
- Highlight animation on form pre-fill

</details>

<details>
<summary><strong>1.1.0</strong> — Stability & Polish</summary>

<br>

- Self-link prevention, duplicate keyword detection
- Max replacements validation (capped at 100)
- Error logging when `WP_DEBUG` enabled
- Settings link on Plugins page
- Multisite-safe uninstall
- Full i18n support, optimized option autoloading
- REST API and AJAX request skipping

</details>

<details>
<summary><strong>1.0.0</strong> — Initial Release</summary>

<br>

- Core keyword-to-URL mapping with automatic replacement
- Global and per-keyword settings for nofollow, new tab, max replacements
- Smart content protection (existing links, code blocks, scripts)
- AJAX admin interface

</details>

<br>

---

<br>

<div align="center">

## Credits

<br>

**Developed by [Marko Tiosavljevic](https://github.com/magnetoid)**

Supported by **[Imba Marketing](https://imbamarketing.com)**

<br>

---

<br>

**GPL-2.0-or-later** · [View License](https://www.gnu.org/licenses/gpl-2.0.html)

<br>

<sub>Built with care for the WordPress community.</sub>

</div>
