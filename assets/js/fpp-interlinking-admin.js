(function($) {
	'use strict';

	var FPP = {

		suggestionsPage: 1,
		keywordsPage: 1,
		keywordsSearch: '',
		analyticsPeriod: '30d',
		analyticsStartDate: '',
		analyticsEndDate: '',
		trendChart: null,
		postTypeChart: null,

		init: function() {
			this.bindEvents();
			this.initCurrentTab();
		},

		initCurrentTab: function() {
			var tab = this.getCurrentTab();
			if (tab === 'keywords') {
				this.loadKeywordsTable(1);
			} else if (tab === 'dashboard') {
				this.loadDashboard();
			} else if (tab === 'analytics') {
				this.loadAnalytics('30d');
			}
		},

		getCurrentTab: function() {
			var match = window.location.search.match(/[?&]tab=([^&]+)/);
			return match ? match[1] : 'dashboard';
		},

		bindEvents: function() {
			// Keyword CRUD.
			$('#fpp-add-keyword').on('click', this.addKeyword);
			$('#fpp-update-keyword').on('click', this.updateKeyword);
			$('#fpp-cancel-edit').on('click', this.cancelEdit);
			$(document).on('click', '.fpp-edit-keyword', this.editKeyword);
			$(document).on('click', '.fpp-delete-keyword', this.deleteKeyword);
			$(document).on('click', '.fpp-toggle-keyword', this.toggleKeyword);

			// Quick-Add Post Search.
			$('#fpp-post-search').on('input', FPP.createDebounce(FPP.searchPosts, 300));
			$(document).on('click', '.fpp-search-result-item', this.selectSearchResult);
			$(document).on('click', this.dismissSearchDropdown);

			// Scan per keyword row.
			$(document).on('click', '.fpp-scan-keyword', this.scanKeyword);
			$(document).on('click', '.fpp-use-url', this.useScannedUrl);
			$(document).on('click', '.fpp-close-scan', this.closeScanResults);

			// Suggest Keywords.
			$('#fpp-toggle-suggestions').on('click', this.toggleSuggestions);
			$('#fpp-scan-titles').on('click', this.scanTitles);
			$('#fpp-suggestions-prev').on('click', function() { FPP.loadSuggestionsPage(FPP.suggestionsPage - 1); });
			$('#fpp-suggestions-next').on('click', function() { FPP.loadSuggestionsPage(FPP.suggestionsPage + 1); });
			$(document).on('click', '.fpp-add-suggestion', this.addSuggestion);

			// Analysis section toggles.
			$('#fpp-toggle-ai-extract').on('click', function() { FPP.toggleSection($(this), '#fpp-ai-extract-content'); });
			$('#fpp-toggle-ai-score').on('click', function() { FPP.toggleSection($(this), '#fpp-ai-score-content'); });
			$('#fpp-toggle-ai-gaps').on('click', function() { FPP.toggleSection($(this), '#fpp-ai-gaps-content'); });
			$('#fpp-toggle-ai-generate').on('click', function() { FPP.toggleSection($(this), '#fpp-ai-generate-content'); });

			// Analysis Tools (use dispatcher endpoints).
			$('#fpp-ai-extract-search').on('input', FPP.createDebounce(FPP.aiExtractSearch, 300));
			$(document).on('click', '.fpp-ai-extract-result-item', FPP.aiExtractSelectPost);
			$('#fpp-ai-extract-btn').on('click', FPP.analyzeExtract);
			$('#fpp-ai-score-btn').on('click', FPP.analyzeScore);
			$('#fpp-ai-gaps-btn').on('click', FPP.analyzeGaps);
			$('#fpp-ai-generate-btn').on('click', FPP.analyzeGenerate);
			$('#fpp-ai-add-all-btn').on('click', FPP.aiAddAllMappings);
			$(document).on('click', '.fpp-ai-add-mapping', FPP.aiAddMapping);

			// Settings: engine toggle.
			$('input[name="fpp_analysis_engine"]').on('change', function() {
				$('#fpp-ai-config').toggle($(this).val() === 'ai');
			});

			// Settings: save all.
			$('#fpp-save-all-settings').on('click', FPP.saveAllSettings);
			$('#fpp-save-settings').on('click', FPP.saveAllSettings);

			// AI specific.
			$('#fpp-test-ai-connection').on('click', FPP.testAiConnection);
			$('#fpp-ai-provider').on('change', FPP.onProviderChange);

			// Keywords table: search, pagination, bulk ops, import/export.
			$('#fpp-keyword-search').on('input', FPP.createDebounce(function() {
				FPP.keywordsSearch = $.trim($('#fpp-keyword-search').val());
				FPP.loadKeywordsTable(1);
			}, 400));
			$('#fpp-keywords-prev').on('click', function() { FPP.loadKeywordsTable(FPP.keywordsPage - 1); });
			$('#fpp-keywords-next').on('click', function() { FPP.loadKeywordsTable(FPP.keywordsPage + 1); });
			$('#fpp-select-all').on('change', FPP.toggleSelectAll);
			$('#fpp-bulk-apply').on('click', FPP.applyBulkAction);
			$('#fpp-export-csv').on('click', FPP.exportCsv);
			$('#fpp-import-csv-file').on('change', FPP.importCsv);

			// Analytics period.
			$(document).on('click', '.fpp-period-btn', function() {
				var period = $(this).data('period');
				$('.fpp-period-btn').removeClass('active');
				$(this).addClass('active');
				if (period === 'custom') {
					$('#fpp-custom-date-range').show();
				} else {
					$('#fpp-custom-date-range').hide();
					FPP.analyticsStartDate = '';
					FPP.analyticsEndDate = '';
					FPP.loadAnalytics(period);
				}
			});

			// Custom date range apply.
			$(document).on('click', '#fpp-apply-custom-date', function() {
				FPP.analyticsStartDate = $('#fpp-date-start').val();
				FPP.analyticsEndDate = $('#fpp-date-end').val();
				if (FPP.analyticsStartDate && FPP.analyticsEndDate) {
					FPP.loadAnalytics('custom');
				}
			});

			// Analytics CSV export.
			$(document).on('click', '#fpp-export-analytics-csv', FPP.exportAnalyticsCsv);

			// AJAX tab switching.
			$(document).on('click', '.fpp-nav-tabs .nav-tab', function(e) {
				e.preventDefault();
				var tab = $(this).data('tab');
				FPP.switchTab(tab);
			});

			// Handle browser back/forward.
			$(window).on('popstate', function() {
				var tab = FPP.getCurrentTab();
				FPP.switchTab(tab, true);
			});

			// Keyboard support for section toggles.
			$(document).on('keydown', '.fpp-section-toggle[role="button"]', function(e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					$(this).trigger('click');
				}
			});
		},

		/* ── Utility ──────────────────────────────────────────────────── */

		createDebounce: function(func, delay) {
			var timer = null;
			return function() {
				var context = this;
				var args = arguments;
				clearTimeout(timer);
				timer = setTimeout(function() {
					func.apply(context, args);
				}, delay);
			};
		},

		toggleSection: function($toggle, contentSelector) {
			var $content = $(contentSelector);
			var isExpanded = $toggle.attr('aria-expanded') === 'true';
			$content.slideToggle(200);
			$toggle.attr('aria-expanded', !isExpanded);
			$toggle.find('.dashicons-arrow-down-alt2, .dashicons-arrow-up-alt2')
				.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
		},

		/* ── Dashboard ───────────────────────────────────────────────── */

		loadDashboard: function() {
			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_load_dashboard',
				nonce:  fppInterlinking.nonce
			}, function(response) {
				if (response.success) {
					FPP.renderDashboard(response.data);
				}
			});
		},

		renderDashboard: function(data) {
			var cov = data.coverage || {};
			var sum = data.summary || {};

			$('#fpp-dash-total-keywords').text(cov.total_keywords || 0);
			$('#fpp-dash-active-keywords').text(cov.active_keywords || 0);
			$('#fpp-dash-clicks-30d').text(sum.total_clicks || 0);
			$('#fpp-dash-coverage').text((cov.coverage_percent || 0) + '%');

			// 7-day sparkline for clicks.
			var trend = data.trend_7d || [];
			if (trend.length > 0) {
				FPP.renderSparkline('fpp-spark-clicks', trend.map(function(d) { return d.clicks; }));
			}

			// Recent clicks table.
			var $container = $('#fpp-dashboard-recent-table');
			var recent = data.recent || [];

			if (recent.length === 0) {
				$container.html(
					'<div class="fpp-empty-state fpp-empty-state-small">'
					+ '<span class="dashicons dashicons-chart-line fpp-empty-icon" aria-hidden="true"></span>'
					+ '<p>' + FPP.escHtml(fppInterlinking.i18n.no_recent_empty) + '</p>'
					+ '</div>'
				);
				return;
			}

			var html = '<table class="wp-list-table widefat fixed striped fpp-sortable">'
				+ '<thead><tr>'
				+ '<th>' + FPP.escHtml(fppInterlinking.i18n.keyword) + '</th>'
				+ '<th>' + FPP.escHtml(fppInterlinking.i18n.url) + '</th>'
				+ '<th>' + FPP.escHtml(fppInterlinking.i18n.date) + '</th>'
				+ '</tr></thead><tbody>';

			$.each(recent, function(i, click) {
				html += '<tr>'
					+ '<td>' + FPP.escHtml(click.keyword || '—') + '</td>'
					+ '<td><a href="' + FPP.escAttr(click.target_url || '') + '" target="_blank" rel="noopener noreferrer">' + FPP.escHtml(click.target_url || '') + '</a></td>'
					+ '<td>' + FPP.escHtml(click.clicked_at || '') + '</td>'
					+ '</tr>';
			});

			html += '</tbody></table>';
			$container.html(html);
			FPP.makeSortable($container.find('table'));
		},

		/* ── Analytics ───────────────────────────────────────────────── */

		loadAnalytics: function(period) {
			FPP.analyticsPeriod = period;

			$('#fpp-analytics-top-keywords, #fpp-analytics-clicks-by-post, #fpp-analytics-top-links').html(
				'<div class="fpp-skeleton fpp-skeleton-table"></div>'
			);

			var postData = {
				action: 'fpp_interlinking_load_analytics',
				nonce:  fppInterlinking.nonce,
				period: period
			};

			if (period === 'custom') {
				postData.start_date = FPP.analyticsStartDate;
				postData.end_date   = FPP.analyticsEndDate;
			}

			$.post(fppInterlinking.ajax_url, postData, function(response) {
				if (response.success) {
					FPP.renderAnalytics(response.data);
				}
			});
		},

		renderAnalytics: function(data) {
			var sum = data.summary || {};
			var cmp = data.comparison || {};

			$('#fpp-analytics-total-clicks').text(sum.total_clicks || 0);
			$('#fpp-analytics-impressions').text(sum.total_impressions || 0);
			$('#fpp-analytics-ctr').text((sum.ctr || 0) + '%');
			$('#fpp-analytics-top-keyword').text(sum.top_keyword || '—');

			// Comparison badges.
			FPP.renderComparisonBadge('#fpp-cmp-clicks', cmp.clicks_change);
			FPP.renderComparisonBadge('#fpp-cmp-impressions', cmp.impr_change);
			FPP.renderComparisonBadge('#fpp-cmp-ctr', cmp.ctr_change, true);

			// Show/hide empty state.
			var hasData = (sum.total_clicks || 0) > 0 || (sum.total_impressions || 0) > 0;
			$('#fpp-analytics-empty').toggle(!hasData);
			$('#fpp-analytics-trend, #fpp-analytics-tables, #fpp-analytics-extra').toggle(hasData);

			if (!hasData) return;

			// Click trend chart (Chart.js).
			FPP.renderTrendChart(data.daily_trend || []);

			// Post type doughnut chart.
			FPP.renderPostTypeChart(data.post_type_stats || []);

			// Top keywords table with CTR.
			FPP.renderAnalyticsTable(
				'#fpp-analytics-top-keywords',
				[fppInterlinking.i18n.keyword, fppInterlinking.i18n.clicks, fppInterlinking.i18n.impressions, fppInterlinking.i18n.ctr],
				(data.top_keywords || []).map(function(kw) {
					return [kw.keyword || '—', kw.clicks || 0, kw.impressions || 0, (kw.ctr || 0) + '%'];
				})
			);

			// Clicks by post table.
			FPP.renderAnalyticsTable(
				'#fpp-analytics-clicks-by-post',
				[fppInterlinking.i18n.post, fppInterlinking.i18n.clicks],
				(data.clicks_by_post || []).map(function(p) {
					return [p.post_title || '—', p.clicks || 0];
				})
			);

			// Top links table.
			FPP.renderAnalyticsTable(
				'#fpp-analytics-top-links',
				[fppInterlinking.i18n.keyword, fppInterlinking.i18n.url, fppInterlinking.i18n.clicks],
				(data.top_links || []).map(function(l) {
					return [l.keyword || '—', l.target_url || '—', l.clicks || 0];
				})
			);
		},

		renderTrendChart: function(trend) {
			var canvas = document.getElementById('fpp-trend-chart');
			if (!canvas || typeof Chart === 'undefined') return;

			if (FPP.trendChart) {
				FPP.trendChart.destroy();
				FPP.trendChart = null;
			}

			if (!trend || trend.length === 0) return;

			var labels = trend.map(function(d) { return d.date ? d.date.substring(5) : ''; });
			var values = trend.map(function(d) { return d.clicks || 0; });

			FPP.trendChart = new Chart(canvas.getContext('2d'), {
				type: 'line',
				data: {
					labels: labels,
					datasets: [{
						label: fppInterlinking.i18n.clicks || 'Clicks',
						data: values,
						borderColor: '#2271b1',
						backgroundColor: 'rgba(34, 113, 177, 0.08)',
						fill: true,
						tension: 0.3,
						pointRadius: trend.length > 60 ? 0 : 3,
						pointHoverRadius: 5,
						borderWidth: 2
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { display: false },
						tooltip: { mode: 'index', intersect: false }
					},
					scales: {
						y: { beginAtZero: true, ticks: { precision: 0 } },
						x: { ticks: { maxTicksLimit: 15 } }
					}
				}
			});
		},

		renderPostTypeChart: function(stats) {
			var canvas = document.getElementById('fpp-post-type-chart');
			if (!canvas || typeof Chart === 'undefined') return;

			if (FPP.postTypeChart) {
				FPP.postTypeChart.destroy();
				FPP.postTypeChart = null;
			}

			if (!stats || stats.length === 0) return;

			var labels = stats.map(function(s) { return s.post_type || '—'; });
			var values = stats.map(function(s) { return s.clicks || 0; });
			var colors = ['#2271b1', '#00a32a', '#dba617', '#d63638', '#9b59b6', '#3498db', '#e67e22', '#1abc9c'];

			FPP.postTypeChart = new Chart(canvas.getContext('2d'), {
				type: 'doughnut',
				data: {
					labels: labels,
					datasets: [{
						data: values,
						backgroundColor: colors.slice(0, labels.length),
						borderWidth: 2,
						borderColor: '#fff'
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { position: 'bottom', labels: { padding: 16 } }
					}
				}
			});
		},

		renderComparisonBadge: function(selector, change, isCtr) {
			var $badge = $(selector);
			if (typeof change === 'undefined' || change === null || change === 0) {
				$badge.html('').hide();
				return;
			}
			var isPositive = change > 0;
			var sign = isPositive ? '+' : '';
			var suffix = isCtr ? 'pp' : '%';
			var cls = isPositive ? 'fpp-badge-up' : 'fpp-badge-down';
			$badge.html('<span class="' + cls + '">' + sign + change + suffix + '</span>').show();
		},

		renderAnalyticsTable: function(selector, headers, rows) {
			var $container = $(selector);

			if (rows.length === 0) {
				$container.html('<p class="description">' + FPP.escHtml(fppInterlinking.i18n.no_data) + '</p>');
				return;
			}

			var html = '<table class="wp-list-table widefat fixed striped fpp-sortable"><thead><tr>';
			$.each(headers, function(i, h) {
				html += '<th>' + FPP.escHtml(h) + '</th>';
			});
			html += '</tr></thead><tbody>';

			$.each(rows, function(i, row) {
				html += '<tr>';
				$.each(row, function(j, cell) {
					html += '<td>' + FPP.escHtml(String(cell)) + '</td>';
				});
				html += '</tr>';
			});

			html += '</tbody></table>';
			$container.html(html);
			FPP.makeSortable($container.find('table'));
		},

		/* ── Settings ────────────────────────────────────────────────── */

		saveAllSettings: function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).text(fppInterlinking.i18n.saving);

			var maxVal = parseInt($('#fpp-global-max-replacements').val(), 10) || 1;
			var cap    = parseInt(fppInterlinking.max_replacements_cap, 10) || 100;
			if (maxVal < 1) maxVal = 1;
			if (maxVal > cap) maxVal = cap;
			$('#fpp-global-max-replacements').val(maxVal);

			var postTypes = [];
			$('.fpp-post-type-checkbox:checked').each(function() {
				postTypes.push($(this).val());
			});

			var data = {
				action:             'fpp_interlinking_save_settings',
				nonce:              fppInterlinking.nonce,
				max_replacements:   maxVal,
				nofollow:           $('#fpp-global-nofollow').is(':checked') ? 1 : 0,
				new_tab:            $('#fpp-global-new-tab').is(':checked') ? 1 : 0,
				case_sensitive:     $('#fpp-global-case-sensitive').is(':checked') ? 1 : 0,
				excluded_posts:     $('#fpp-global-excluded-posts').val(),
				max_links_per_post: $('#fpp-global-max-links-per-post').val() || 0,
				post_types:         postTypes.join(','),
				analysis_engine:    $('input[name="fpp_analysis_engine"]:checked').val() || 'internal',
				enable_tracking:    $('#fpp-enable-tracking').is(':checked') ? 1 : 0,
				retention_days:     $('#fpp-retention-days').val() || 90
			};

			// AI settings if visible.
			if ($('#fpp-ai-config').is(':visible')) {
				data.ai_provider   = $('#fpp-ai-provider').val();
				data.ai_api_key    = $('#fpp-ai-api-key').val();
				data.ai_model      = $('#fpp-ai-model').val();
				data.ai_max_tokens = $('#fpp-ai-max-tokens').val();
				data.ai_base_url   = $('#fpp-ai-base-url').val();
			}

			$.post(fppInterlinking.ajax_url, data, function(response) {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.save_all_settings || fppInterlinking.i18n.save_settings);
				if (response.success) {
					FPP.showNotice('success', response.data.message);
					// Update engine badge if on analysis tab.
					var eng = data.analysis_engine;
					fppInterlinking.analysis_engine = eng;
					$('#fpp-engine-badge')
						.text(eng === 'ai' ? fppInterlinking.i18n.ai_engine : fppInterlinking.i18n.internal_engine)
						.removeClass('fpp-engine-internal fpp-engine-ai')
						.addClass(eng === 'ai' ? 'fpp-engine-ai' : 'fpp-engine-internal');
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.save_all_settings || fppInterlinking.i18n.save_settings);
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		/* ── AJAX Keywords Table (Paginated) ─────────────────────────── */

		loadKeywordsTable: function(page) {
			var $tbody = $('#fpp-keywords-tbody');
			$tbody.html('<tr><td colspan="8"><span class="spinner is-active" style="float:none;"></span> ' + FPP.escHtml(fppInterlinking.i18n.loading) + '</td></tr>');

			$.post(fppInterlinking.ajax_url, {
				action:  'fpp_interlinking_load_keywords',
				nonce:   fppInterlinking.nonce,
				page:    page,
				search:  FPP.keywordsSearch
			}, function(response) {
				if (response.success) {
					FPP.keywordsPage = response.data.page;
					FPP.renderKeywordsTable(response.data);
				} else {
					$tbody.html('<tr><td colspan="8">' + FPP.escHtml(response.data.message) + '</td></tr>');
				}
			}).fail(function() {
				$tbody.html('<tr><td colspan="8">' + FPP.escHtml(fppInterlinking.i18n.request_failed) + '</td></tr>');
			});
		},

		renderKeywordsTable: function(data) {
			var $tbody = $('#fpp-keywords-tbody');
			$tbody.empty();
			$('#fpp-select-all').prop('checked', false);

			if (data.keywords.length === 0) {
				$('#fpp-keywords-table').hide();
				$('#fpp-no-keywords').show();
				$('#fpp-keywords-pagination').hide();
				return;
			}

			$('#fpp-keywords-table').show();
			$('#fpp-no-keywords').hide();

			$.each(data.keywords, function(i, kw) {
				var row = '<tr id="fpp-keyword-row-' + kw.id + '">'
					+ '<td class="column-cb check-column"><input type="checkbox" class="fpp-kw-check" value="' + kw.id + '" /></td>'
					+ '<td class="column-keyword">' + FPP.escHtml(kw.keyword) + '</td>'
					+ '<td class="column-url"><a href="' + FPP.escAttr(kw.target_url) + '" target="_blank" rel="noopener noreferrer">' + FPP.escHtml(kw.target_url) + '</a></td>'
					+ '<td class="column-nofollow">' + (parseInt(kw.nofollow) ? 'Yes' : 'No') + '</td>'
					+ '<td class="column-newtab">' + (parseInt(kw.new_tab) ? 'Yes' : 'No') + '</td>'
					+ '<td class="column-max">' + (parseInt(kw.max_replacements) ? FPP.escHtml(String(kw.max_replacements)) : 'Global') + '</td>'
					+ '<td class="column-active"><span class="' + (parseInt(kw.is_active) ? 'fpp-badge-active' : 'fpp-badge-inactive') + '">'
					+ FPP.escHtml(parseInt(kw.is_active) ? fppInterlinking.i18n.active : fppInterlinking.i18n.inactive) + '</span></td>'
					+ '<td class="column-actions">'
					+ '<button type="button" class="button button-small fpp-edit-keyword"'
					+ ' data-id="' + kw.id + '"'
					+ ' data-keyword="' + FPP.escAttr(kw.keyword) + '"'
					+ ' data-url="' + FPP.escAttr(kw.target_url) + '"'
					+ ' data-nofollow="' + kw.nofollow + '"'
					+ ' data-newtab="' + kw.new_tab + '"'
					+ ' data-max="' + kw.max_replacements + '">Edit</button> '
					+ '<button type="button" class="button button-small fpp-scan-keyword"'
					+ ' data-id="' + kw.id + '"'
					+ ' data-keyword="' + FPP.escAttr(kw.keyword) + '">Scan</button> '
					+ '<button type="button" class="button button-small fpp-toggle-keyword"'
					+ ' data-id="' + kw.id + '" data-active="' + kw.is_active + '">'
					+ FPP.escHtml(parseInt(kw.is_active) ? fppInterlinking.i18n.disable : fppInterlinking.i18n.enable) + '</button> '
					+ '<button type="button" class="button button-small fpp-delete-keyword"'
					+ ' data-id="' + kw.id + '">Delete</button>'
					+ '</td></tr>';

				var scanRow = '<tr id="fpp-scan-results-row-' + kw.id + '" class="fpp-scan-results-row" style="display:none;">'
					+ '<td colspan="8"><div class="fpp-scan-results-container">'
					+ '<p class="fpp-scan-results-loading" style="display:none;"><span class="spinner is-active"></span> ' + FPP.escHtml(fppInterlinking.i18n.scanning) + '</p>'
					+ '<div class="fpp-scan-results-list"></div>'
					+ '</div></td></tr>';

				$tbody.append(row).append(scanRow);
			});

			if (data.pages > 1) {
				var startItem = (data.page - 1) * 20 + 1;
				var endItem = Math.min(data.page * 20, data.total);
				var info = fppInterlinking.i18n.keyword_page_info
					.replace('%1$d', startItem)
					.replace('%2$d', endItem)
					.replace('%3$d', data.total);
				$('.fpp-keywords-info').text(info);
				$('#fpp-keywords-prev').prop('disabled', data.page <= 1);
				$('#fpp-keywords-next').prop('disabled', data.page >= data.pages);
				$('#fpp-keywords-pagination').show();
			} else {
				$('#fpp-keywords-pagination').hide();
			}
		},

		/* ── Bulk Operations ──────────────────────────────────────────── */

		toggleSelectAll: function() {
			var checked = $(this).is(':checked');
			$('.fpp-kw-check').prop('checked', checked);
		},

		applyBulkAction: function(e) {
			e.preventDefault();
			var action = $('#fpp-bulk-action').val();
			if (!action) {
				FPP.showNotice('error', fppInterlinking.i18n.bulk_select_action);
				return;
			}

			var ids = [];
			$('.fpp-kw-check:checked').each(function() {
				ids.push($(this).val());
			});

			if (ids.length === 0) {
				FPP.showNotice('error', fppInterlinking.i18n.bulk_select_items);
				return;
			}

			if (action === 'delete' && !confirm(fppInterlinking.i18n.bulk_confirm_delete)) {
				return;
			}

			$.post(fppInterlinking.ajax_url, {
				action:      'fpp_interlinking_bulk_action',
				nonce:       fppInterlinking.nonce,
				bulk_action: action,
				ids:         ids
			}, function(response) {
				if (response.success) {
					FPP.showNotice('success', response.data.message);
					FPP.loadKeywordsTable(FPP.keywordsPage);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		/* ── CSV Import / Export ──────────────────────────────────────── */

		exportCsv: function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true);

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_export_csv',
				nonce:  fppInterlinking.nonce
			}, function(response) {
				$btn.prop('disabled', false);
				if (response.success) {
					var blob = new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' });
					var link = document.createElement('a');
					link.href = URL.createObjectURL(blob);
					link.download = response.data.filename;
					link.click();
					URL.revokeObjectURL(link.href);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false);
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		importCsv: function() {
			var file = this.files[0];
			if (!file) return;

			var reader = new FileReader();
			reader.onload = function(e) {
				$.post(fppInterlinking.ajax_url, {
					action:   'fpp_interlinking_import_csv',
					nonce:    fppInterlinking.nonce,
					csv_data: e.target.result
				}, function(response) {
					if (response.success) {
						FPP.showNotice('success', response.data.message);
						FPP.loadKeywordsTable(1);
					} else {
						FPP.showNotice('error', response.data.message);
					}
				}).fail(function() {
					FPP.showNotice('error', fppInterlinking.i18n.request_failed);
				});
			};
			reader.readAsText(file);
			$(this).val('');
		},

		/* ── Keyword CRUD ─────────────────────────────────────────────── */

		addKeyword: function(e) {
			e.preventDefault();
			var keyword   = $.trim($('#fpp-keyword').val());
			var targetUrl = $.trim($('#fpp-target-url').val());

			if (!keyword || !targetUrl) {
				FPP.showNotice('error', fppInterlinking.i18n.required);
				return;
			}

			if (!/^https?:\/\/.+/i.test(targetUrl)) {
				FPP.showNotice('error', fppInterlinking.i18n.invalid_url);
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(fppInterlinking.i18n.adding);

			var perMax = parseInt($('#fpp-per-max-replacements').val(), 10) || 0;
			var cap    = parseInt(fppInterlinking.max_replacements_cap, 10) || 100;
			if (perMax > cap) perMax = cap;

			$.post(fppInterlinking.ajax_url, {
				action:           'fpp_interlinking_add_keyword',
				nonce:            fppInterlinking.nonce,
				keyword:          keyword,
				target_url:       targetUrl,
				nofollow:         $('#fpp-per-nofollow').is(':checked') ? 1 : 0,
				new_tab:          $('#fpp-per-new-tab').is(':checked') ? 1 : 0,
				max_replacements: perMax
			}, function(response) {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.add_keyword);
				if (response.success) {
					FPP.showNotice('success', response.data.message);
					FPP.clearForm();
					FPP.loadKeywordsTable(FPP.keywordsPage);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.add_keyword);
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		editKeyword: function(e) {
			e.preventDefault();
			var $btn = $(this);

			$('#fpp-edit-id').val($btn.data('id'));
			$('#fpp-keyword').val($btn.data('keyword'));
			$('#fpp-target-url').val($btn.data('url'));
			$('#fpp-per-nofollow').prop('checked', $btn.data('nofollow') == 1);
			$('#fpp-per-new-tab').prop('checked', $btn.data('newtab') == 1);
			$('#fpp-per-max-replacements').val($btn.data('max'));

			$('#fpp-form-title').text(fppInterlinking.i18n.edit_mapping);
			$('#fpp-add-keyword').hide();
			$('#fpp-update-keyword, #fpp-cancel-edit').show();

			$('html, body').animate({
				scrollTop: $('#fpp-form-title').offset().top - 50
			}, 300);
		},

		updateKeyword: function(e) {
			e.preventDefault();
			var id        = $('#fpp-edit-id').val();
			var keyword   = $.trim($('#fpp-keyword').val());
			var targetUrl = $.trim($('#fpp-target-url').val());

			if (!id || !keyword || !targetUrl) {
				FPP.showNotice('error', fppInterlinking.i18n.required);
				return;
			}

			if (!/^https?:\/\/.+/i.test(targetUrl)) {
				FPP.showNotice('error', fppInterlinking.i18n.invalid_url);
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(fppInterlinking.i18n.updating);

			var perMax = parseInt($('#fpp-per-max-replacements').val(), 10) || 0;
			var cap    = parseInt(fppInterlinking.max_replacements_cap, 10) || 100;
			if (perMax > cap) perMax = cap;

			$.post(fppInterlinking.ajax_url, {
				action:           'fpp_interlinking_update_keyword',
				nonce:            fppInterlinking.nonce,
				id:               id,
				keyword:          keyword,
				target_url:       targetUrl,
				nofollow:         $('#fpp-per-nofollow').is(':checked') ? 1 : 0,
				new_tab:          $('#fpp-per-new-tab').is(':checked') ? 1 : 0,
				max_replacements: perMax
			}, function(response) {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.update_keyword);
				if (response.success) {
					FPP.showNotice('success', response.data.message);
					FPP.cancelEdit();
					FPP.loadKeywordsTable(FPP.keywordsPage);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.update_keyword);
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		cancelEdit: function(e) {
			if (e) e.preventDefault();
			$('#fpp-edit-id').val('');
			$('#fpp-form-title').text(fppInterlinking.i18n.add_new_mapping);
			$('#fpp-add-keyword').show();
			$('#fpp-update-keyword, #fpp-cancel-edit').hide();
			FPP.clearForm();
		},

		deleteKeyword: function(e) {
			e.preventDefault();
			if (!confirm(fppInterlinking.i18n.confirm_delete)) return;

			var id = $(this).data('id');

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_delete_keyword',
				nonce:  fppInterlinking.nonce,
				id:     id
			}, function(response) {
				if (response.success) {
					FPP.showNotice('success', response.data.message);
					FPP.loadKeywordsTable(FPP.keywordsPage);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		toggleKeyword: function(e) {
			e.preventDefault();
			var $btn      = $(this);
			var id        = $btn.data('id');
			var isActive  = parseInt($btn.data('active'));
			var newActive = isActive ? 0 : 1;

			$.post(fppInterlinking.ajax_url, {
				action:    'fpp_interlinking_toggle_keyword',
				nonce:     fppInterlinking.nonce,
				id:        id,
				is_active: newActive
			}, function(response) {
				if (response.success) {
					FPP.showNotice('success', response.data.message);
					FPP.loadKeywordsTable(FPP.keywordsPage);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		clearForm: function() {
			$('#fpp-keyword').val('');
			$('#fpp-target-url').val('');
			$('#fpp-per-nofollow').prop('checked', false);
			$('#fpp-per-new-tab').prop('checked', true);
			$('#fpp-per-max-replacements').val(0);
		},

		/* ── Quick-Add Post Search ────────────────────────────────────── */

		searchPosts: function() {
			var query = $.trim($('#fpp-post-search').val());
			var $dropdown = $('#fpp-post-search-results');

			if (query.length < 2) {
				$dropdown.hide().empty();
				return;
			}

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_search_posts',
				nonce:  fppInterlinking.nonce,
				search: query
			}, function(response) {
				if (response.success && response.data.results.length > 0) {
					var html = '';
					$.each(response.data.results, function(i, post) {
						html += '<div class="fpp-search-result-item" role="option"'
							+ ' data-title="' + FPP.escAttr(post.title) + '"'
							+ ' data-url="' + FPP.escAttr(post.permalink) + '">'
							+ '<strong>' + FPP.escHtml(post.title) + '</strong>'
							+ ' <span class="fpp-search-type">(' + FPP.escHtml(post.post_type) + ')</span>'
							+ '<br><small>' + FPP.escHtml(post.permalink) + '</small>'
							+ '</div>';
					});
					$dropdown.html(html).show();
				} else {
					$dropdown.html('<div class="fpp-search-no-results">' + FPP.escHtml(fppInterlinking.i18n.no_posts_found) + '</div>').show();
				}
			}).fail(function() {
				$dropdown.hide();
			});
		},

		selectSearchResult: function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $item = $(this);

			// Only handle Quick-Add results, not analysis search results.
			if ($item.hasClass('fpp-ai-extract-result-item')) return;

			FPP.cancelEdit();
			$('#fpp-keyword').val($item.data('title'));
			$('#fpp-target-url').val($item.data('url'));
			$('#fpp-post-search').val('');
			$('#fpp-post-search-results').hide().empty();

			$('html, body').animate({
				scrollTop: $('#fpp-form-title').offset().top - 50
			}, 300);

			FPP.highlightSection('.fpp-add-keyword-section');
		},

		dismissSearchDropdown: function(e) {
			if (!$(e.target).closest('.fpp-search-wrapper').length) {
				$('#fpp-post-search-results').hide();
				$('#fpp-ai-extract-search-results').hide();
			}
		},

		/* ── Scan per Keyword Row ─────────────────────────────────────── */

		scanKeyword: function(e) {
			e.preventDefault();
			var $btn     = $(this);
			var id       = $btn.data('id');
			var keyword  = $btn.data('keyword');
			var $row     = $('#fpp-scan-results-row-' + id);
			var $loading = $row.find('.fpp-scan-results-loading');
			var $list    = $row.find('.fpp-scan-results-list');

			if ($row.is(':visible')) {
				$row.slideUp(200);
				return;
			}

			$('.fpp-scan-results-row').slideUp(200);
			$row.slideDown(200);
			$loading.show();
			$list.empty();

			$.post(fppInterlinking.ajax_url, {
				action:  'fpp_interlinking_scan_keyword',
				nonce:   fppInterlinking.nonce,
				keyword: keyword
			}, function(response) {
				$loading.hide();
				if (response.success && response.data.results.length > 0) {
					var html = '<p class="fpp-scan-summary">'
						+ FPP.escHtml(fppInterlinking.i18n.scan_found.replace('%d', response.data.results.length))
						+ '</p><ul class="fpp-scan-list">';
					$.each(response.data.results, function(i, post) {
						html += '<li class="fpp-scan-item">'
							+ '<span class="fpp-scan-title">' + FPP.escHtml(post.title) + '</span>'
							+ ' <span class="fpp-scan-type">(' + FPP.escHtml(post.post_type) + ')</span>'
							+ ' <span class="fpp-scan-url">' + FPP.escHtml(post.permalink) + '</span>'
							+ ' <button type="button" class="button button-small button-primary fpp-use-url"'
							+ ' data-keyword-id="' + FPP.escAttr(String(id)) + '"'
							+ ' data-url="' + FPP.escAttr(post.permalink) + '">'
							+ FPP.escHtml(fppInterlinking.i18n.use_this_url)
							+ '</button></li>';
					});
					html += '</ul>';
					html += '<p><button type="button" class="button button-small fpp-close-scan" data-id="' + id + '">' + FPP.escHtml(fppInterlinking.i18n.close) + '</button></p>';
					$list.html(html);
				} else {
					$list.html(
						'<p class="fpp-scan-empty">' + FPP.escHtml(fppInterlinking.i18n.scan_no_results) + '</p>'
						+ '<p><button type="button" class="button button-small fpp-close-scan" data-id="' + id + '">' + FPP.escHtml(fppInterlinking.i18n.close) + '</button></p>'
					);
				}
			}).fail(function() {
				$loading.hide();
				$list.html('<p class="fpp-scan-error">' + FPP.escHtml(fppInterlinking.i18n.request_failed) + '</p>');
			});
		},

		useScannedUrl: function(e) {
			e.preventDefault();
			var $btn      = $(this);
			var keywordId = $btn.data('keyword-id');
			var url       = $btn.data('url');

			$btn.prop('disabled', true).text(fppInterlinking.i18n.updating);

			var $editBtn = $('#fpp-keyword-row-' + keywordId).find('.fpp-edit-keyword');

			$.post(fppInterlinking.ajax_url, {
				action:           'fpp_interlinking_update_keyword',
				nonce:            fppInterlinking.nonce,
				id:               keywordId,
				keyword:          $editBtn.data('keyword'),
				target_url:       url,
				nofollow:         $editBtn.data('nofollow'),
				new_tab:          $editBtn.data('newtab'),
				max_replacements: $editBtn.data('max')
			}, function(response) {
				if (response.success) {
					FPP.showNotice('success', response.data.message);
					$('#fpp-scan-results-row-' + keywordId).slideUp(200);
					FPP.loadKeywordsTable(FPP.keywordsPage);
				} else {
					FPP.showNotice('error', response.data.message);
					$btn.prop('disabled', false).text(fppInterlinking.i18n.use_this_url);
				}
			}).fail(function() {
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
				$btn.prop('disabled', false).text(fppInterlinking.i18n.use_this_url);
			});
		},

		closeScanResults: function(e) {
			e.preventDefault();
			$('#fpp-scan-results-row-' + $(this).data('id')).slideUp(200);
		},

		/* ── Suggest Keywords ────────────────────────────────────────── */

		toggleSuggestions: function() {
			FPP.toggleSection($('#fpp-toggle-suggestions'), '#fpp-suggestions-content');
		},

		scanTitles: function(e) {
			e.preventDefault();
			FPP.suggestionsPage = 1;
			FPP.loadSuggestionsPage(1);
		},

		loadSuggestionsPage: function(page) {
			var $btn = $('#fpp-scan-titles');
			$btn.prop('disabled', true).text(fppInterlinking.i18n.scanning);

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_suggest_keywords',
				nonce:  fppInterlinking.nonce,
				page:   page
			}, function(response) {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.scan_post_titles);
				if (response.success) {
					FPP.suggestionsPage = response.data.page;
					FPP.renderSuggestions(response.data);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.scan_post_titles);
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		renderSuggestions: function(data) {
			var $results = $('#fpp-suggestions-results');
			var $tbody   = $('#fpp-suggestions-tbody');
			$tbody.empty();

			if (data.results.length === 0) {
				$results.hide();
				FPP.showNotice('error', fppInterlinking.i18n.no_suggestions);
				return;
			}

			$.each(data.results, function(i, post) {
				var statusText  = post.already_added ? fppInterlinking.i18n.already_mapped : fppInterlinking.i18n.available;
				var statusClass = post.already_added ? 'fpp-badge-inactive' : 'fpp-badge-active';
				var actionBtn   = post.already_added
					? '<span class="description">' + FPP.escHtml(fppInterlinking.i18n.already_mapped) + '</span>'
					: '<button type="button" class="button button-small button-primary fpp-add-suggestion"'
					  + ' data-title="' + FPP.escAttr(post.title) + '"'
					  + ' data-url="' + FPP.escAttr(post.permalink) + '">'
					  + FPP.escHtml(fppInterlinking.i18n.add_as_keyword)
					  + '</button>';

				$tbody.append(
					'<tr>'
					+ '<td>' + FPP.escHtml(post.title) + '</td>'
					+ '<td>' + FPP.escHtml(post.post_type) + '</td>'
					+ '<td><a href="' + FPP.escAttr(post.permalink) + '" target="_blank" rel="noopener noreferrer">' + FPP.escHtml(post.permalink) + '</a></td>'
					+ '<td><span class="' + statusClass + '">' + FPP.escHtml(statusText) + '</span></td>'
					+ '<td>' + actionBtn + '</td>'
					+ '</tr>'
				);
			});

			var info = fppInterlinking.i18n.page_info
				.replace('%1$d', data.page)
				.replace('%2$d', data.total_pages)
				.replace('%3$d', data.total_posts);
			$('.fpp-suggestions-info').text(info);
			$('#fpp-suggestions-prev').prop('disabled', data.page <= 1);
			$('#fpp-suggestions-next').prop('disabled', data.page >= data.total_pages);

			$results.show();
		},

		addSuggestion: function(e) {
			e.preventDefault();
			var $btn  = $(this);
			FPP.cancelEdit();
			$('#fpp-keyword').val($btn.data('title'));
			$('#fpp-target-url').val($btn.data('url'));

			$('html, body').animate({
				scrollTop: $('#fpp-form-title').offset().top - 50
			}, 300);

			FPP.highlightSection('.fpp-add-keyword-section');
		},

		/* ── Analysis Tools (Dispatcher) ─────────────────────────────── */

		aiExtractSearch: function() {
			var query = $.trim($('#fpp-ai-extract-search').val());
			var $dropdown = $('#fpp-ai-extract-search-results');

			if (query.length < 2) {
				$dropdown.hide().empty();
				return;
			}

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_search_posts',
				nonce:  fppInterlinking.nonce,
				search: query
			}, function(response) {
				if (response.success && response.data.results.length > 0) {
					var html = '';
					$.each(response.data.results, function(i, post) {
						html += '<div class="fpp-search-result-item fpp-ai-extract-result-item" role="option"'
							+ ' data-id="' + post.id + '"'
							+ ' data-title="' + FPP.escAttr(post.title) + '"'
							+ ' data-url="' + FPP.escAttr(post.permalink) + '">'
							+ '<strong>' + FPP.escHtml(post.title) + '</strong>'
							+ ' <span class="fpp-search-type">(' + FPP.escHtml(post.post_type) + ')</span>'
							+ '<br><small>' + FPP.escHtml(post.permalink) + '</small>'
							+ '</div>';
					});
					$dropdown.html(html).show();
				} else {
					$dropdown.html('<div class="fpp-search-no-results">' + FPP.escHtml(fppInterlinking.i18n.no_posts_found) + '</div>').show();
				}
			});
		},

		aiExtractSelectPost: function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $item = $(this);
			$('#fpp-ai-extract-post-id').val($item.data('id'));
			$('#fpp-ai-extract-selected').html(
				'<strong>' + FPP.escHtml($item.data('title')) + '</strong>'
				+ ' <small>(' + FPP.escHtml($item.data('url')) + ')</small>'
			).show();
			$('#fpp-ai-extract-search').val('');
			$('#fpp-ai-extract-search-results').hide().empty();
			$('#fpp-ai-extract-btn').prop('disabled', false);
		},

		analyzeExtract: function(e) {
			e.preventDefault();
			var postId = $('#fpp-ai-extract-post-id').val();
			if (!postId) {
				FPP.showNotice('error', fppInterlinking.i18n.ai_select_post);
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px;vertical-align:middle;"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_processing));

			$.post(fppInterlinking.ajax_url, {
				action:  'fpp_interlinking_analyze_extract',
				nonce:   fppInterlinking.nonce,
				post_id: postId
			}, function(response) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-lightbulb"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_extract_btn));
				if (response.success) {
					FPP.renderAiExtractResults(response.data);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-lightbulb"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_extract_btn));
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		renderAiExtractResults: function(data) {
			var $results = $('#fpp-ai-extract-results');
			var $tbody = $('#fpp-ai-extract-tbody');
			$tbody.empty();

			if (!data.keywords || data.keywords.length === 0) {
				$results.hide();
				FPP.showNotice('error', fppInterlinking.i18n.ai_no_results);
				return;
			}

			$.each(data.keywords, function(i, kw) {
				var keyword = kw.keyword || '';
				var relevance = kw.relevance || 0;
				var exists = kw.already_exists;
				var scoreClass = relevance >= 7 ? 'fpp-score-high' : (relevance >= 4 ? 'fpp-score-medium' : 'fpp-score-low');

				var actionBtn = exists
					? '<span class="description">' + FPP.escHtml(fppInterlinking.i18n.already_mapped) + '</span>'
					: '<button type="button" class="button button-small button-primary fpp-ai-add-mapping"'
					  + ' data-keyword="' + FPP.escAttr(keyword) + '"'
					  + ' data-url="' + FPP.escAttr(data.post_url) + '">'
					  + FPP.escHtml(fppInterlinking.i18n.ai_add_mapping)
					  + '</button>';

				$tbody.append(
					'<tr>'
					+ '<td class="column-ai-keyword">' + FPP.escHtml(keyword) + '</td>'
					+ '<td class="column-ai-relevance"><span class="fpp-ai-score ' + scoreClass + '">' + FPP.escHtml(String(relevance)) + '/10</span></td>'
					+ '<td class="column-ai-actions">' + actionBtn + '</td>'
					+ '</tr>'
				);
			});

			$results.show();
		},

		analyzeScore: function(e) {
			e.preventDefault();
			var keyword = $.trim($('#fpp-ai-score-keyword').val());
			if (!keyword) {
				FPP.showNotice('error', fppInterlinking.i18n.ai_enter_keyword);
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px;vertical-align:middle;"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_processing));

			$.post(fppInterlinking.ajax_url, {
				action:  'fpp_interlinking_analyze_score',
				nonce:   fppInterlinking.nonce,
				keyword: keyword
			}, function(response) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-chart-bar"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_score_btn));
				if (response.success) {
					FPP.renderAiScoreResults(response.data);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-chart-bar"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_score_btn));
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		renderAiScoreResults: function(data) {
			var $results = $('#fpp-ai-score-results');
			var $tbody = $('#fpp-ai-score-tbody');
			$tbody.empty();

			if (!data.scores || data.scores.length === 0) {
				$results.hide();
				FPP.showNotice('error', fppInterlinking.i18n.ai_no_results);
				return;
			}

			$.each(data.scores, function(i, item) {
				var score = item.score || 0;
				var scoreClass = score >= 70 ? 'fpp-score-high' : (score >= 40 ? 'fpp-score-medium' : 'fpp-score-low');

				$tbody.append(
					'<tr>'
					+ '<td class="column-ai-title">' + FPP.escHtml(item.title || '') + '</td>'
					+ '<td class="column-ai-url"><a href="' + FPP.escAttr(item.url || '') + '" target="_blank" rel="noopener noreferrer">' + FPP.escHtml(item.url || '') + '</a></td>'
					+ '<td class="column-ai-score"><span class="fpp-ai-score ' + scoreClass + '">' + FPP.escHtml(String(score)) + '/100</span></td>'
					+ '<td class="column-ai-reason">' + FPP.escHtml(item.reason || '') + '</td>'
					+ '<td class="column-ai-actions">'
					+ '<button type="button" class="button button-small button-primary fpp-ai-add-mapping"'
					+ ' data-keyword="' + FPP.escAttr(data.keyword) + '"'
					+ ' data-url="' + FPP.escAttr(item.url || '') + '">'
					+ FPP.escHtml(fppInterlinking.i18n.ai_add_mapping)
					+ '</button></td>'
					+ '</tr>'
				);
			});

			$results.show();
		},

		analyzeGaps: function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px;vertical-align:middle;"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_processing));
			$('#fpp-ai-gaps-status').text('');

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_analyze_gaps',
				nonce:  fppInterlinking.nonce,
				offset: 0
			}, function(response) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_gaps_btn));
				if (response.success) {
					FPP.renderAiGapsResults(response.data);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_gaps_btn));
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		renderAiGapsResults: function(data) {
			var $results = $('#fpp-ai-gaps-results');
			var $tbody = $('#fpp-ai-gaps-tbody');
			$tbody.empty();

			var statusText = fppInterlinking.i18n.ai_analysed_info
				.replace('%1$d', data.analysed || 0)
				.replace('%2$d', data.total_posts || 0);
			$('#fpp-ai-gaps-status').text(statusText);

			if (!data.gaps || data.gaps.length === 0) {
				$results.hide();
				FPP.showNotice('success', fppInterlinking.i18n.ai_no_gaps);
				return;
			}

			$.each(data.gaps, function(i, gap) {
				var confidence = gap.confidence || 0;
				var scoreClass = confidence >= 70 ? 'fpp-score-high' : (confidence >= 40 ? 'fpp-score-medium' : 'fpp-score-low');

				$tbody.append(
					'<tr>'
					+ '<td class="column-ai-keyword">' + FPP.escHtml(gap.keyword || '') + '</td>'
					+ '<td class="column-ai-source">' + FPP.escHtml(gap.source_title || '') + '</td>'
					+ '<td class="column-ai-target">' + FPP.escHtml(gap.target_title || '') + '</td>'
					+ '<td class="column-ai-confidence"><span class="fpp-ai-score ' + scoreClass + '">' + FPP.escHtml(String(confidence)) + '%</span></td>'
					+ '<td class="column-ai-reason">' + FPP.escHtml(gap.reason || '') + '</td>'
					+ '<td class="column-ai-actions">'
					+ '<button type="button" class="button button-small button-primary fpp-ai-add-mapping"'
					+ ' data-keyword="' + FPP.escAttr(gap.keyword || '') + '"'
					+ ' data-url="' + FPP.escAttr(gap.target_url || '') + '">'
					+ FPP.escHtml(fppInterlinking.i18n.ai_add_mapping)
					+ '</button></td>'
					+ '</tr>'
				);
			});

			$results.show();
		},

		analyzeGenerate: function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px;vertical-align:middle;"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_processing));
			$('#fpp-ai-generate-status').text('');
			$('#fpp-ai-add-all-btn').hide();

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_analyze_generate',
				nonce:  fppInterlinking.nonce,
				offset: 0
			}, function(response) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_generate_btn));
				if (response.success) {
					FPP.renderAiGenerateResults(response.data);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> ' + FPP.escHtml(fppInterlinking.i18n.ai_generate_btn));
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		renderAiGenerateResults: function(data) {
			var $results = $('#fpp-ai-generate-results');
			var $tbody = $('#fpp-ai-generate-tbody');
			$tbody.empty();

			var statusText = fppInterlinking.i18n.ai_analysed_info
				.replace('%1$d', data.analysed || 0)
				.replace('%2$d', data.total_posts || 0);
			$('#fpp-ai-generate-status').text(statusText);

			if (!data.mappings || data.mappings.length === 0) {
				$results.hide();
				$('#fpp-ai-add-all-btn').hide();
				FPP.showNotice('error', fppInterlinking.i18n.ai_no_results);
				return;
			}

			$.each(data.mappings, function(i, m) {
				var confidence = m.confidence || 0;
				var scoreClass = confidence >= 70 ? 'fpp-score-high' : (confidence >= 40 ? 'fpp-score-medium' : 'fpp-score-low');

				$tbody.append(
					'<tr>'
					+ '<td class="column-ai-keyword">' + FPP.escHtml(m.keyword || '') + '</td>'
					+ '<td class="column-ai-url"><a href="' + FPP.escAttr(m.target_url || '') + '" target="_blank" rel="noopener noreferrer">' + FPP.escHtml(m.target_url || '') + '</a></td>'
					+ '<td class="column-ai-target">' + FPP.escHtml(m.target_title || '') + '</td>'
					+ '<td class="column-ai-confidence"><span class="fpp-ai-score ' + scoreClass + '">' + FPP.escHtml(String(confidence)) + '%</span></td>'
					+ '<td class="column-ai-actions">'
					+ '<button type="button" class="button button-small button-primary fpp-ai-add-mapping"'
					+ ' data-keyword="' + FPP.escAttr(m.keyword || '') + '"'
					+ ' data-url="' + FPP.escAttr(m.target_url || '') + '">'
					+ FPP.escHtml(fppInterlinking.i18n.ai_add_mapping)
					+ '</button></td>'
					+ '</tr>'
				);
			});

			$results.show();
			if (data.mappings.length > 1) {
				$('#fpp-ai-add-all-btn').show();
			}
		},

		/* ── Add Mapping (shared) ────────────────────────────────────── */

		aiAddMapping: function(e) {
			e.preventDefault();
			var $btn = $(this);
			var keyword = $btn.data('keyword');
			var url = $btn.data('url');

			$btn.prop('disabled', true).text(fppInterlinking.i18n.adding);

			$.post(fppInterlinking.ajax_url, {
				action:     'fpp_interlinking_ai_add_mapping',
				nonce:      fppInterlinking.nonce,
				keyword:    keyword,
				target_url: url
			}, function(response) {
				if (response.success) {
					$btn.replaceWith('<span class="fpp-badge-active">' + FPP.escHtml(fppInterlinking.i18n.ai_added) + '</span>');
					FPP.loadKeywordsTable(FPP.keywordsPage);
				} else {
					$btn.prop('disabled', false).text(fppInterlinking.i18n.ai_add_mapping);
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false).text(fppInterlinking.i18n.ai_add_mapping);
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		aiAddAllMappings: function(e) {
			e.preventDefault();
			var $btns = $('#fpp-ai-generate-tbody .fpp-ai-add-mapping:not(:disabled)');
			if ($btns.length === 0) return;

			var $allBtn = $(this);
			$allBtn.prop('disabled', true).text(fppInterlinking.i18n.adding_all);

			var queue = $btns.toArray();
			var added = 0;

			function processNext() {
				if (queue.length === 0) {
					$allBtn.prop('disabled', false).text(fppInterlinking.i18n.ai_add_all);
					FPP.showNotice('success', fppInterlinking.i18n.ai_added_count.replace('%d', added));
					FPP.loadKeywordsTable(FPP.keywordsPage);
					return;
				}

				var btn = queue.shift();
				var $btn = $(btn);

				$btn.prop('disabled', true).text(fppInterlinking.i18n.adding);

				$.post(fppInterlinking.ajax_url, {
					action:     'fpp_interlinking_ai_add_mapping',
					nonce:      fppInterlinking.nonce,
					keyword:    $btn.data('keyword'),
					target_url: $btn.data('url')
				}, function(response) {
					if (response.success) {
						$btn.replaceWith('<span class="fpp-badge-active">' + FPP.escHtml(fppInterlinking.i18n.ai_added) + '</span>');
						added++;
					} else {
						$btn.prop('disabled', false).text(fppInterlinking.i18n.ai_add_mapping);
					}
					processNext();
				}).fail(function() {
					$btn.prop('disabled', false).text(fppInterlinking.i18n.ai_add_mapping);
					processNext();
				});
			}

			processNext();
		},

		/* ── AI Settings Helpers ─────────────────────────────────────── */

		testAiConnection: function(e) {
			e.preventDefault();
			var $btn = $(this);
			var $status = $('#fpp-ai-connection-status');
			$btn.prop('disabled', true);
			$status.html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>').show();

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_test_ai_connection',
				nonce:  fppInterlinking.nonce
			}, function(response) {
				$btn.prop('disabled', false);
				if (response.success) {
					$status.html('<span class="fpp-badge-active">' + FPP.escHtml(response.data.message) + '</span>');
				} else {
					$status.html('<span class="fpp-badge-inactive">' + FPP.escHtml(response.data.message) + '</span>');
				}
			}).fail(function() {
				$btn.prop('disabled', false);
				$status.html('<span class="fpp-badge-inactive">' + FPP.escHtml(fppInterlinking.i18n.request_failed) + '</span>');
			});
		},

		onProviderChange: function() {
			var provider = $(this).val();
			var $selected = $(this).find(':selected');
			var requiresKey = $selected.data('requires-key') === 1 || $selected.data('requires-key') === '1';

			var defaults = {
				openai: 'gpt-4o-mini',
				anthropic: 'claude-sonnet-4-20250514',
				ollama: 'llama3.2',
				gemini: 'gemini-2.0-flash',
				mistral: 'mistral-small-latest',
				deepseek: 'deepseek-chat'
			};

			// Toggle API key vs base URL visibility.
			$('#fpp-ai-api-key-row').toggle(requiresKey);
			$('#fpp-ai-base-url-row').toggle(provider === 'ollama');

			// Swap model if it belongs to a different provider.
			var $model = $('#fpp-ai-model');
			var current = $model.val();
			var isOtherProviderModel = false;
			$.each(defaults, function(key, model) {
				if (key !== provider && current === model) {
					isOtherProviderModel = true;
					return false;
				}
			});
			if (!current || isOtherProviderModel) {
				$model.val(defaults[provider] || '');
			}
		},

		/* ── UI Helpers ───────────────────────────────────────────────── */

		highlightSection: function(selector) {
			var $section = $(selector);
			$section.addClass('fpp-highlight');
			setTimeout(function() {
				$section.removeClass('fpp-highlight');
			}, 2000);
		},

		showNotice: function(type, message) {
			var cssClass = (type === 'success') ? 'notice-success' : 'notice-error';
			var $notice  = $('<div class="notice ' + cssClass + ' is-dismissible" role="alert"><p>' + FPP.escHtml(message) + '</p></div>');
			$('#fpp-notices').html('').append($notice);
			$('html, body').animate({ scrollTop: 0 }, 200);
			setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 4000);
		},

		escHtml: function(str) {
			if (typeof str !== 'string') str = String(str);
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		},

		escAttr: function(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;');
		},

		/* ── v4.0.0: AJAX Tab Switching ──────────────────────────────── */

		switchTab: function(tab, fromPopstate) {
			if (!tab) return;

			// Update active tab highlight.
			$('.fpp-nav-tabs .nav-tab').removeClass('nav-tab-active');
			$('.fpp-nav-tabs .nav-tab[data-tab="' + tab + '"]').addClass('nav-tab-active');

			// Show skeleton while loading.
			$('.fpp-tab-content').html('<div class="fpp-skeleton fpp-skeleton-content"></div>');

			// Update URL without reload.
			if (!fromPopstate) {
				var url = new URL(window.location.href);
				url.searchParams.set('tab', tab);
				history.pushState({ tab: tab }, '', url.toString());
			}

			$.post(fppInterlinking.ajax_url, {
				action: 'fpp_interlinking_load_tab',
				nonce:  fppInterlinking.nonce,
				tab:    tab
			}, function(response) {
				if (response.success) {
					$('.fpp-tab-content').html(response.data.html);
					FPP.initCurrentTab();
				}
			}).fail(function() {
				$('.fpp-tab-content').html('<p>' + FPP.escHtml(fppInterlinking.i18n.request_failed) + '</p>');
			});
		},

		/* ── v4.0.0: Analytics CSV Export ────────────────────────────── */

		exportAnalyticsCsv: function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true);

			var postData = {
				action: 'fpp_interlinking_export_analytics_csv',
				nonce:  fppInterlinking.nonce,
				type:   'top_keywords',
				period: FPP.analyticsPeriod
			};

			if (FPP.analyticsPeriod === 'custom') {
				postData.start_date = FPP.analyticsStartDate;
				postData.end_date   = FPP.analyticsEndDate;
			}

			$.post(fppInterlinking.ajax_url, postData, function(response) {
				$btn.prop('disabled', false);
				if (response.success) {
					var blob = new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' });
					var link = document.createElement('a');
					link.href = URL.createObjectURL(blob);
					link.download = response.data.filename;
					link.click();
					URL.revokeObjectURL(link.href);
				} else {
					FPP.showNotice('error', response.data.message);
				}
			}).fail(function() {
				$btn.prop('disabled', false);
				FPP.showNotice('error', fppInterlinking.i18n.request_failed);
			});
		},

		/* ── v4.0.0: Sortable Tables ────────────────────────────────── */

		makeSortable: function($table) {
			if (!$table.length || !$table.hasClass('fpp-sortable')) return;

			$table.find('thead th').each(function(colIdx) {
				var $th = $(this);
				if ($th.hasClass('column-cb') || $th.hasClass('column-actions')) return;

				$th.addClass('fpp-sort-header').on('click', function() {
					var $tbody = $table.find('tbody');
					var rows = $tbody.find('tr').get();
					var asc = !$th.hasClass('fpp-sort-asc');

					// Reset other headers.
					$table.find('th').removeClass('fpp-sort-asc fpp-sort-desc');
					$th.addClass(asc ? 'fpp-sort-asc' : 'fpp-sort-desc');

					rows.sort(function(a, b) {
						var aVal = $(a).children('td').eq(colIdx).text().trim();
						var bVal = $(b).children('td').eq(colIdx).text().trim();
						var aNum = parseFloat(aVal.replace(/[^0-9.\-]/g, ''));
						var bNum = parseFloat(bVal.replace(/[^0-9.\-]/g, ''));

						if (!isNaN(aNum) && !isNaN(bNum)) {
							return asc ? aNum - bNum : bNum - aNum;
						}
						return asc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
					});

					$.each(rows, function(i, row) {
						$tbody.append(row);
					});
				});
			});
		},

		/* ── v4.0.0: Sparkline Mini Charts ──────────────────────────── */

		renderSparkline: function(canvasId, values) {
			var canvas = document.getElementById(canvasId);
			if (!canvas || typeof Chart === 'undefined' || !values || values.length === 0) return;

			new Chart(canvas.getContext('2d'), {
				type: 'line',
				data: {
					labels: values.map(function() { return ''; }),
					datasets: [{
						data: values,
						borderColor: '#2271b1',
						borderWidth: 1.5,
						pointRadius: 0,
						fill: false,
						tension: 0.4
					}]
				},
				options: {
					responsive: false,
					maintainAspectRatio: false,
					plugins: { legend: { display: false }, tooltip: { enabled: false } },
					scales: { x: { display: false }, y: { display: false } },
					animation: false
				}
			});
		}
	};

	$(document).ready(function() {
		FPP.init();
	});

})(jQuery);
