<?php
/**
 * Single source of truth for "is this attachment a customer file?".
 *
 * A customer file is an attachment AJCore created for a customer (AJCore → Files, the portal, the
 * file API). It is flagged with postmeta `_ajcore_customer_file` at creation. The flag is the
 * explicit marker; table membership (aj_portal_files / aj_storage_objects) is read as well so
 * files created before the flag existed, or flagged later, are still recognised.
 *
 * Live owns these records. Sync tools must never send, overwrite or delete them. They can call
 * AJCore_Customer_Files::ids() or filter `ajcore_customer_file_ids`.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'AJCore_Customer_Files' ) ) {
	class AJCore_Customer_Files {

		const META = '_ajcore_customer_file';

		/** Flag an attachment as a customer file. Call right after the attachment is created. */
		public static function mark( $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id > 0 ) {
				update_post_meta( $attachment_id, self::META, '1' );
			}
		}

		public static function is_customer_file( $attachment_id ) {
			return in_array( (int) $attachment_id, self::ids(), true );
		}

		/**
		 * Attachment IDs AJCore owns on this site: flagged, or named by aj_portal_files or
		 * aj_storage_objects. Cached per request.
		 */
		public static function ids() {
			static $memo = null;
			if ( null !== $memo ) {
				return $memo;
			}
			global $wpdb;
			$ids   = array();
			$ids   = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ) );
			$files = $wpdb->prefix . 'aj_portal_files';
			$objs  = $wpdb->prefix . 'aj_storage_objects';
			if ( self::table_exists( $files ) ) {
				$ids = array_merge( $ids, (array) $wpdb->get_col( "SELECT attachment_id FROM `{$files}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( self::table_exists( $objs ) ) {
				$ids = array_merge( $ids, (array) $wpdb->get_col( "SELECT attachment_id FROM `{$objs}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$ids  = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
			$memo = array_map( 'intval', (array) apply_filters( 'ajcore_customer_file_ids', $ids ) );
			return $memo;
		}

		/**
		 * True when a post uses this attachment (featured image or wp-image-N in content). A portal
		 * row pointing at such an ID is a stale pointer to a site image, not a customer file.
		 */
		public static function is_used_by_post( $attachment_id ) {
			global $wpdb;
			$attachment_id = (int) $attachment_id;
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $attachment_id ) ) > 0 ) {
				return true;
			}
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_status NOT IN ('trash','auto-draft') AND (post_content LIKE %s OR post_content LIKE %s)",
				'%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '"%',
				'%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . ' %'
			) ) > 0;
		}

		/**
		 * Whether deleting a Files record may also delete its attachment and remote object.
		 * True only for a real attachment that no post uses. A record whose attachment is gone, was
		 * replaced by another post, or is now a site image (an ID reused by a sync) must delete the
		 * record only: removing the "attachment" would destroy an unrelated file, or the remote
		 * object that a Reattach could still recover.
		 */
		public static function owns_attachment( $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$post          = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				return false;
			}
			return ! self::is_used_by_post( $attachment_id );
		}

		/**
		 * One-time, per site: flag existing customer attachments (portal-file rows whose attachment
		 * exists and is not a site image in use by a post). Idempotent. Not synced: ajcore_ options
		 * are site-owned.
		 */
		public static function maybe_backfill() {
			if ( '1' === (string) get_option( 'ajcore_customer_flag_backfilled', '' ) || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			global $wpdb;
			$files = $wpdb->prefix . 'aj_portal_files';
			if ( ! self::table_exists( $files ) ) {
				return;
			}
			$rows = (array) $wpdb->get_col( "SELECT DISTINCT f.attachment_id FROM `{$files}` f INNER JOIN {$wpdb->posts} p ON p.ID = f.attachment_id AND p.post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $id ) {
				if ( ! self::is_used_by_post( (int) $id ) ) {
					self::mark( (int) $id );
				}
			}
			update_option( 'ajcore_customer_flag_backfilled', '1', false );
		}

		/**
		 * Dangling records, report only: storage mappings whose attachment is gone, and portal rows
		 * whose attachment is missing or is no longer an attachment. Never deletes anything.
		 */
		public static function orphans() {
			global $wpdb;
			$out   = array( 'mappings' => array(), 'portal_rows' => array() );
			$files = $wpdb->prefix . 'aj_portal_files';
			$objs  = $wpdb->prefix . 'aj_storage_objects';
			if ( self::table_exists( $objs ) ) {
				$out['mappings'] = array_map( 'intval', (array) $wpdb->get_col( "SELECT o.attachment_id FROM `{$objs}` o LEFT JOIN {$wpdb->posts} p ON p.ID = o.attachment_id AND p.post_type = 'attachment' WHERE p.ID IS NULL ORDER BY o.attachment_id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( self::table_exists( $files ) ) {
				$out['portal_rows'] = (array) $wpdb->get_results( "SELECT f.id, f.attachment_id, f.title FROM `{$files}` f LEFT JOIN {$wpdb->posts} p ON p.ID = f.attachment_id AND p.post_type = 'attachment' WHERE p.ID IS NULL ORDER BY f.attachment_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return $out;
		}

		/**
		 * Point a Files row whose attachment is gone (or was replaced by something else) at the file
		 * that still exists in remote storage. Creates a new customer attachment in the reserved ID
		 * range, then moves the storage mapping and the Files row to it. The object is not copied or
		 * renamed. Refuses unless the mapping exists and the object is really in the bucket.
		 *
		 * @return int|WP_Error New attachment ID.
		 */
		public static function reattach( $file_row_id ) {
			global $wpdb;
			if ( ! class_exists( 'AJCore_Storage_Service' ) ) {
				return new WP_Error( 'ajcore_reattach_unavailable', __( 'Remote storage is not available.', 'ajforms' ) );
			}
			$files = $wpdb->prefix . 'aj_portal_files';
			$objs  = $wpdb->prefix . 'aj_storage_objects';
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, attachment_id, title FROM `{$files}` WHERE id = %d", (int) $file_row_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				return new WP_Error( 'ajcore_reattach_no_row', __( 'File record not found.', 'ajforms' ) );
			}
			$old    = (int) $row->attachment_id;
			$post   = get_post( $old );
			$broken = ! $post || 'attachment' !== $post->post_type || self::is_used_by_post( $old );
			if ( ! $broken ) {
				return new WP_Error( 'ajcore_reattach_not_broken', __( 'This record already has its file.', 'ajforms' ) );
			}
			$record = AJCore_Storage_Service::get_remote_record( $old );
			if ( ! $record ) {
				return new WP_Error( 'ajcore_reattach_no_mapping', __( 'No remote storage mapping for this record, so there is nothing to reattach.', 'ajforms' ) );
			}
			$settings           = AJCore_Storage_Service::get_settings();
			$settings['bucket'] = $record->bucket;
			$client             = AJCore_Storage_Service::get_client( $settings );
			if ( ! $client ) {
				return new WP_Error( 'ajcore_reattach_no_client', __( 'Remote storage is not configured.', 'ajforms' ) );
			}
			$head = $client->head_object( $record->object_key );
			if ( is_wp_error( $head ) ) {
				return $head;
			}
			if ( ! $head ) {
				return new WP_Error( 'ajcore_reattach_no_object', __( 'The file is not in the bucket at the stored path.', 'ajforms' ) );
			}

			$mime   = $record->content_type ? $record->content_type : ( ! empty( $head['content_type'] ) ? $head['content_type'] : 'application/octet-stream' );
			$upload = wp_upload_dir();
			$new_id = wp_insert_attachment(
				array(
					'post_title'     => $row->title,
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_mime_type' => $mime,
					'import_id'      => AJCore_Storage_Service::next_customer_file_id(),
				),
				trailingslashit( $upload['basedir'] ) . 'ajcore-remote/' . basename( (string) $record->object_key ), // Never exists locally; the file is served from remote storage.
				0,
				true
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'ajcore_reattach_insert', __( 'Could not create the attachment.', 'ajforms' ) );
			}
			$new_id = (int) $new_id;

			$wpdb->query( 'START TRANSACTION' );
			$ok = false !== $wpdb->update( $objs, array( 'attachment_id' => $new_id ), array( 'attachment_id' => $old ), array( '%d' ), array( '%d' ) )
				&& false !== $wpdb->update( $files, array( 'attachment_id' => $new_id ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				// Direct delete: wp_delete_attachment() would also delete the remote object.
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $new_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->posts, array( 'ID' => $new_id ), array( '%d' ) );
				return new WP_Error( 'ajcore_reattach_db', __( 'Could not update the records; nothing was changed.', 'ajforms' ) );
			}
			$wpdb->query( 'COMMIT' );
			self::mark( $new_id );
			clean_post_cache( $new_id );
			return $new_id;
		}

		/**
		 * Objects in the bucket that could be this record's file. Ranked: file name matches the
		 * title and lives in a recipient's folder, then name match, then same-folder. Objects that
		 * another record already uses are left out.
		 *
		 * @return array|WP_Error list of array( key, size, modified, name_match, same_customer )
		 */
		public static function find_objects( $file_row_id, $search = '' ) {
			global $wpdb;
			if ( ! class_exists( 'AJCore_Storage_Service' ) ) {
				return new WP_Error( 'ajcore_find_unavailable', __( 'Remote storage is not available.', 'ajforms' ) );
			}
			$files = $wpdb->prefix . 'aj_portal_files';
			$users = $wpdb->prefix . 'aj_portal_file_users';
			$objs  = $wpdb->prefix . 'aj_storage_objects';
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, attachment_id, title FROM `{$files}` WHERE id = %d", (int) $file_row_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				return new WP_Error( 'ajcore_find_no_row', __( 'File record not found.', 'ajforms' ) );
			}
			$settings = AJCore_Storage_Service::get_settings();
			$client   = AJCore_Storage_Service::get_client( $settings );
			if ( ! $client ) {
				return new WP_Error( 'ajcore_find_no_client', __( 'Remote storage is not configured.', 'ajforms' ) );
			}
			$listing = $client->list_objects( '', 5000 );
			if ( is_wp_error( $listing ) ) {
				return $listing;
			}
			if ( ! $listing ) {
				return new WP_Error( 'ajcore_find_empty_listing', sprintf( /* translators: %s: bucket name */ __( 'The bucket listing for "%s" came back empty. Check the storage endpoint, bucket and credentials.', 'ajforms' ), $settings['bucket'] ) );
			}
			// An object is "taken" only when a mapping with a real attachment behind it uses it. Orphan mappings
			// (their attachment is gone) don't count: they are exactly the records being recovered.
			$taken = array_flip( (array) $wpdb->get_col( $wpdb->prepare( "SELECT o.object_key FROM `{$objs}` o INNER JOIN {$wpdb->posts} p ON p.ID = o.attachment_id AND p.post_type = 'attachment' WHERE o.bucket = %s", $settings['bucket'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Folder fragments for this record's recipients, matching AJCore's key layout.
			$fragments = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, user_email FROM `{$users}` WHERE file_id = %d LIMIT 200", (int) $row->id ) ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user = (int) $u->user_id ? get_userdata( (int) $u->user_id ) : ( $u->user_email ? get_user_by( 'email', $u->user_email ) : false );
				if ( $user ) {
					$fragments[] = '/user-' . (int) $user->ID . '-' . sanitize_title( $user->user_login ) . '/';
				}
				if ( $u->user_email ) {
					$fragments[] = '/customer-' . sanitize_title( $u->user_email ) . '/';
				}
			}
			$stem = function ( $name ) {
				$name = preg_replace( '/\.[A-Za-z0-9]{2,5}$/', '', (string) $name );
				$name = preg_replace( '/^\d+-/', '', $name );
				return sanitize_title( $name );
			};
			$want   = $stem( $row->title );
			$search = strtolower( trim( (string) $search ) );
			$out    = array();
			foreach ( $listing as $obj ) {
				$key = $obj['key'];
				if ( '/' === substr( $key, -1 ) || false !== strpos( $key, '/sizes/' ) || isset( $taken[ $key ] ) ) {
					continue;
				}
				$name_match = '' !== $want && $stem( basename( $key ) ) === $want;
				$same       = false;
				foreach ( $fragments as $frag ) {
					if ( false !== strpos( '/' . $key, $frag ) ) {
						$same = true;
						break;
					}
				}
				if ( '' !== $search ) {
					if ( false === strpos( strtolower( $key ), $search ) ) {
						continue;
					}
				} elseif ( ! $name_match && ! $same ) {
					continue;
				}
				$out[] = $obj + array( 'name_match' => $name_match, 'same_customer' => $same );
			}
			usort( $out, function ( $a, $b ) {
				$sa = ( $a['name_match'] ? 2 : 0 ) + ( $a['same_customer'] ? 1 : 0 );
				$sb = ( $b['name_match'] ? 2 : 0 ) + ( $b['same_customer'] ? 1 : 0 );
				return $sb <=> $sa;
			} );
			return array_slice( $out, 0, 25 );
		}

		/**
		 * Attach an object that already exists in the bucket to a Files record whose file is gone
		 * (or is a stale pointer to a site image). Creates a new customer attachment in the
		 * reserved range and records the mapping. The object is not copied or renamed.
		 *
		 * @return int|WP_Error New attachment ID.
		 */
		public static function attach_object( $file_row_id, $object_key ) {
			global $wpdb;
			if ( ! class_exists( 'AJCore_Storage_Service' ) ) {
				return new WP_Error( 'ajcore_attach_unavailable', __( 'Remote storage is not available.', 'ajforms' ) );
			}
			$object_key = ltrim( (string) $object_key, '/' );
			if ( '' === $object_key || false !== strpos( $object_key, '..' ) ) {
				return new WP_Error( 'ajcore_attach_bad_key', __( 'Invalid file path.', 'ajforms' ) );
			}
			$files = $wpdb->prefix . 'aj_portal_files';
			$objs  = $wpdb->prefix . 'aj_storage_objects';
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, attachment_id, title FROM `{$files}` WHERE id = %d", (int) $file_row_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				return new WP_Error( 'ajcore_attach_no_row', __( 'File record not found.', 'ajforms' ) );
			}
			$old = (int) $row->attachment_id;
			if ( self::owns_attachment( $old ) ) {
				return new WP_Error( 'ajcore_attach_not_broken', __( 'This record already has its file.', 'ajforms' ) );
			}
			$settings = AJCore_Storage_Service::get_settings();
			$client   = AJCore_Storage_Service::get_client( $settings );
			if ( ! $client ) {
				return new WP_Error( 'ajcore_attach_no_client', __( 'Remote storage is not configured.', 'ajforms' ) );
			}
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$objs}` o INNER JOIN {$wpdb->posts} p ON p.ID = o.attachment_id AND p.post_type = 'attachment' WHERE o.bucket = %s AND o.object_key = %s", $settings['bucket'], $object_key ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return new WP_Error( 'ajcore_attach_taken', __( 'Another record already uses this file.', 'ajforms' ) );
			}
			$head = $client->head_object( $object_key );
			if ( is_wp_error( $head ) ) {
				return $head;
			}
			if ( ! $head ) {
				return new WP_Error( 'ajcore_attach_no_object', __( 'That file is not in the bucket.', 'ajforms' ) );
			}

			$type = wp_check_filetype( basename( $object_key ) );
			$mime = ! empty( $type['type'] ) ? $type['type'] : ( ! empty( $head['content_type'] ) ? $head['content_type'] : 'application/octet-stream' );
			$upload = wp_upload_dir();
			$new_id = wp_insert_attachment(
				array(
					'post_title'     => $row->title,
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_mime_type' => $mime,
					'import_id'      => AJCore_Storage_Service::next_customer_file_id(),
				),
				trailingslashit( $upload['basedir'] ) . 'ajcore-remote/' . basename( $object_key ), // Never exists locally; served from remote storage.
				0,
				true
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'ajcore_attach_insert', __( 'Could not create the attachment.', 'ajforms' ) );
			}
			$new_id = (int) $new_id;

			$wpdb->query( 'START TRANSACTION' );
			// Drop any orphan mapping (its attachment is gone) that still names this object; the new record replaces it.
			$wpdb->query( $wpdb->prepare( "DELETE o FROM `{$objs}` o LEFT JOIN {$wpdb->posts} p ON p.ID = o.attachment_id AND p.post_type = 'attachment' WHERE p.ID IS NULL AND o.bucket = %s AND o.object_key = %s", $settings['bucket'], $object_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ok = AJCore_Storage_Service::record_existing_object( $new_id, $settings['bucket'], $object_key, (int) $head['content_length'], $mime )
				&& false !== $wpdb->update( $files, array( 'attachment_id' => $new_id ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				// Direct delete: wp_delete_attachment() would also delete the remote object.
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $new_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->posts, array( 'ID' => $new_id ), array( '%d' ) );
				return new WP_Error( 'ajcore_attach_db', __( 'Could not update the records; nothing was changed.', 'ajforms' ) );
			}
			$wpdb->query( 'COMMIT' );
			self::mark( $new_id );
			clean_post_cache( $new_id );
			return $new_id;
		}

		public static function ajax_find_objects() {
			if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ajcore_object_attach', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ajforms' ) ), 403 );
			}
			$result = self::find_objects( isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0, isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '' );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array( 'objects' => $result ) );
		}

		public static function ajax_attach_object() {
			if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ajcore_object_attach', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ajforms' ) ), 403 );
			}
			$result = self::attach_object( isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0, isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '' );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array( 'attachment_id' => (int) $result ) );
		}

		public static function ajax_reattach() {
			if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ajcore_reattach_file', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ajforms' ) ), 403 );
			}
			$result = self::reattach( isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0 );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array( 'attachment_id' => (int) $result ) );
		}

		private static function table_exists( $table ) {
			global $wpdb;
			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
	}

	add_action( 'admin_init', array( 'AJCore_Customer_Files', 'maybe_backfill' ) );
	add_action( 'wp_ajax_ajcore_reattach_file', array( 'AJCore_Customer_Files', 'ajax_reattach' ) );
	add_action( 'wp_ajax_ajcore_find_objects', array( 'AJCore_Customer_Files', 'ajax_find_objects' ) );
	add_action( 'wp_ajax_ajcore_attach_object', array( 'AJCore_Customer_Files', 'ajax_attach_object' ) );

	// Upload folders AJCore owns on each site: a sync must never copy these between sites.
	add_filter( 'synchy_live_owned_upload_dirs', function ( $dirs ) {
		return array_values( array_unique( array_merge( (array) $dirs, array( 'ajcore-backups', 'ajphone-rules' ) ) ) );
	} );
}
