<?php
/**
 * Plugin Name: DigitB News Cloudflare Smart Purge
 * Plugin URI: https://digitb.com/
 * Description: Targeted Cloudflare cache purge for WordPress news publishing, with optional purge URL logging and automatic log retention.
 * Version: 1.3.1
 * Author: Hossain Thoufique Ifthekher
 * Author URI: https://digitb.com/
 * License: GPL-2.0-or-later
 * Text Domain: digitb-news-cloudflare-smart-purge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DigitB_News_Cloudflare_Smart_Purge {
	const VERSION = '1.3.1';
	const CRON_HOOK = 'digitb_cfsp_process_queue';
	const CLEANUP_HOOK = 'digitb_cfsp_cleanup_logs';
	const OPTION_SETTINGS = 'digitb_cfsp_settings';
	const OPTION_QUEUE = 'digitb_cfsp_queue';
	const TABLE_SUFFIX = 'digitb_cfsp_logs';
	const NONCE_ACTION = 'digitb_cfsp_admin';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'transition_post_status', array( $this, 'queue_post_purge' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_logs' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	}

	public static function activate() {
		self::create_table();
		$settings = get_option( self::OPTION_SETTINGS, array() );
		$defaults = self::defaults();
		update_option( self::OPTION_SETTINGS, wp_parse_args( $settings, $defaults ), false );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + 300, 'digitb_daily', self::CLEANUP_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	private static function defaults() {
		return array(
			'logger_enabled' => 0,
			'log_retention' => '48h',
			'max_log_rows'  => 500,
		);
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	private static function create_table() {
		global $wpdb;
		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'INFO',
			message text NULL,
			url_count int(10) unsigned NOT NULL DEFAULT 0,
			urls longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	public function cron_schedules( $schedules ) {
		$schedules['digitb_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily (DigitB Smart Purge)', 'digitb-news-cloudflare-smart-purge' ),
		);
		return $schedules;
	}

	private function settings() {
		return wp_parse_args( get_option( self::OPTION_SETTINGS, array() ), self::defaults() );
	}

	public function admin_menu() {
		add_management_page(
			'Cloudflare Smart Purge',
			'Cloudflare Smart Purge',
			'manage_options',
			'digitb-cloudflare-smart-purge',
			array( $this, 'admin_page' )
		);
	}

	public function handle_admin_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['digitb_cfsp_action'] ) ) {
			return;
		}
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['digitb_cfsp_action'] ) );

		if ( 'save_settings' === $action ) {
			$settings = $this->settings();
			$settings['logger_enabled'] = ! empty( $_POST['logger_enabled'] ) ? 1 : 0;
			$retention = isset( $_POST['log_retention'] ) ? sanitize_key( wp_unslash( $_POST['log_retention'] ) ) : '48h';
			$allowed = array( '24h', '48h', '1w' );
			$settings['log_retention'] = in_array( $retention, $allowed, true ) ? $retention : '48h';
			$max_rows = isset( $_POST['max_log_rows'] ) ? absint( $_POST['max_log_rows'] ) : 500;
			$settings['max_log_rows'] = max( 50, min( 5000, $max_rows ) );
			update_option( self::OPTION_SETTINGS, $settings, false );
			$this->cleanup_logs();
			$this->redirect_notice( 'settings_saved' );
		}

		if ( 'clear_logs' === $action ) {
			$this->clear_logs();
			$this->redirect_notice( 'logs_cleared' );
		}

		if ( 'purge_homepage' === $action ) {
			$result = $this->purge_urls( array( home_url( '/' ) ), 'Manual homepage purge' );
			$this->redirect_notice( $result['success'] ? 'purge_success' : 'purge_failed' );
		}
	}

	private function redirect_notice( $notice ) {
		$url = add_query_arg(
			array(
				'page'   => 'digitb-cloudflare-smart-purge',
				'notice' => $notice,
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings();
		$logs = $this->get_recent_logs( 100 );
		$api_ready = $this->api_credentials_ready();
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1>DigitB Cloudflare Smart Purge</h1>
			<?php if ( 'settings_saved' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
			<?php if ( 'logs_cleared' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>Recent logs cleared.</p></div><?php endif; ?>
			<?php if ( 'purge_success' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>Homepage purge request completed successfully.</p></div><?php endif; ?>
			<?php if ( 'purge_failed' === $notice ) : ?><div class="notice notice-error is-dismissible"><p>Homepage purge request failed. Check Recent Logs.</p></div><?php endif; ?>

			<h2>Cloudflare API</h2>
			<p>
				<strong>Status:</strong>
				<?php echo $api_ready ? '<span style="color:green">Ready</span>' : '<span style="color:#b32d2e">Not configured</span>'; ?>
			</p>
			<p>Configure <code>CLOUDFLARE_SMART_PURGE_ZONE_ID</code> and <code>CLOUDFLARE_SMART_PURGE_API_TOKEN</code> in <code>wp-config.php</code>. Legacy <code>NEWS_CF_ZONE_ID</code> and <code>NEWS_CF_API_TOKEN</code> constants are also supported for backward compatibility. The token should have only the required Cache Purge permission.</p>

			<h2>Smart Purge URL Logger</h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="digitb_cfsp_action" value="save_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable URL Logger</th>
						<td><label><input type="checkbox" name="logger_enabled" value="1" <?php checked( 1, (int) $settings['logger_enabled'] ); ?>> Log Cloudflare purge requests and results</label><p class="description">Turn this off temporarily whenever you do not need purge URL logging. Purging itself continues to work.</p></td>
					</tr>
					<tr>
						<th scope="row">Log retention</th>
						<td>
							<select name="log_retention">
								<option value="24h" <?php selected( '24h', $settings['log_retention'] ); ?>>Last 24 hours</option>
								<option value="48h" <?php selected( '48h', $settings['log_retention'] ); ?>>Last 48 hours</option>
								<option value="1w" <?php selected( '1w', $settings['log_retention'] ); ?>>Last 1 week</option>
							</select>
							<p class="description">Older logs are automatically deleted.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Maximum log rows</th>
						<td><input type="number" name="max_log_rows" min="50" max="5000" step="1" value="<?php echo esc_attr( $settings['max_log_rows'] ); ?>"> <p class="description">Hard safety limit. Default: 500 rows.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save Logger Settings' ); ?>
			</form>

			<hr>
			<h2>Manual Actions</h2>
			<form method="post" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="digitb_cfsp_action" value="purge_homepage">
				<?php submit_button( 'Purge Homepage Cache', 'primary', 'submit', false ); ?>
			</form>
			<form method="post" style="display:inline-block;" onsubmit="return confirm('Clear all Recent Logs? This cannot be undone.');">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="digitb_cfsp_action" value="clear_logs">
				<?php submit_button( 'Clear Recent Logs', 'secondary', 'submit', false ); ?>
			</form>

			<hr>
			<h2>Recent Logs</h2>
			<?php if ( empty( $logs ) ) : ?>
				<p>No logs available.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Date/Time</th><th>Status</th><th>Message</th><th>URLs</th></tr></thead>
					<tbody>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $log->created_at, 'Y-m-d H:i:s' ) ); ?></td>
							<td><?php echo esc_html( $log->status ); ?></td>
							<td><?php echo esc_html( $log->message ); ?></td>
							<td>
								<?php
								$urls = json_decode( $log->urls, true );
								if ( is_array( $urls ) ) {
									foreach ( $urls as $url ) {
										echo '<div><code>' . esc_html( $url ) . '</code></div>';
									}
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_zone_id() {
		if ( defined( 'CLOUDFLARE_SMART_PURGE_ZONE_ID' ) && CLOUDFLARE_SMART_PURGE_ZONE_ID ) {
			return (string) CLOUDFLARE_SMART_PURGE_ZONE_ID;
		}
		if ( defined( 'NEWS_CF_ZONE_ID' ) && NEWS_CF_ZONE_ID ) {
			return (string) NEWS_CF_ZONE_ID;
		}
		return '';
	}

	private function get_api_token() {
		if ( defined( 'CLOUDFLARE_SMART_PURGE_API_TOKEN' ) && CLOUDFLARE_SMART_PURGE_API_TOKEN ) {
			return (string) CLOUDFLARE_SMART_PURGE_API_TOKEN;
		}
		if ( defined( 'NEWS_CF_API_TOKEN' ) && NEWS_CF_API_TOKEN ) {
			return (string) NEWS_CF_API_TOKEN;
		}
		return '';
	}

	private function api_credentials_ready() {
		return (bool) ( $this->get_zone_id() && $this->get_api_token() );
	}

	public function queue_post_purge( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$urls = array();
		$urls[] = get_permalink( $post );
		$urls[] = home_url( '/' );
		$urls[] = get_category_link( (int) $post->post_category[0] );

		$terms = get_the_terms( $post->ID, 'post_tag' );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$urls[] = get_term_link( $term );
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		$this->queue_urls( $urls );
	}

	private function queue_urls( $urls ) {
		$queue = get_option( self::OPTION_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$queue = array_values( array_unique( array_merge( $queue, $urls ) ) );
		$queue = array_slice( $queue, 0, 100 );
		update_option( self::OPTION_QUEUE, $queue, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	public function process_queue() {
		$urls = get_option( self::OPTION_QUEUE, array() );
		delete_option( self::OPTION_QUEUE );
		if ( empty( $urls ) ) {
			return;
		}
		$this->purge_urls( $urls, 'Automatic post publish purge' );
	}

	private function purge_urls( $urls, $context ) {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		if ( empty( $urls ) ) {
			return array( 'success' => false, 'message' => 'No valid URLs.' );
		}

		if ( ! $this->api_credentials_ready() ) {
			$this->write_log( 'ERROR', 'Cloudflare API credentials are not configured.', $urls );
			return array( 'success' => false, 'message' => 'Cloudflare API credentials are not configured.' );
		}

		$endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $this->get_zone_id() ) . '/purge_cache';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_api_token(),
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( array( 'files' => $urls ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->write_log( 'ERROR', $context . ': ' . $response->get_error_message(), $urls );
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$success = 200 === (int) $code && ! empty( $body['success'] );
		$message = $success ? $context . ': Cloudflare accepted purge request.' : $context . ': Cloudflare purge request failed (HTTP ' . $code . ').';
		$this->write_log( $success ? 'SUCCESS' : 'ERROR', $message, $urls );

		return array( 'success' => $success, 'message' => $message );
	}

	private function write_log( $status, $message, $urls = array() ) {
		$settings = $this->settings();
		if ( empty( $settings['logger_enabled'] ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'status'     => $status,
				'message'    => $message,
				'url_count'  => count( $urls ),
				'urls'       => wp_json_encode( array_values( $urls ) ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		$this->cleanup_logs();
	}

	public function cleanup_logs() {
		global $wpdb;
		$table = self::table_name();
		$settings = $this->settings();
		$seconds = '24h' === $settings['log_retention'] ? DAY_IN_SECONDS : ( '1w' === $settings['log_retention'] ? WEEK_IN_SECONDS : 2 * DAY_IN_SECONDS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );

		$max_rows = max( 50, min( 5000, absint( $settings['max_log_rows'] ) ) );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > $max_rows ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $count - $max_rows ) );
		}
	}

	private function clear_logs() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}

	private function get_recent_logs( $limit = 100 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 100, absint( $limit ) ) );
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}" );
	}
}

register_activation_hook( __FILE__, array( 'DigitB_News_Cloudflare_Smart_Purge', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DigitB_News_Cloudflare_Smart_Purge', 'deactivate' ) );
DigitB_News_Cloudflare_Smart_Purge::instance();
