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
		const SECRET_PLACEHOLDER = '••••••••••••••••';

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
				'enabled'     => '0',
				'provider'    => 'rustfs',
				'endpoint'    => '',
				'console_url' => '',
				'region'      => 'us-east-1',
				'bucket'      => '',
				'access_key'  => '',
				'secret_key'  => '',
				'path_style'  => '1',
			);
		}

		/**
		 * Per-provider label, endpoint placeholder/hint, default path-style, and
		 * step-by-step instructions for obtaining an access key/secret key —
		 * shown in the settings screen based on the selected provider. The S3
		 * client itself needs none of this; SigV4 is identical across providers,
		 * only the console/steps to get credentials differ.
		 */
		public static function get_provider_definitions() {
			return array(
				'rustfs' => array(
					'label'                => __( 'RustFS', 'ajforms' ),
					'endpoint_hint'        => __( 'RustFS speaks the S3 API the same way MinIO does — use its S3 API host here, not the console host below.', 'ajforms' ),
					'endpoint_placeholder' => 'https://files.example.com',
					'default_path_style'   => true,
					'console_hint'         => __( 'The RustFS web console — a different host from the S3 API endpoint above (e.g. console.files.example.com). Fill this in to get one-click links to bucket creation and access keys below.', 'ajforms' ),
					'console_placeholder'  => 'https://console.files.example.com',
					'console_paths'        => array(
						'buckets'     => '/rustfs/console/browser/',
						'access_keys' => '/rustfs/console/access-keys/',
					),
					'instructions'         => array(
						__( 'Open the RustFS console → Access Keys (link above once you fill in the Console URL).', 'ajforms' ),
						__( 'Click "Create Access Key".', 'ajforms' ),
						__( 'A "read-write" key scoped to the bucket you created is all this plugin needs — it only ever does GetObject/PutObject/DeleteObject/ListBucket, never admin-level actions.', 'ajforms' ),
						__( 'Copy the Access Key and Secret Key now — the secret is typically only shown once.', 'ajforms' ),
					),
					'bucket_options_help'  => array(
						__( 'Versioning: leave off for this plugin. It writes each file to a unique path and never overwrites or deletes it (deleting the WordPress attachment does not yet delete the remote copy), so version history would not currently protect anything — it would only add storage cost. Turn it on later if you add that behavior.', 'ajforms' ),
						__( 'Object Lock: only worth it if you need WORM-style retention for compliance documents that must not be deleted or altered for a fixed period. It can only be set at bucket-creation time (not added later) and requires Versioning to be on too. The plugin does not set or manage retention periods itself — you would configure a default retention rule directly in RustFS.', 'ajforms' ),
						__( 'Quota: optional soft ceiling to catch runaway growth (e.g. 10 GiB is generous for scanned documents). Not required for a small document library.', 'ajforms' ),
					),
				),
				'minio'  => array(
					'label'                => __( 'MinIO', 'ajforms' ),
					'endpoint_hint'        => __( 'The S3 API endpoint, not the web console — these are usually different hosts/ports (e.g. files.example.com for the API vs. console.files.example.com for the console).', 'ajforms' ),
					'endpoint_placeholder' => 'https://files.example.com',
					'default_path_style'   => true,
					'console_hint'         => __( 'The MinIO web console. Recent Community Edition builds have dropped the self-service Access Keys screen from this console — if you don\'t see one, you\'ll need the mc CLI instead (see instructions below).', 'ajforms' ),
					'console_placeholder'  => 'https://console.files.example.com',
					'console_paths'        => array(
						'buckets' => '/buckets',
					),
					'instructions'         => array(
						__( 'Log into your MinIO Console (link above once you fill in the Console URL).', 'ajforms' ),
						__( 'Go to "Access Keys" (older versions: "Identity" → "Users") — if that screen is missing, your build has dropped it; use the mc CLI (`mc admin user add` / `mc admin accesskey create`) instead.', 'ajforms' ),
						__( 'Click "Create Access Key".', 'ajforms' ),
						__( 'Optionally attach a policy restricting it to a single bucket — recommended over using your root credentials directly.', 'ajforms' ),
						__( 'Copy the Access Key and Secret Key now — the secret is only ever shown once.', 'ajforms' ),
					),
					'bucket_options_help'  => array(
						__( 'Versioning: leave off unless you specifically want protection against accidental overwrite/delete — this plugin doesn\'t overwrite or delete remote copies today, so it wouldn\'t benefit from it yet.', 'ajforms' ),
						__( 'Object Lock: only relevant for compliance/WORM retention requirements; must be chosen at bucket creation and requires Versioning too.', 'ajforms' ),
						__( 'Quota: optional soft ceiling on bucket size; not required for a small document library.', 'ajforms' ),
					),
				),
				'aws_s3' => array(
					'label'                => __( 'AWS S3', 'ajforms' ),
					'endpoint_hint'        => __( 'Use your bucket\'s regional endpoint, e.g. https://s3.us-east-1.amazonaws.com — and make sure Region below matches the region the bucket was actually created in.', 'ajforms' ),
					'endpoint_placeholder' => 'https://s3.us-east-1.amazonaws.com',
					'default_path_style'   => false,
					'console_hint'         => __( 'AWS has one fixed console (not self-hosted) — leave this blank, or use https://console.aws.amazon.com/s3/ for a generic bucket-list link.', 'ajforms' ),
					'console_placeholder'  => 'https://console.aws.amazon.com',
					'console_paths'        => array(),
					'instructions'         => array(
						__( 'Open the AWS IAM Console → Users, and select (or create) the user this plugin should act as.', 'ajforms' ),
						__( 'Open the "Security credentials" tab for that user.', 'ajforms' ),
						__( 'Click "Create access key", choose "Application running outside AWS" (or similar) as the use case.', 'ajforms' ),
						__( 'Copy the Access Key ID and Secret Access Key now — the secret is only ever shown once.', 'ajforms' ),
						__( 'Attach a bucket-scoped IAM policy to that user rather than using broad account credentials.', 'ajforms' ),
					),
					'bucket_options_help'  => array(
						__( 'Versioning: leave off unless you specifically want overwrite/delete protection — this plugin doesn\'t need it today.', 'ajforms' ),
						__( 'Object Lock: only for compliance/WORM retention; must be enabled at bucket creation and requires Versioning too.', 'ajforms' ),
						__( 'S3 has no native per-bucket storage quota — use an AWS Budget/CloudWatch alarm instead if you want a cost ceiling.', 'ajforms' ),
					),
				),
				'other'  => array(
					'label'                => __( 'Other S3-compatible', 'ajforms' ),
					'endpoint_hint'        => __( 'Use the S3 API endpoint documented by your provider.', 'ajforms' ),
					'endpoint_placeholder' => 'https://s3.example.com',
					'default_path_style'   => true,
					'console_hint'         => __( 'Your provider\'s web console, if it has one — fill this in for a quick link below.', 'ajforms' ),
					'console_placeholder'  => 'https://console.example.com',
					'console_paths'        => array(),
					'instructions'         => array(
						__( 'Check your provider\'s documentation for an "Access Keys" or "API Keys" section, usually under account or security settings.', 'ajforms' ),
						__( 'Create a key scoped to a single bucket if that option exists.', 'ajforms' ),
						__( 'Copy the Access Key and Secret Key now — most providers only show the secret once.', 'ajforms' ),
					),
					'bucket_options_help'  => array(
						__( 'Versioning: leave off unless you specifically want overwrite/delete protection — this plugin doesn\'t need it today.', 'ajforms' ),
						__( 'Object Lock: only for compliance/WORM retention requirements; usually must be enabled at bucket creation.', 'ajforms' ),
						__( 'Quota: optional soft ceiling on bucket size, if your provider supports one.', 'ajforms' ),
					),
				),
			);
		}

		public static function get_settings() {
			$saved = get_option( self::OPTION_KEY, array() );
			$saved = is_array( $saved ) ? $saved : array();
			return wp_parse_args( $saved, self::get_default_settings() );
		}

		public static function update_settings( $settings ) {
			$existing = self::get_settings();
			$defaults = self::get_default_settings();
			$clean    = array();
			foreach ( $defaults as $key => $default ) {
				$clean[ $key ] = isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
			}
			$clean['enabled']    = ! empty( $settings['enabled'] ) ? '1' : '0';
			$clean['path_style'] = ! empty( $settings['path_style'] ) ? '1' : '0';
			if ( ! array_key_exists( $clean['provider'], self::get_provider_definitions() ) ) {
				$clean['provider'] = 'rustfs';
			}

			// Preserve the existing secret if the settings form submitted a masked
			// placeholder (the settings screen never round-trips the real secret back
			// into the page source).
			if ( isset( $settings['secret_key'] ) && self::is_secret_placeholder( $settings['secret_key'], $existing['secret_key'] ) ) {
				$clean['secret_key'] = $existing['secret_key'];
			}

			update_option( self::OPTION_KEY, $clean );
			return $clean;
		}

		private static function is_secret_placeholder( $submitted, $existing ) {
			$submitted = (string) $submitted;
			$existing  = (string) $existing;

			if ( self::SECRET_PLACEHOLDER === $submitted ) {
				return true;
			}

			// Accept the previous prefix/suffix mask for forms opened before this fix.
			return '' !== $existing
				&& function_exists( 'ajcore_mask_secret_for_display' )
				&& ajcore_mask_secret_for_display( $existing ) === $submitted;
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
				'enabled'     => isset( $_POST['enabled'] ) ? '1' : '0',
				'provider'    => isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'rustfs',
				'endpoint'    => isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '',
				'console_url' => isset( $_POST['console_url'] ) ? esc_url_raw( wp_unslash( $_POST['console_url'] ) ) : '',
				'region'      => isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'us-east-1',
				'bucket'      => isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '',
				'access_key'  => isset( $_POST['access_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['access_key'] ) ) ) : '',
				'secret_key'  => isset( $_POST['secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) ) : '',
				'path_style'  => isset( $_POST['path_style'] ) ? '1' : '0',
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
				'region'     => isset( $_POST['region'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['region'] ) ) ) : 'us-east-1',
				'access_key' => isset( $_POST['access_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['access_key'] ) ) ) : '',
				'secret_key' => isset( $_POST['secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) ) : '',
				'bucket'     => '',
				'path_style' => isset( $_POST['path_style'] ) ? '1' : '0',
			);

			$existing = self::get_settings();
			if ( self::is_secret_placeholder( $settings['secret_key'], $existing['secret_key'] ) ) {
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
			$masked_secret = '' !== $settings['secret_key'] ? self::SECRET_PLACEHOLDER : '';
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
							<th scope="row"><label for="ajcore_storage_provider"><?php esc_html_e( 'Provider', 'ajforms' ); ?></label></th>
							<td>
								<select id="ajcore_storage_provider" name="provider">
									<?php foreach ( self::get_provider_definitions() as $provider_key => $provider ) : ?>
										<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $settings['provider'], $provider_key ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( "Only changes the instructions and defaults shown below — the actual connection works identically across providers, so switching later is just a config change.", 'ajforms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_endpoint"><?php esc_html_e( 'Endpoint URL', 'ajforms' ); ?></label></th>
							<td>
								<input type="url" id="ajcore_storage_endpoint" name="endpoint" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" class="regular-text" placeholder="https://files.example.com" />
								<?php foreach ( self::get_provider_definitions() as $provider_key => $provider ) : ?>
									<p class="description ajcore-storage-provider-endpoint-hint" data-provider="<?php echo esc_attr( $provider_key ); ?>" style="<?php echo $settings['provider'] === $provider_key ? '' : 'display:none;'; ?>"><?php echo esc_html( $provider['endpoint_hint'] ); ?></p>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_console_url"><?php esc_html_e( 'Console URL', 'ajforms' ); ?></label></th>
							<td>
								<input type="url" id="ajcore_storage_console_url" name="console_url" value="<?php echo esc_attr( $settings['console_url'] ); ?>" class="regular-text" placeholder="https://console.files.example.com" />
								<p id="ajcore_storage_console_links" class="ajforms-settings-inline-actions" style="margin-top:8px;"></p>
							</td>
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
							<th scope="row"><?php esc_html_e( 'Test Connection', 'ajforms' ); ?></th>
							<td>
								<button type="button" class="button" id="ajcore_storage_test_connection"><?php esc_html_e( 'Test Connection', 'ajforms' ); ?></button>
								<p class="description" id="ajcore_storage_test_status"><?php esc_html_e( 'Checks the endpoint/access key/secret key above before you bother filling in a bucket.', 'ajforms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ajcore_storage_bucket"><?php esc_html_e( 'Bucket', 'ajforms' ); ?></label></th>
							<td>
								<input type="text" id="ajcore_storage_bucket" name="bucket" value="<?php echo esc_attr( $settings['bucket'] ); ?>" class="regular-text" list="ajcore_storage_bucket_options" placeholder="my-bucket-name" />
								<datalist id="ajcore_storage_bucket_options"></datalist>
								<button type="button" class="button" id="ajcore_storage_list_buckets"><?php esc_html_e( 'List Buckets', 'ajforms' ); ?></button>
								<p class="description" id="ajcore_storage_bucket_status"><?php esc_html_e( 'Type the exact bucket name, or click List Buckets for suggestions.', 'ajforms' ); ?></p>
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
				var providerDefaults = <?php echo wp_json_encode( array_map( function ( $p ) {
					return array(
						'path_style'          => $p['default_path_style'],
						'placeholder'         => $p['endpoint_placeholder'],
						'console_placeholder' => $p['console_placeholder'],
						'console_paths'       => $p['console_paths'],
					);
				}, self::get_provider_definitions() ) ); ?>;
				var linkLabels = {
					buckets: <?php echo wp_json_encode( __( 'Open Bucket Browser (create bucket here) →', 'ajforms' ) ); ?>,
					access_keys: <?php echo wp_json_encode( __( 'Open Access Keys →', 'ajforms' ) ); ?>
				};

				function currentProvider() {
					return document.getElementById('ajcore_storage_provider').value;
				}

				function renderConsoleLinks() {
					var container = document.getElementById('ajcore_storage_console_links');
					var consoleUrl = document.getElementById('ajcore_storage_console_url').value.replace(/\/+$/, '');
					var defaults = providerDefaults[currentProvider()];
					container.innerHTML = '';
					if (!consoleUrl || !defaults || !defaults.console_paths) {
						return;
					}
					Object.keys(defaults.console_paths).forEach(function (key) {
						var a = document.createElement('a');
						a.href = consoleUrl + defaults.console_paths[key];
						a.target = '_blank';
						a.rel = 'noopener noreferrer';
						a.className = 'button button-secondary';
						a.textContent = linkLabels[key] || key;
						container.appendChild(a);
					});
				}

				document.getElementById('ajcore_storage_provider').addEventListener('change', function () {
					var provider = this.value;
					var defaults = providerDefaults[provider];
					if (defaults) {
						document.getElementById('ajcore_storage_endpoint').placeholder = defaults.placeholder;
						document.getElementById('ajcore_storage_console_url').placeholder = defaults.console_placeholder;
						document.querySelector('input[name="path_style"]').checked = defaults.path_style;
					}
					renderConsoleLinks();
				});

				document.getElementById('ajcore_storage_console_url').addEventListener('input', renderConsoleLinks);
				renderConsoleLinks();

				function fetchBuckets() {
					var body = new URLSearchParams({
						action: 'ajcore_storage_list_buckets',
						nonce: nonce,
						endpoint: document.getElementById('ajcore_storage_endpoint').value,
						access_key: document.getElementById('ajcore_storage_access_key').value,
						secret_key: document.getElementById('ajcore_storage_secret_key').value,
						path_style: document.querySelector('input[name="path_style"]').checked ? '1' : ''
					});
					return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
				}

				document.getElementById('ajcore_storage_test_connection').addEventListener('click', function () {
					var status = document.getElementById('ajcore_storage_test_status');
					status.textContent = <?php echo wp_json_encode( __( 'Testing…', 'ajforms' ) ); ?>;
					fetchBuckets()
						.then(function (res) {
							status.textContent = res.success
								? <?php echo wp_json_encode( __( '✅ Connected — endpoint and credentials work.', 'ajforms' ) ); ?>
								: ( '❌ ' + ((res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Connection failed.', 'ajforms' ) ); ?>) );
						})
						.catch(function () {
							status.textContent = <?php echo wp_json_encode( __( 'Request failed.', 'ajforms' ) ); ?>;
						});
				});

				document.getElementById('ajcore_storage_list_buckets').addEventListener('click', function () {
					var status = document.getElementById('ajcore_storage_bucket_status');
					status.textContent = <?php echo wp_json_encode( __( 'Loading…', 'ajforms' ) ); ?>;
					fetchBuckets()
						.then(function (res) {
							if (!res.success) {
								status.textContent = (res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Failed to list buckets.', 'ajforms' ) ); ?>;
								return;
							}
							var datalist = document.getElementById('ajcore_storage_bucket_options');
							var bucketInput = document.getElementById('ajcore_storage_bucket');
							datalist.innerHTML = '';
							res.data.buckets.forEach(function (name) {
								var opt = document.createElement('option');
								opt.value = name;
								datalist.appendChild(opt);
							});
							if (!bucketInput.value && res.data.buckets.length) {
								bucketInput.value = res.data.buckets[0];
							}
							status.textContent = res.data.buckets.length
								? <?php echo wp_json_encode( __( 'Suggestions loaded — pick one or keep what you typed, then save.', 'ajforms' ) ); ?>
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
