<?php
/**
 * Plugin Name: TP3 CDN Cache Purge
 * Description: Purge CloudFront CDN cache from wp-admin via the tp3-fast invalidation API.
 * Version: 1.0.0
 * Author: TrinityP3
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TP3_Cache_Purge {

	const OPTION_URL = 'tp3_cache_purge_api_url';
	const OPTION_KEY = 'tp3_cache_purge_api_key';
	const NONCE_ACTION = 'tp3_cache_purge_action';
	const PRESETS = array( 'all', 'home', 'css', 'js', 'static', 'blog', 'pages' );

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_submission' ) );
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
			$url = esc_url_raw( wp_unslash( $_POST['tp3_api_url'] ?? '' ) );
			$key = sanitize_text_field( wp_unslash( $_POST['tp3_api_key'] ?? '' ) );

			update_option( self::OPTION_URL, $url );
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
				// Ensure path starts with /.
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
		$api_url = get_option( self::OPTION_URL, '' );
		$api_key = get_option( self::OPTION_KEY, '' );

		if ( $api_url === '' || $api_key === '' ) {
			add_settings_error( 'tp3_cache_purge', 'missing_config', 'API URL and API Key must be configured first.', 'error' );
			set_transient( 'tp3_cache_purge_errors', get_settings_errors( 'tp3_cache_purge' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=tp3-cache-purge' ) );
			exit;
		}

		$response = wp_remote_post( $api_url, array(
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

	public function render_page() {
		$api_url = get_option( self::OPTION_URL, '' );
		$api_key = get_option( self::OPTION_KEY, '' );
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
			<h1>CDN Cache Purge</h1>

			<h2>Settings</h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'tp3_cache_purge_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="tp3_api_url">API URL</label></th>
						<td>
							<input type="url" id="tp3_api_url" name="tp3_api_url"
								value="<?php echo esc_attr( $api_url ); ?>"
								class="regular-text" placeholder="https://xxx.execute-api.us-east-1.amazonaws.com/prod/invalidate" />
						</td>
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
		</div>
		<?php
	}
}

new TP3_Cache_Purge();
