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

		private static function table_exists( $table ) {
			global $wpdb;
			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
	}

	add_action( 'admin_init', array( 'AJCore_Customer_Files', 'maybe_backfill' ) );

	// Upload folders AJCore owns on each site: a sync must never copy these between sites.
	add_filter( 'synchy_live_owned_upload_dirs', function ( $dirs ) {
		return array_values( array_unique( array_merge( (array) $dirs, array( 'ajcore-backups', 'ajphone-rules' ) ) ) );
	} );
}
