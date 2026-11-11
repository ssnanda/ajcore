<?php
/**
 * Media Library dashboard: clickable bucket tiles with counts above the list table.
 *
 * Buckets (customer / images / documents / audio-video / other) are mutually exclusive
 * and sum to the library total. "In RustFS" is an overlay (any bucket that is offloaded).
 * Filtering uses the `ajcore_bucket` query var on upload.php (list mode).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'AJCore_Media_Dashboard' ) ) {
	class AJCore_Media_Dashboard {

		const QUERY_VAR = 'ajcore_bucket';

		public static function init() {
			add_action( 'admin_notices', array( __CLASS__, 'render' ) );
			add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
			add_filter( 'posts_where', array( __CLASS__, 'filter_where' ), 10, 2 );
			add_filter( 'manage_media_columns', array( __CLASS__, 'add_columns' ) );
			add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		}

		public static function add_columns( $columns ) {
			$out = array();
			foreach ( $columns as $key => $label ) {
				$out[ $key ] = $label;
				if ( 'title' === $key ) {
					$out['ajcore_id']      = __( 'ID', 'ajforms' );
					$out['ajcore_type']    = __( 'Type', 'ajforms' );
					$out['ajcore_storage'] = __( 'Stored in', 'ajforms' );
				}
			}
			return $out;
		}

		/** Bucket key for one attachment, using the same rules as the tiles. */
		private static function bucket_for( $post_id, $record ) {
			global $wpdb;
			$files = $wpdb->prefix . 'aj_portal_files';
			$in_portal = self::table_exists( $files ) && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$files}` WHERE attachment_id = %d", $post_id ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$key       = $record ? (string) $record->object_key : '';
			$by_path   = $key && ( false !== strpos( $key, '/user-' ) || false !== strpos( $key, '/customer-' ) || 0 === strpos( $key, 'customer-' ) || false !== strpos( $key, '/unassigned/' ) );
			if ( $in_portal || $by_path ) {
				return 'customer';
			}
			$mime = (string) get_post_mime_type( $post_id );
			if ( 0 === strpos( $mime, 'image/' ) ) {
				return 'images';
			}
			if ( 0 === strpos( $mime, 'video/' ) || 0 === strpos( $mime, 'audio/' ) ) {
				return 'av';
			}
			if ( 'application/pdf' === $mime || 0 === strpos( $mime, 'application/msword' ) || 0 === strpos( $mime, 'application/vnd.' ) || 0 === strpos( $mime, 'text/' ) ) {
				return 'docs';
			}
			return 'other';
		}

		public static function render_column( $column, $post_id ) {
			if ( ! in_array( $column, array( 'ajcore_id', 'ajcore_type', 'ajcore_storage' ), true ) ) {
				return;
			}
			$post_id = (int) $post_id;
			if ( 'ajcore_id' === $column ) {
				echo esc_html( (string) $post_id );
				return;
			}
			$record = class_exists( 'AJCore_Storage_Service' ) ? AJCore_Storage_Service::get_remote_record( $post_id ) : null;
			if ( 'ajcore_type' === $column ) {
				$labels = self::labels();
				$bucket = self::bucket_for( $post_id, $record );
				echo esc_html( isset( $labels[ $bucket ] ) ? $labels[ $bucket ] : $bucket );
				return;
			}
			if ( $record ) {
				echo '<strong>RustFS</strong><br><code style="font-size:11px;overflow-wrap:anywhere">' . esc_html( $record->bucket . '/' . ltrim( $record->object_key, '/' ) ) . '</code>';
			} else {
				echo esc_html__( 'Local (Media)', 'ajforms' );
			}
		}

		private static function table_exists( $table ) {
			global $wpdb;
			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}

		/** SQL predicates per bucket, against the `posts` table. */
		private static function predicates() {
			global $wpdb;
			$files = $wpdb->prefix . 'aj_portal_files';
			$objs  = $wpdb->prefix . 'aj_storage_objects';
			$p     = $wpdb->posts;

			// Customer file = a portal-files row, or an offloaded file stored under a customer path
			// (user-<id>-…, customer-…, unassigned) even when no portal-files row exists.
			$by_portal = self::table_exists( $files )
				? "EXISTS (SELECT 1 FROM `{$files}` f WHERE f.attachment_id = {$p}.ID)"
				: '0=1';
			$by_path   = self::table_exists( $objs )
				? "EXISTS (SELECT 1 FROM `{$objs}` c WHERE c.attachment_id = {$p}.ID AND (c.object_key LIKE '%/user-%' OR c.object_key LIKE '%/customer-%' OR c.object_key LIKE 'customer-%' OR c.object_key LIKE '%/unassigned/%'))"
				: '0=1';
			$customer = "({$by_portal} OR {$by_path})";
			$remote   = self::table_exists( $objs )
				? "EXISTS (SELECT 1 FROM `{$objs}` o WHERE o.attachment_id = {$p}.ID)"
				: '0=1';

			$mismatch = self::table_exists( $objs )
				? "EXISTS (SELECT 1 FROM `{$objs}` o WHERE o.attachment_id = {$p}.ID AND o.content_type <> '' AND o.content_type <> {$p}.post_mime_type)"
				: '0=1';

			$doc = "({$p}.post_mime_type = 'application/pdf' OR {$p}.post_mime_type LIKE 'application/msword%' OR {$p}.post_mime_type LIKE 'application/vnd.%' OR {$p}.post_mime_type LIKE 'text/%')";

			return array(
				'customer' => $customer,
				'images'   => "NOT {$customer} AND {$p}.post_mime_type LIKE 'image/%'",
				'docs'     => "NOT {$customer} AND {$doc}",
				'av'       => "NOT {$customer} AND ({$p}.post_mime_type LIKE 'video/%' OR {$p}.post_mime_type LIKE 'audio/%')",
				'other'    => "NOT {$customer} AND {$p}.post_mime_type NOT LIKE 'image/%' AND {$p}.post_mime_type NOT LIKE 'video/%' AND {$p}.post_mime_type NOT LIKE 'audio/%' AND NOT {$doc}",
				'rustfs'   => $remote,
				'mismatch' => $mismatch,
			);
		}

		private static function labels() {
			return array(
				'all'      => __( 'All', 'ajforms' ),
				'customer' => __( 'Customer files', 'ajforms' ),
				'images'   => __( 'Site images', 'ajforms' ),
				'docs'     => __( 'Documents', 'ajforms' ),
				'av'       => __( 'Audio / video', 'ajforms' ),
				'other'    => __( 'Other', 'ajforms' ),
				'rustfs'   => __( 'In RustFS', 'ajforms' ),
				'mismatch' => __( 'Mapping mismatch', 'ajforms' ),
			);
		}

		private static function counts() {
			global $wpdb;
			$p    = $wpdb->posts;
			$cols = array( "COUNT(*) AS `all`" );
			foreach ( self::predicates() as $key => $sql ) {
				$cols[] = "SUM({$sql}) AS `{$key}`";
			}
			$row = $wpdb->get_row( "SELECT " . implode( ', ', $cols ) . " FROM {$p} WHERE post_type = 'attachment' AND post_status IN ('inherit','private')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return is_array( $row ) ? array_map( 'intval', $row ) : array();
		}

		private static function is_media_list_screen() {
			global $pagenow;
			return is_admin() && 'upload.php' === $pagenow;
		}

		private static function current_bucket() {
			$bucket = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array_key_exists( $bucket, self::predicates() ) ? $bucket : '';
		}

		public static function filter_query( $query ) {
			if ( ! self::is_media_list_screen() || ! $query->is_main_query() || '' === self::current_bucket() ) {
				return;
			}
			$query->set( 'ajcore_bucket_active', self::current_bucket() );
		}

		public static function filter_where( $where, $query ) {
			$bucket = $query->get( 'ajcore_bucket_active' );
			if ( $bucket ) {
				$predicates = self::predicates();
				if ( isset( $predicates[ $bucket ] ) ) {
					$where .= ' AND (' . $predicates[ $bucket ] . ')';
				}
			}
			return $where;
		}

		public static function render() {
			if ( ! self::is_media_list_screen() || ! current_user_can( 'upload_files' ) ) {
				return;
			}
			$counts  = self::counts();
			$labels  = self::labels();
			$current = self::current_bucket();
			$base    = add_query_arg( 'mode', 'list', admin_url( 'upload.php' ) );
			?>
			<style>
				.ajcore-media-tiles{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 4px}
				.ajcore-media-tile{display:block;min-width:112px;padding:8px 12px;background:#fff;border:1px solid #c3c4c7;border-left:3px solid #2271b1;border-radius:3px;text-decoration:none;color:#1d2327}
				.ajcore-media-tile:hover{border-color:#2271b1}
				.ajcore-media-tile.is-active{background:#f0f6fc;border-color:#2271b1}
				.ajcore-media-tile b{display:block;font-size:20px;line-height:1.2}
				.ajcore-media-tile span{font-size:12px;color:#50575e}
				.column-ajcore_id{width:90px}.column-ajcore_type{width:110px}.column-ajcore_storage{width:26%}
				.ajcore-media-tile.is-warn{border-left-color:#d63638;background:#fcf0f1}
				.ajcore-media-tile.is-overlay{border-left-color:#8c8f94;border-style:dashed}
			</style>
			<div class="ajcore-media-tiles">
				<?php foreach ( $labels as $key => $label ) :
					$url    = 'all' === $key ? $base : add_query_arg( self::QUERY_VAR, $key, $base );
					$active = ( 'all' === $key && '' === $current ) || $key === $current;
					$class  = 'ajcore-media-tile' . ( $active ? ' is-active' : '' ) . ( in_array( $key, array( 'rustfs', 'mismatch' ), true ) ? ' is-overlay' : '' ) . ( 'mismatch' === $key && ! empty( $counts['mismatch'] ) ? ' is-warn' : '' );
					?>
					<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>">
						<b><?php echo esc_html( number_format_i18n( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) ); ?></b>
						<span><?php echo esc_html( $label ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php
		}
	}
}
