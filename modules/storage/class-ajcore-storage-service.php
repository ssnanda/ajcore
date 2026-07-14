<?php
/**
 * Remote object storage integration (MinIO / RustFS / AWS S3 — anything
 * speaking the S3 API). Offloads WordPress media-library attachments from
 * local wp-content/uploads to a configured S3-compatible bucket.
 *
 * Scope of this pass: the plugin core only (settings, the storage client,
 * and the universal WP media hooks). The AJCore REST API
 * (includes/class-ajcore-rest-api.php) and AJOps are handled separately.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-ajcore-s3-client.php';

if ( ! class_exists( 'AJCore_Storage_Service' ) ) {

	class AJCore_Storage_Service {

		const OPTION_KEY = 'ajcore_storage_settings';
		const NONCE_ACTION = 'ajcore_storage_settings_save';

		/**
		 * Mime types auto-offloaded on upload, and covered by the settings-page
		 * "Migrate existing documents" bulk action. Deliberately excludes images:
		 * downloads of these go through a redirect generated fresh on each request
		 * (portal file-library links, presigned and short-lived), whereas images are
		 * routinely embedded directly in rendered/cached page HTML — a presigned URL
		 * baked into stored content would 404 once it expires. Site images (theme
		 * graphics, logos, post content) are explicitly out of scope for automatic
		 * offload; an admin can still offload a specific image deliberately via the
		 * Media Library bulk action, accepting that tradeoff themselves.
		 */
		const AUTO_OFFLOAD_MIME_TYPES = array(
			'application/pdf',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.ms-excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);

		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_filter( 'pre_update_option_' . self::OPTION_KEY, array( $this, 'encrypt_secret_on_save' ) );
			add_filter( 'option_' . self::OPTION_KEY, array( $this, 'decrypt_secret_on_read' ) );

			// Universal hook: fires after wp_generate_attachment_metadata() runs, which
			// every upload path in this plugin (and WP core's own media uploader) calls —
			// so this one filter covers portal uploads, admin/CRM uploads, and the native
			// Media Library, without needing to touch each call site individually.
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_attachment_metadata_generated' ), 20, 2 );

			// Any code anywhere that asks WordPress for an attachment's URL transparently
			// gets a presigned remote URL once that attachment has been offloaded.
			add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );

			add_action( 'admin_post_ajcore_storage_settings_save', array( $this, 'handle_settings_save' ) );
			add_action( 'wp_ajax_ajcore_storage_list_buckets', array( $this, 'ajax_list_buckets' ) );
			add_action( 'wp_ajax_ajcore_storage_migrate_now', array( $this, 'ajax_migrate_now' ) );

			// Media Library bulk action: lets an admin explicitly opt a specific file
			// (of any mime type, including images) into remote storage, one selection
			// at a time — unlike the automatic hook above, this is a deliberate choice.
			add_filter( 'bulk_actions-upload', array( $this, 'register_media_bulk_action' ) );
			add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_media_bulk_action' ), 10, 3 );
			add_action( 'admin_notices', array( $this, 'render_media_bulk_action_notice' ) );
		}

		// -----------------------------------------------------------------
		// Settings
		// -----------------------------------------------------------------

		public static function get_default_settings() {
			return array(
				'enabled'    => '0',
				'endpoint'   => '',
				'region'     => 'us-east-1',
				'bucket'     => '',
				'access_key' => '',
				'secret_key' => '',
				'path_style' => '1',
			);
		}

		public static function get_settings() {
			$saved = get_option( self::OPTION_KEY, array() );
			$saved = is_array( $saved ) ? $saved : array();
			return wp_parse_args( $saved, self::get_default_settings() );
		}

		public static function update_settings( $settings ) {
			$defaults = self::get_default_settings();
			$clean    = array();
			foreach ( $defaults as $key => $default ) {
				$clean[ $key ] = isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
			}
			$clean['enabled']    = ! empty( $settings['enabled'] ) ? '1' : '0';
			$clean['path_style'] = ! empty( $settings['path_style'] ) ? '1' : '0';

			// Preserve the existing secret if the settings form submitted a masked
			// placeholder (the settings screen never round-trips the real secret back
			// into the page source).
			if ( isset( $settings['secret_key'] ) && 0 === strpos( (string) $settings['secret_key'], '••••' ) ) {
				$existing           = self::get_settings();
				$clean['secret_key'] = $existing['secret_key'];
			}

			update_option( self::OPTION_KEY, $clean );
			return $clean;
		}

		public function encrypt_secret_on_save( $settings ) {
			if ( is_array( $settings ) && ! empty( $settings['secret_key'] ) && function_exists( 'ajcore_encrypt_setting_value' ) ) {
				$settings['secret_key'] = ajcore_encrypt_setting_value( $settings['secret_key'] );
			}
			return $settings;
		}

		public function decrypt_secret_on_read( $settings ) {
			if ( is_array( $settings ) && ! empty( $settings['secret_key'] ) && function_exists( 'ajcore_decrypt_setting_value' ) ) {
				$settings['secret_key'] = ajcore_decrypt_setting_value( $settings['secret_key'] );
			}
			return $settings;
		}

		public static function is_enabled() {
			$settings = self::get_settings();
			return '1' === (string) $settings['enabled'] && self::is_configured( $settings );
		}

		public static function is_configured( $settings = null ) {
			$settings = $settings ? $settings : self::get_settings();
			return '' !== trim( (string) $settings['endpoint'] )
				&& '' !== trim( (string) $settings['access_key'] )
				&& '' !== trim( (string) $settings['secret_key'] )
				&& '' !== trim( (string) $settings['bucket'] );
		}

		/**
		 * @param array $settings Optional explicit settings (used by "test connection"
		 *                        before saving). Falls back to the stored settings.
		 */
		public static function get_client( $settings = null ) {
			$settings = $settings ? $settings : self::get_settings();
			if ( ! self::is_configured( $settings ) && empty( $settings['endpoint'] ) ) {
				return null;
			}
			return new AJCore_S3_Client(
				array(
					'endpoint'   => $settings['endpoint'],
					'region'     => $settings['region'],
					'access_key' => $settings['access_key'],
					'secret_key' => $settings['secret_key'],
					'bucket'     => $settings['bucket'],
					'path_style' => '1' === (string) $settings['path_style'],
				)
			);
		}

		// -----------------------------------------------------------------
		// Remote-object bookkeeping (wp_aj_storage_objects)
		// -----------------------------------------------------------------

		private static function table() {
			global $wpdb;
			return $wpdb->prefix . 'aj_storage_objects';
		}

		public static function get_remote_record( $attachment_id ) {
			global $wpdb;
			$table = self::table();
			return $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE attachment_id = %d LIMIT 1", (int) $attachment_id )
			);
		}

		private static function save_remote_record( $attachment_id, $bucket, $object_key, $size_bytes, $content_type ) {
			global $wpdb;
			$table   = self::table();
			$existing = self::get_remote_record( $attachment_id );
			$data     = array(
				'attachment_id' => (int) $attachment_id,
				'driver'        => 's3',
				'bucket'        => $bucket,
				'object_key'    => $object_key,
				'size_bytes'    => (int) $size_bytes,
				'content_type'  => (string) $content_type,
				'migrated_at'   => current_time( 'mysql' ),
			);
			if ( $existing ) {
				return false !== $wpdb->update( $table, $data, array( 'attachment_id' => (int) $attachment_id ) );
			}
			return false !== $wpdb->insert( $table, $data );
		}

		// -----------------------------------------------------------------
		// Upload hook: after metadata is generated, push the file(s) to remote
		// storage and remove the local copies.
		// -----------------------------------------------------------------

		public function on_attachment_metadata_generated( $metadata, $attachment_id ) {
			if ( ! self::is_enabled() ) {
				return $metadata;
			}
			$mime_type = get_post_mime_type( (int) $attachment_id );
			if ( ! in_array( $mime_type, self::AUTO_OFFLOAD_MIME_TYPES, true ) ) {
				return $metadata; // Images and anything else stay local; see AUTO_OFFLOAD_MIME_TYPES.
			}
			$this->migrate_attachment( (int) $attachment_id );
			return $metadata;
		}

		/**
		 * Uploads an attachment's local file (and any generated image sizes) to
		 * remote storage, records the mapping, and deletes the local copies.
		 * Safe to call repeatedly — a no-op once already migrated.
		 */
		public function migrate_attachment( $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( self::get_remote_record( $attachment_id ) ) {
				return true; // Already migrated.
			}

			$settings = self::get_settings();
			$client   = self::get_client( $settings );
			if ( ! $client || ! self::is_configured( $settings ) ) {
				return new WP_Error( 'ajcore_storage_not_configured', __( 'Remote storage is not configured.', 'ajforms' ) );
			}

			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
				return new WP_Error( 'ajcore_storage_no_local_file', __( 'No local file found for this attachment.', 'ajforms' ) );
			}

			$mime_type = get_post_mime_type( $attachment_id );
			$mime_type = $mime_type ? $mime_type : 'application/octet-stream';
			$object_key = 'attachments/' . $attachment_id . '/' . sanitize_file_name( basename( $file_path ) );

			$body   = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$result = $client->put_object( $object_key, $body, $mime_type );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$size_bytes = filesize( $file_path );
			$saved      = self::save_remote_record( $attachment_id, $settings['bucket'], $object_key, $size_bytes, $mime_type );
			if ( ! $saved ) {
				return new WP_Error( 'ajcore_storage_db_error', __( 'Uploaded to remote storage but failed to record the mapping.', 'ajforms' ) );
			}

			// Also push any generated image sizes (no-op for documents like PDFs, which
			// have none), then remove every local file now that remote copies exist.
			$metadata = wp_get_attachment_metadata( $attachment_id );
			$dir      = dirname( $file_path );
			if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}
					$size_path = $dir . '/' . $size['file'];
					if ( is_file( $size_path ) && is_readable( $size_path ) ) {
						$size_key = 'attachments/' . $attachment_id . '/sizes/' . sanitize_file_name( $size['file'] );
						$client->put_object( $size_key, file_get_contents( $size_path ), isset( $size['mime-type'] ) ? $size['mime-type'] : $mime_type ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					}
					@unlink( $size_path );
				}
			}

			@unlink( $file_path );

			return true;
		}

		public function filter_attachment_url( $url, $attachment_id ) {
			$record = self::get_remote_record( $attachment_id );
			if ( ! $record ) {
				return $url;
			}
			$client = self::get_client();
			if ( ! $client ) {
				return $url;
			}
			return $client->presigned_url( $record->object_key, 15 * MINUTE_IN_SECONDS );
		}

		/**
		 * Used by the portal file-download handler to redirect straight to a
		 * short-lived signed URL instead of streaming the file through PHP.
		 */
		public static function get_presigned_download_url( $attachment_id, $expires_seconds = 300 ) {
			$record = self::get_remote_record( $attachment_id );
			if ( ! $record ) {
				return false;
			}
			$client = self::get_client();
			if ( ! $client ) {
				return false;
			}
			return $client->presigned_url( $record->object_key, $expires_seconds );
		}

		// -----------------------------------------------------------------
		// Bulk migration (Media Library → remote storage)
		// -----------------------------------------------------------------

		/**
		 * Migrates every not-yet-migrated attachment whose mime type is in
		 * AUTO_OFFLOAD_MIME_TYPES (documents only — see that constant for why
		 * images are excluded from this blanket action). Intended for small media
		 * libraries — it is not paginated/batched beyond a single request.
		 */
		public static function migrate_all_attachments() {
			$service = self::instance();
			$ids     = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => self::AUTO_OFFLOAD_MIME_TYPES,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			return self::migrate_attachment_ids( $ids );
		}

		/**
		 * Migrates an explicit list of attachment IDs regardless of mime type —
		 * used by the Media Library bulk action, where the admin has deliberately
		 * selected each file.
		 */
		public static function migrate_attachment_ids( $ids ) {
			$service = self::instance();
			$results = array( 'migrated' => array(), 'skipped' => array(), 'failed' => array() );
			foreach ( $ids as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				if ( self::get_remote_record( $attachment_id ) ) {
					$results['skipped'][] = $attachment_id;
					continue;
				}
				$outcome = $service->migrate_attachment( $attachment_id );
				if ( is_wp_error( $outcome ) ) {
					$results['failed'][ $attachment_id ] = $outcome->get_error_message();
				} else {
					$results['migrated'][] = $attachment_id;
				}
			}
			return $results;
		}

		public function register_media_bulk_action( $bulk_actions ) {
			if ( self::is_enabled() ) {
				$bulk_actions['ajcore_migrate_to_remote_storage'] = __( 'Migrate to Remote Storage', 'ajforms' );
			}
			return $bulk_actions;
		}

		public function handle_media_bulk_action( $redirect_to, $action, $post_ids ) {
			if ( 'ajcore_migrate_to_remote_storage' !== $action ) {
				return $redirect_to;
			}
			$results     = self::migrate_attachment_ids( array_map( 'intval', $post_ids ) );
			$redirect_to = add_query_arg(
				array(
					'ajcore_storage_migrated' => count( $results['migrated'] ),
					'ajcore_storage_failed'   => count( $results['failed'] ),
				),
				$redirect_to
			);
			return $redirect_to;
		}

		public function render_media_bulk_action_notice() {
			if ( ! isset( $_REQUEST['ajcore_storage_migrated'] ) ) {
				return;
			}
			$migrated = (int) $_REQUEST['ajcore_storage_migrated'];
			$failed   = isset( $_REQUEST['ajcore_storage_failed'] ) ? (int) $_REQUEST['ajcore_storage_failed'] : 0;
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: migrated count, 2: failed count */
						__( 'Remote storage: migrated %1$d file(s), %2$d failed.', 'ajforms' ),
						$migrated,
						$failed
					)
				)
			);
		}

		// -----------------------------------------------------------------
		// Admin UI
		// -----------------------------------------------------------------

		public function handle_settings_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
			}
			check_admin_referer( self::NONCE_ACTION, 'ajcore_storage_nonce' );

			$posted = array(
				'enabled'    => isset( $_POST['enabled'] ) ? '1' : '0',
				'endpoint'   => isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '',
				'region'     => isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'us-east-1',
				'bucket'     => isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '',
				'access_key' => isset( $_POST['access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['access_key'] ) ) : '',
				'secret_key' => isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '',
				'path_style' => isset( $_POST['path_style'] ) ? '1' : '0',
			);

			self::update_settings( $posted );

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'ajforms-client-portal', 'tab' => 'cp-settings', 'cp_section' => 'storage', 'notice' => 'saved' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		public function ajax_list_buckets() {
			if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ajforms' ) ), 403 );
			}

			$settings = array(
				'endpoint'   => isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '',
				'region'     => isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'us-east-1',
				'access_key' => isset( $_POST['access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['access_key'] ) ) : '',
				'secret_key' => isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '',
				'bucket'     => '',
				'path_style' => isset( $_POST['path_style'] ) ? '1' : '0',
			);

			if ( 0 === strpos( $settings['secret_key'], '••••' ) ) {
				$existing            = self::get_settings();
				$settings['secret_key'] = $existing['secret_key'];
			}

			$client = self::get_client( $settings );
			if ( ! $client ) {
				wp_send_json_error( array( 'message' => __( 'Enter an endpoint, access key, and secret key first.', 'ajforms' ) ) );
			}

			$buckets = $client->list_buckets();
			if ( is_wp_error( $buckets ) ) {
				wp_send_json_error( array( 'message' => $buckets->get_error_message() ) );
			}

			wp_send_json_success( array( 'buckets' => $buckets ) );
		}

		public function ajax_migrate_now() {
			if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ajforms' ) ), 403 );
			}

			if ( ! self::is_enabled() ) {
				wp_send_json_error( array( 'message' => __( 'Save and enable remote storage settings first.', 'ajforms' ) ) );
			}

			$results = self::migrate_all_attachments();
			wp_send_json_success( $results );
		}

		public function render_settings_page( $embedded = false ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
			}

			$settings     = self::get_settings();
			$masked_secret = '' !== $settings['secret_key'] && function_exists( 'ajcore_mask_secret_for_display' )
				? ajcore_mask_secret_for_display( $settings['secret_key'] )
				: '';
			$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
			$nonce  = wp_create_nonce( self::NONCE_ACTION );
			?>
			<div class="<?php echo $embedded ? '' : 'wrap'; ?>">
				<?php if ( ! $embedded ) : ?>
					<h1><?php esc_html_e( 'Remote Storage', 'ajforms' ); ?></h1>
				<?php endif; ?>
				<p><?php esc_html_e( 'Offload media-library files (portal uploads, CRM attachments, and anything added via the WordPress Media Library) to an S3-compatible bucket — MinIO, RustFS, AWS S3, or any other server that speaks the S3 API. Only the endpoint/credentials below change if you ever switch providers.', 'ajforms' ); ?></p>

				<?php if ( 'saved' === $notice ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Storage settings saved.', 'ajforms' ); ?></p></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;margin-top:20px;">
					<input type="hidden" name="action" value="ajcore_storage_settings_save" />
					<?php wp_nonce_field( self::NONCE_ACTION, 'ajcore_storage_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'ajforms' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enabled" value="1" <?php checked( '1', $settings['enabled'] ); ?> />
									<?php esc_html_e( 'Send new uploads to remote storage and serve migrated files from there.', 'ajforms' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_endpoint"><?php esc_html_e( 'Endpoint URL', 'ajforms' ); ?></label></th>
							<td>
								<input type="url" id="ajcore_storage_endpoint" name="endpoint" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" class="regular-text" placeholder="https://files.example.com" />
								<p class="description"><?php esc_html_e( 'The S3 API endpoint (not the web console). For MinIO on OpenShift this is the api host, not the console host.', 'ajforms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_region"><?php esc_html_e( 'Region', 'ajforms' ); ?></label></th>
							<td><input type="text" id="ajcore_storage_region" name="region" value="<?php echo esc_attr( $settings['region'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_access_key"><?php esc_html_e( 'Access Key', 'ajforms' ); ?></label></th>
							<td><input type="text" id="ajcore_storage_access_key" name="access_key" value="<?php echo esc_attr( $settings['access_key'] ); ?>" class="regular-text" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_secret_key"><?php esc_html_e( 'Secret Key', 'ajforms' ); ?></label></th>
							<td>
								<input type="password" id="ajcore_storage_secret_key" name="secret_key" value="<?php echo esc_attr( $masked_secret ); ?>" class="regular-text" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Leave the masked value in place to keep the currently saved secret.', 'ajforms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_bucket"><?php esc_html_e( 'Bucket', 'ajforms' ); ?></label></th>
							<td>
								<select id="ajcore_storage_bucket" name="bucket" style="min-width:260px">
									<?php if ( '' !== $settings['bucket'] ) : ?>
										<option value="<?php echo esc_attr( $settings['bucket'] ); ?>" selected><?php echo esc_html( $settings['bucket'] ); ?></option>
									<?php endif; ?>
								</select>
								<button type="button" class="button" id="ajcore_storage_list_buckets"><?php esc_html_e( 'List Buckets', 'ajforms' ); ?></button>
								<p class="description" id="ajcore_storage_bucket_status"></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Path-style addressing', 'ajforms' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="path_style" value="1" <?php checked( '1', $settings['path_style'] ); ?> />
									<?php esc_html_e( 'On (required for MinIO/RustFS behind a custom domain). Turn off for virtual-hosted-style addressing (typical on AWS S3).', 'ajforms' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'ajforms' ); ?></button>
					</p>
				</form>

				<hr />

				<h2><?php esc_html_e( 'Migrate Existing Documents', 'ajforms' ); ?></h2>
				<p><?php esc_html_e( 'Uploads every not-yet-migrated PDF/Word/Excel attachment to the bucket above, then removes the local copy. Images are deliberately skipped here — downloads of documents are always redirected to a fresh, short-lived link, but images are often embedded directly in page content, where a link that expires would break the page. To offload a specific image anyway, select it in Media → Library and use the "Migrate to Remote Storage" bulk action.', 'ajforms' ); ?></p>
				<button type="button" class="button button-secondary" id="ajcore_storage_migrate_now"><?php esc_html_e( 'Migrate Documents Now', 'ajforms' ); ?></button>
				<div id="ajcore_storage_migrate_result" style="margin-top:12px"></div>
			</div>
			<script>
			(function(){
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;
				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

				document.getElementById('ajcore_storage_list_buckets').addEventListener('click', function () {
					var status = document.getElementById('ajcore_storage_bucket_status');
					status.textContent = <?php echo wp_json_encode( __( 'Loading…', 'ajforms' ) ); ?>;
					var body = new URLSearchParams({
						action: 'ajcore_storage_list_buckets',
						nonce: nonce,
						endpoint: document.getElementById('ajcore_storage_endpoint').value,
						region: document.getElementById('ajcore_storage_region').value,
						access_key: document.getElementById('ajcore_storage_access_key').value,
						secret_key: document.getElementById('ajcore_storage_secret_key').value,
						path_style: document.querySelector('input[name="path_style"]').checked ? '1' : ''
					});
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (!res.success) {
								status.textContent = (res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Failed to list buckets.', 'ajforms' ) ); ?>;
								return;
							}
							var select = document.getElementById('ajcore_storage_bucket');
							var current = select.value;
							select.innerHTML = '';
							res.data.buckets.forEach(function (name) {
								var opt = document.createElement('option');
								opt.value = name;
								opt.textContent = name;
								if (name === current) { opt.selected = true; }
								select.appendChild(opt);
							});
							status.textContent = res.data.buckets.length
								? <?php echo wp_json_encode( __( 'Choose a bucket and save.', 'ajforms' ) ); ?>
								: <?php echo wp_json_encode( __( 'No buckets found on this endpoint.', 'ajforms' ) ); ?>;
						})
						.catch(function () {
							status.textContent = <?php echo wp_json_encode( __( 'Request failed.', 'ajforms' ) ); ?>;
						});
				});

				document.getElementById('ajcore_storage_migrate_now').addEventListener('click', function () {
					var out = document.getElementById('ajcore_storage_migrate_result');
					out.textContent = <?php echo wp_json_encode( __( 'Migrating…', 'ajforms' ) ); ?>;
					var body = new URLSearchParams({ action: 'ajcore_storage_migrate_now', nonce: nonce });
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (!res.success) {
								out.textContent = (res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Migration failed.', 'ajforms' ) ); ?>;
								return;
							}
							var d = res.data;
							out.textContent = 'Migrated: ' + d.migrated.length + ', Skipped (already migrated): ' + d.skipped.length + ', Failed: ' + Object.keys(d.failed).length;
						})
						.catch(function () {
							out.textContent = <?php echo wp_json_encode( __( 'Request failed.', 'ajforms' ) ); ?>;
						});
				});
			})();
			</script>
			<?php
		}
	}
}
