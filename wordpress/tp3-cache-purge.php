<?php
/**
 * Plugin Name: TP3 CDN Cache Purge
 * Description: Purge and warm CloudFront CDN cache from wp-admin via the tp3-fast invalidation API.
 * Version: 2.0.0
 * Author: TrinityP3
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TP3_Cache_Purge {

	const API_URL      = 'https://tp3-fast.trinityp3.com/invalidate';
	const OPTION_KEY   = 'tp3_cache_purge_api_key';
	const NONCE_ACTION = 'tp3_cache_purge_action';
	const PRESETS      = array( 'all', 'home', 'css', 'js', 'static', 'blog', 'pages' );

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_submission' ) );
		add_action( 'wp_ajax_tp3_warm_discover', array( $this, 'ajax_warm_discover' ) );
		add_action( 'wp_ajax_tp3_warm_batch', array( $this, 'ajax_warm_batch' ) );
		add_action( 'wp_ajax_tp3_warm_assets', array( $this, 'ajax_warm_assets' ) );
	}

	public function add_menu() {
		add_menu_page(
			'TP3-FAST CDN',
			'TP3-FAST CDN',
			'manage_options',
			'tp3-cache-purge',
			array( $this, 'render_page' ),
			'dashicons-performance',
			81
		);
	}

	public function handle_submission() {
		if ( ! isset( $_POST['tp3_cache_purge_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['tp3_cache_purge_nonce'], self::NONCE_ACTION ) ) {
			wp_die( 'Invalid nonce.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		// Save settings.
		if ( isset( $_POST['tp3_save_settings'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['tp3_api_key'] ?? '' ) );

			if ( $key !== '' ) {
				update_option( self::OPTION_KEY, $key );
			}

			add_settings_error( 'tp3_cache_purge', 'settings_saved', 'Settings saved.', 'success' );
			set_transient( 'tp3_cache_purge_errors', get_settings_errors( 'tp3_cache_purge' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=tp3-cache-purge' ) );
			exit;
		}

		// Purge preset.
		if ( isset( $_POST['tp3_purge_preset'] ) ) {
			$preset = sanitize_text_field( wp_unslash( $_POST['tp3_purge_preset'] ) );
			if ( in_array( $preset, self::PRESETS, true ) ) {
				$this->send_purge( array( 'preset' => $preset ) );
			}
			return;
		}

		// Purge custom path.
		if ( isset( $_POST['tp3_purge_custom'] ) ) {
			$path = sanitize_text_field( wp_unslash( $_POST['tp3_custom_path'] ?? '' ) );
			if ( $path !== '' ) {
				if ( strpos( $path, '/' ) !== 0 ) {
					$path = '/' . $path;
				}
				$this->send_purge( array( 'paths' => array( $path ) ) );
			} else {
				add_settings_error( 'tp3_cache_purge', 'empty_path', 'Please enter a path to purge.', 'error' );
				set_transient( 'tp3_cache_purge_errors', get_settings_errors( 'tp3_cache_purge' ), 30 );
				wp_safe_redirect( admin_url( 'admin.php?page=tp3-cache-purge' ) );
				exit;
			}
			return;
		}
	}

	private function send_purge( $body ) {
		$api_key = get_option( self::OPTION_KEY, '' );

		if ( $api_key === '' ) {
			add_settings_error( 'tp3_cache_purge', 'missing_config', 'API Key must be configured first.', 'error' );
			set_transient( 'tp3_cache_purge_errors', get_settings_errors( 'tp3_cache_purge' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=tp3-cache-purge' ) );
			exit;
		}

		$response = wp_remote_post( self::API_URL, array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key'   => $api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			$message = 'Request failed: ' . $response->get_error_message();
			add_settings_error( 'tp3_cache_purge', 'request_failed', $message, 'error' );
		} else {
			$code = wp_remote_retrieve_response_code( $response );
			$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 ) {
				$invalidation_id = $resp_body['invalidationId'] ?? '';
				$paths_display = '';
				if ( isset( $body['preset'] ) ) {
					$paths_display = 'preset: ' . $body['preset'];
				} elseif ( isset( $body['paths'] ) ) {
					$paths_display = implode( ', ', $body['paths'] );
				}
				$message = sprintf( 'Cache purge submitted (%s).', $paths_display );
				if ( $invalidation_id ) {
					$message .= sprintf( ' Invalidation ID: %s', $invalidation_id );
				}
				add_settings_error( 'tp3_cache_purge', 'purge_success', $message, 'success' );
			} else {
				$error_msg = $resp_body['message'] ?? wp_remote_retrieve_body( $response );
				$message = sprintf( 'Purge failed (HTTP %d): %s', $code, $error_msg );
				add_settings_error( 'tp3_cache_purge', 'purge_failed', $message, 'error' );
			}
		}

		set_transient( 'tp3_cache_purge_errors', get_settings_errors( 'tp3_cache_purge' ), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=tp3-cache-purge' ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * AJAX: Discover URLs from sitemap
	 * ----------------------------------------------------------------*/
	public function ajax_warm_discover() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.', 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, '_nonce' );

		$site_url  = site_url();
		$index_url = trailingslashit( $site_url ) . 'sitemap_index.xml';

		$response = wp_remote_get( $index_url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Failed to fetch sitemap index: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$urls = array();

		// Try parsing as sitemap index first.
		$sitemap_urls = $this->parse_sitemap_locs( $body );

		if ( ! empty( $sitemap_urls ) && strpos( $body, '<sitemapindex' ) !== false ) {
			// It's a sitemap index — fetch each child sitemap.
			foreach ( $sitemap_urls as $child_url ) {
				$child_resp = wp_remote_get( $child_url, array( 'timeout' => 10 ) );
				if ( ! is_wp_error( $child_resp ) ) {
					$child_body = wp_remote_retrieve_body( $child_resp );
					$child_locs = $this->parse_sitemap_locs( $child_body );
					$urls = array_merge( $urls, $child_locs );
				}
			}
		} else {
			// Single sitemap or fallback.
			$urls = $sitemap_urls;
		}

		$urls = array_unique( $urls );
		wp_send_json_success( array( 'urls' => array_values( $urls ) ) );
	}

	private function parse_sitemap_locs( $xml_string ) {
		$urls = array();
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_string );
		if ( $xml === false ) {
			return $urls;
		}
		// Register namespaces — sitemaps use http://www.sitemaps.org/schemas/sitemap/0.9
		$xml->registerXPathNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		foreach ( $xml->xpath( '//sm:loc' ) as $loc ) {
			$url = trim( (string) $loc );
			if ( $url !== '' ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/* ------------------------------------------------------------------
	 * AJAX: Warm a batch of URLs
	 * ----------------------------------------------------------------*/
	public function ajax_warm_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.', 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, '_nonce' );

		$urls = isset( $_POST['urls'] ) ? (array) $_POST['urls'] : array();
		$results = array();

		foreach ( $urls as $url ) {
			$url = esc_url_raw( $url );
			if ( $url === '' ) {
				continue;
			}
			$resp = wp_remote_get( $url, array(
				'timeout'   => 5,
				'sslverify' => false,
			) );
			$results[] = array(
				'url'    => $url,
				'status' => is_wp_error( $resp ) ? 'error' : wp_remote_retrieve_response_code( $resp ),
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/* ------------------------------------------------------------------
	 * AJAX: Extract asset URLs from a page
	 * ----------------------------------------------------------------*/
	public function ajax_warm_assets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.', 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, '_nonce' );

		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( $_POST['page_url'] ) : '';
		if ( $page_url === '' ) {
			wp_send_json_error( 'No page URL provided.' );
		}

		$resp = wp_remote_get( $page_url, array( 'timeout' => 10, 'sslverify' => false ) );
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( 'Failed to fetch page: ' . $resp->get_error_message() );
		}

		$html   = wp_remote_retrieve_body( $resp );
		$assets = array();
		$site   = wp_parse_url( site_url() );
		$host   = $site['host'] ?? '';

		// CSS: <link rel="stylesheet" href="...">
		if ( preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\']/', $html, $m ) ) {
			$assets = array_merge( $assets, $m[1] );
		}
		// Also match href before rel.
		if ( preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']stylesheet["\']/', $html, $m ) ) {
			$assets = array_merge( $assets, $m[1] );
		}

		// JS: <script src="...">
		if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/', $html, $m ) ) {
			$assets = array_merge( $assets, $m[1] );
		}

		// Images: <img src="..."> and srcset
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $html, $m ) ) {
			$assets = array_merge( $assets, $m[1] );
		}
		if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/', $html, $m ) ) {
			foreach ( $m[1] as $srcset ) {
				$parts = preg_split( '/\s*,\s*/', $srcset );
				foreach ( $parts as $part ) {
					$pieces = preg_split( '/\s+/', trim( $part ) );
					if ( ! empty( $pieces[0] ) ) {
						$assets[] = $pieces[0];
					}
				}
			}
		}

		// Filter to same-host URLs only and deduplicate.
		$filtered = array();
		foreach ( array_unique( $assets ) as $asset_url ) {
			// Make protocol-relative URLs absolute.
			if ( strpos( $asset_url, '//' ) === 0 ) {
				$asset_url = 'https:' . $asset_url;
			}
			// Skip external URLs.
			$parsed = wp_parse_url( $asset_url );
			if ( isset( $parsed['host'] ) && $parsed['host'] !== $host && $parsed['host'] !== 'www.' . $host ) {
				continue;
			}
			// Make relative URLs absolute.
			if ( ! isset( $parsed['host'] ) ) {
				$asset_url = site_url( $asset_url );
			}
			$filtered[] = $asset_url;
		}

		wp_send_json_success( array( 'assets' => array_values( array_unique( $filtered ) ) ) );
	}

	/* ------------------------------------------------------------------
	 * Render admin page
	 * ----------------------------------------------------------------*/
	public function render_page() {
		$api_key    = get_option( self::OPTION_KEY, '' );
		$masked_key = $api_key !== '' ? str_repeat( '*', max( 0, strlen( $api_key ) - 4 ) ) . substr( $api_key, -4 ) : '';

		// Show any stored messages from redirect.
		$errors = get_transient( 'tp3_cache_purge_errors' );
		if ( $errors ) {
			delete_transient( 'tp3_cache_purge_errors' );
			foreach ( $errors as $error ) {
				$type = $error['type'] === 'success' ? 'success' : 'error';
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $error['message'] ) );
			}
		}
		?>
		<div class="wrap">
			<h1>TP3-FAST CDN</h1>

			<h2>Settings</h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'tp3_cache_purge_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th>API URL</th>
						<td><code><?php echo esc_html( self::API_URL ); ?></code></td>
					</tr>
					<tr>
						<th><label for="tp3_api_key">API Key</label></th>
						<td>
							<input type="password" id="tp3_api_key" name="tp3_api_key"
								value="" class="regular-text"
								placeholder="<?php echo $masked_key !== '' ? esc_attr( $masked_key ) : 'Enter API key'; ?>" />
							<?php if ( $masked_key !== '' ) : ?>
								<p class="description">Current key: <?php echo esc_html( $masked_key ); ?> &mdash; leave blank to keep existing key.</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<p><input type="submit" name="tp3_save_settings" class="button button-primary" value="Save Settings" /></p>
			</form>

			<hr />

			<h2>Purge by Preset</h2>
			<p>Select a preset to purge a group of cached paths.</p>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<?php foreach ( self::PRESETS as $preset ) : ?>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( self::NONCE_ACTION, 'tp3_cache_purge_nonce' ); ?>
						<input type="hidden" name="tp3_purge_preset" value="<?php echo esc_attr( $preset ); ?>" />
						<button type="submit" class="button button-secondary">
							<?php echo esc_html( ucfirst( $preset ) ); ?>
						</button>
					</form>
				<?php endforeach; ?>
			</div>

			<hr />

			<h2>Purge Custom Path</h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'tp3_cache_purge_nonce' ); ?>
				<p>
					<input type="text" name="tp3_custom_path" value="" class="regular-text"
						placeholder="/blog/* or /specific-page/" />
					<input type="submit" name="tp3_purge_custom" class="button button-secondary" value="Purge Path" />
				</p>
				<p class="description">Use <code>/*</code> as a wildcard. Example: <code>/blog/*</code> purges all blog pages.</p>
			</form>

			<hr />

			<h2>Warm Cache</h2>
			<p>Re-populate CloudFront cache by fetching pages from the sitemap. Run this after a purge to ensure fast page loads.</p>
			<div style="display:flex;gap:8px;margin-bottom:16px;">
				<button type="button" id="tp3-warm-pages" class="button button-secondary">Warm Cache (Pages Only)</button>
				<button type="button" id="tp3-warm-assets" class="button button-secondary">Warm Cache (Pages + Assets)</button>
			</div>
			<div id="tp3-warm-status" style="display:none;">
				<div style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;padding:2px;max-width:500px;margin-bottom:8px;">
					<div id="tp3-warm-bar" style="background:#2271b1;height:24px;border-radius:3px;width:0%;transition:width 0.3s;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;min-width:40px;">
						0%
					</div>
				</div>
				<p id="tp3-warm-message" style="color:#50575e;"></p>
			</div>
		</div>

		<script>
		(function() {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var batchSize = 5;
			var running = false;

			var btnPages  = document.getElementById('tp3-warm-pages');
			var btnAssets = document.getElementById('tp3-warm-assets');
			var statusDiv = document.getElementById('tp3-warm-status');
			var bar       = document.getElementById('tp3-warm-bar');
			var message   = document.getElementById('tp3-warm-message');

			function setProgress(pct, text) {
				var rounded = Math.round(pct);
				bar.style.width = rounded + '%';
				bar.textContent = rounded + '%';
				message.textContent = text;
			}

			function setButtons(enabled) {
				btnPages.disabled = !enabled;
				btnAssets.disabled = !enabled;
				running = !enabled;
			}

			function post(action, data) {
				return new Promise(function(resolve, reject) {
					var formData = new FormData();
					formData.append('action', action);
					formData.append('_nonce', nonce);
					for (var key in data) {
						if (data.hasOwnProperty(key)) {
							var val = data[key];
							if (Array.isArray(val)) {
								for (var i = 0; i < val.length; i++) {
									formData.append(key + '[]', val[i]);
								}
							} else {
								formData.append(key, val);
							}
						}
					}
					var xhr = new XMLHttpRequest();
					xhr.open('POST', ajaxUrl);
					xhr.onload = function() {
						try {
							var resp = JSON.parse(xhr.responseText);
							if (resp.success) resolve(resp.data);
							else reject(resp.data || 'Request failed');
						} catch(e) { reject('Invalid response'); }
					};
					xhr.onerror = function() { reject('Network error'); };
					xhr.send(formData);
				});
			}

			function warmBatches(urls, label) {
				var total = urls.length;
				var done = 0;
				var errors = 0;

				function nextBatch() {
					if (done >= total) {
						setProgress(100, label + ' complete: ' + total + ' URLs warmed' + (errors > 0 ? ' (' + errors + ' errors)' : '') + '.');
						setButtons(true);
						return;
					}
					var batch = urls.slice(done, done + batchSize);
					setProgress((done / total) * 100, label + ': warming ' + (done + 1) + '-' + Math.min(done + batchSize, total) + ' of ' + total + '...');
					post('tp3_warm_batch', { urls: batch }).then(function(data) {
						data.results.forEach(function(r) {
							if (r.status === 'error' || (typeof r.status === 'number' && r.status >= 400)) errors++;
						});
						done += batch.length;
						nextBatch();
					}).catch(function(err) {
						errors += batch.length;
						done += batch.length;
						nextBatch();
					});
				}

				nextBatch();
			}

			function startWarm(includeAssets) {
				if (running) return;
				setButtons(false);
				statusDiv.style.display = 'block';
				setProgress(0, 'Discovering URLs from sitemap...');

				post('tp3_warm_discover', {}).then(function(data) {
					var pageUrls = data.urls || [];
					if (pageUrls.length === 0) {
						setProgress(100, 'No URLs found in sitemap.');
						setButtons(true);
						return;
					}

					if (!includeAssets) {
						warmBatches(pageUrls, 'Pages');
						return;
					}

					// Warm pages first, then discover and warm assets.
					setProgress(0, 'Warming ' + pageUrls.length + ' pages first...');
					var pageDone = 0;
					var pageTotal = pageUrls.length;
					var allAssets = [];
					// Sample up to 10 pages for asset discovery.
					var samplePages = pageUrls.slice(0, 10);
					var assetsDiscovered = 0;

					function warmPageBatch() {
						if (pageDone >= pageTotal) {
							// Pages done — now discover assets.
							discoverAssets();
							return;
						}
						var batch = pageUrls.slice(pageDone, pageDone + batchSize);
						var halfProgress = (pageDone / pageTotal) * 50;
						setProgress(halfProgress, 'Warming pages: ' + (pageDone + 1) + '-' + Math.min(pageDone + batchSize, pageTotal) + ' of ' + pageTotal + '...');
						post('tp3_warm_batch', { urls: batch }).then(function() {
							pageDone += batch.length;
							warmPageBatch();
						}).catch(function() {
							pageDone += batch.length;
							warmPageBatch();
						});
					}

					function discoverAssets() {
						if (assetsDiscovered >= samplePages.length) {
							// Deduplicate and warm assets.
							var unique = [];
							var seen = {};
							allAssets.forEach(function(u) {
								if (!seen[u]) { seen[u] = true; unique.push(u); }
							});
							if (unique.length === 0) {
								setProgress(100, 'Done: ' + pageTotal + ' pages warmed, no assets found.');
								setButtons(true);
								return;
							}
							setProgress(50, 'Warming ' + unique.length + ' assets...');
							warmAssetBatches(unique, pageTotal);
							return;
						}
						setProgress(50, 'Discovering assets from page ' + (assetsDiscovered + 1) + ' of ' + samplePages.length + '...');
						post('tp3_warm_assets', { page_url: samplePages[assetsDiscovered] }).then(function(data) {
							allAssets = allAssets.concat(data.assets || []);
							assetsDiscovered++;
							discoverAssets();
						}).catch(function() {
							assetsDiscovered++;
							discoverAssets();
						});
					}

					function warmAssetBatches(assets, pageCount) {
						var total = assets.length;
						var done = 0;
						var errors = 0;

						function next() {
							if (done >= total) {
								setProgress(100, 'Done: ' + pageCount + ' pages + ' + total + ' assets warmed' + (errors > 0 ? ' (' + errors + ' errors)' : '') + '.');
								setButtons(true);
								return;
							}
							var batch = assets.slice(done, done + batchSize);
							var pct = 50 + (done / total) * 50;
							setProgress(pct, 'Warming assets: ' + (done + 1) + '-' + Math.min(done + batchSize, total) + ' of ' + total + '...');
							post('tp3_warm_batch', { urls: batch }).then(function(data) {
								data.results.forEach(function(r) {
									if (r.status === 'error' || (typeof r.status === 'number' && r.status >= 400)) errors++;
								});
								done += batch.length;
								next();
							}).catch(function() {
								errors += batch.length;
								done += batch.length;
								next();
							});
						}
						next();
					}

					warmPageBatch();
				}).catch(function(err) {
					setProgress(0, 'Error: ' + err);
					setButtons(true);
				});
			}

			btnPages.addEventListener('click', function() { startWarm(false); });
			btnAssets.addEventListener('click', function() { startWarm(true); });
		})();
		</script>
		<?php
	}
}

new TP3_Cache_Purge();
