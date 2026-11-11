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
			add_action( 'add_attachment', array( __CLASS__, 'flush_counts' ) );
			add_action( 'delete_attachment', array( __CLASS__, 'flush_counts' ) );
			add_action( 'save_post', array( __CLASS__, 'flush_counts' ) );
			add_filter( 'manage_media_columns', array( __CLASS__, 'add_columns' ) );
			add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		}

		public static function add_columns( $columns ) {
			$out = array();
			foreach ( $columns as $key => $label ) {
				if ( 'parent' === $key ) {
					$out['ajcore_used'] = __( 'Used in', 'ajforms' ); // Replaces "Uploaded to": that only records the upload-time parent.
					continue;
				}
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

		/** Posts using this file: parent, featured image, or referenced in content. Capped for speed. */
		private static function render_used_in( $post_id ) {
			global $wpdb;
			$ids  = array();
			$parent = (int) wp_get_post_parent_id( $post_id );
			if ( $parent ) {
				$ids[] = $parent;
			}
			$featured = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 10", $post_id ) );
			$ids      = array_merge( $ids, array_map( 'intval', $featured ) );

			$file = (string) get_post_meta( $post_id, '_wp_attached_file', true );
			$stem = $file ? pathinfo( basename( $file ), PATHINFO_FILENAME ) : '';
			$like = array( '%' . $wpdb->esc_like( 'wp-image-' . $post_id ) . '"%', '%' . $wpdb->esc_like( 'wp-image-' . $post_id ) . ' %' );
			$sql  = "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision','nav_menu_item') AND post_status NOT IN ('trash','auto-draft','inherit') AND (post_content LIKE %s OR post_content LIKE %s";
			$args = $like;
			if ( '' !== $stem && strlen( $stem ) > 5 ) {
				$sql   .= ' OR post_content LIKE %s';
				$args[] = '%' . $wpdb->esc_like( $stem ) . '%';
			}
			$sql .= ') LIMIT 10';
			$in_content = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids        = array_values( array_unique( array_merge( $ids, array_map( 'intval', $in_content ) ) ) );

			if ( ! $ids ) {
				echo '<span style="color:#787c82">' . esc_html__( 'Not used', 'ajforms' ) . '</span>';
				return;
			}
			$links = array();
			foreach ( array_slice( $ids, 0, 3 ) as $id ) {
				$title   = get_the_title( $id );
				$links[] = '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $title ? $title : '#' . $id ) . '</a>';
			}
			echo implode( '<br>', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( count( $ids ) > 3 ) {
				echo '<br><span style="color:#787c82">' . esc_html( sprintf( __( '+%d more', 'ajforms' ), count( $ids ) - 3 ) ) . '</span>';
			}
		}

		public static function render_column( $column, $post_id ) {
			if ( ! in_array( $column, array( 'ajcore_id', 'ajcore_type', 'ajcore_storage', 'ajcore_used' ), true ) ) {
				return;
			}
			$post_id = (int) $post_id;
			if ( 'ajcore_used' === $column ) {
				self::render_used_in( $post_id );
				return;
			}
			if ( 'ajcore_id' === $column ) {
				echo esc_html( (string) $post_id );
				return;
			}
			$record = class_exists( 'AJCore_Storage_Service' ) ? AJCore_Storage_Service::get_remote_record( $post_id ) : null;
			if ( 'ajcore_type' === $column ) {
				$labels = self::labels();
				$bucket = self::bucket_for( $post_id, $record );
				$colors = self::colors();
				echo '<span class="ajcore-badge" style="--c:' . esc_attr( $colors[ $bucket ] ) . '">' . esc_html( isset( $labels[ $bucket ] ) ? $labels[ $bucket ] : $bucket ) . '</span>';
				return;
			}
			if ( $record ) {
				echo '<span class="ajcore-badge" style="--c:#4f46e5">RustFS</span><br><code class="ajcore-path" style="--c:#4f46e5">' . esc_html( $record->bucket . '/' . ltrim( $record->object_key, '/' ) ) . '</code>';
			} else {
				echo '<span class="ajcore-badge" style="--c:#0d9488">' . esc_html__( 'Local (Media)', 'ajforms' ) . '</span>';
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

			// "In use" = has a parent, is a featured image, or is referenced in content (wp-image-<id> or file name).
			$used = "({$p}.post_parent > 0"
				. " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = {$p}.ID)"
				. " OR EXISTS (SELECT 1 FROM {$p} c WHERE c.post_type NOT IN ('attachment','revision','nav_menu_item') AND c.post_status NOT IN ('trash','auto-draft','inherit')"
				. " AND (c.post_content LIKE CONCAT('%wp-image-', {$p}.ID, '\"%') OR c.post_content LIKE CONCAT('%wp-image-', {$p}.ID, ' %')"
				. " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} af WHERE af.post_id = {$p}.ID AND af.meta_key = '_wp_attached_file'"
				. " AND CHAR_LENGTH(SUBSTRING_INDEX(SUBSTRING_INDEX(af.meta_value, '/', -1), '.', 1)) > 5"
				. " AND c.post_content LIKE CONCAT('%', SUBSTRING_INDEX(SUBSTRING_INDEX(af.meta_value, '/', -1), '.', 1), '%')))))";

			return array(
				'customer' => $customer,
				'attached' => "NOT {$customer} AND {$p}.post_parent > 0",
				'inuse'    => "NOT {$customer} AND {$used}",
				'unused'   => "NOT {$customer} AND NOT {$used}",
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
				'attached' => __( 'Attached', 'ajforms' ),
				'inuse'    => __( 'In use', 'ajforms' ),
				'unused'   => __( 'Unused', 'ajforms' ),
				'rustfs'   => __( 'In RustFS', 'ajforms' ),
				'mismatch' => __( 'Mapping mismatch', 'ajforms' ),
			);
		}

		private static function colors() {
			return array(
				'all'      => '#2271b1',
				'customer' => '#7c3aed',
				'images'   => '#059669',
				'docs'     => '#d97706',
				'av'       => '#db2777',
				'other'    => '#64748b',
				'attached' => '#0891b2',
				'inuse'    => '#16a34a',
				'unused'   => '#ea580c',
				'rustfs'   => '#4f46e5',
				'mismatch' => '#dc2626',
			);
		}

		private static function counts() {
			global $wpdb;
			$cached = get_transient( 'ajcore_media_tile_counts' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			$p    = $wpdb->posts;
			$cols = array( "COUNT(*) AS `all`" );
			foreach ( self::predicates() as $key => $sql ) {
				$cols[] = "SUM({$sql}) AS `{$key}`";
			}
			$row = $wpdb->get_row( "SELECT " . implode( ', ', $cols ) . " FROM {$p} WHERE post_type = 'attachment' AND post_status IN ('inherit','private')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$row = is_array( $row ) ? array_map( 'intval', $row ) : array();
			set_transient( 'ajcore_media_tile_counts', $row, 2 * MINUTE_IN_SECONDS );
			return $row;
		}

		public static function flush_counts() {
			delete_transient( 'ajcore_media_tile_counts' );
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
				.ajcore-media-tile{display:block;min-width:112px;padding:8px 14px;background:color-mix(in srgb,var(--c) 12%,#fff);border:1px solid color-mix(in srgb,var(--c) 35%,#fff);border-left:5px solid var(--c);border-radius:4px;text-decoration:none;color:#1d2327}
				.ajcore-media-tile:hover{background:color-mix(in srgb,var(--c) 22%,#fff)}
				.ajcore-media-tile.is-active{background:var(--c);border-color:var(--c)}
				.ajcore-media-tile.is-active b,.ajcore-media-tile.is-active span{color:#fff}
				.ajcore-media-tile b{display:block;font-size:22px;line-height:1.2;color:var(--c)}
				.ajcore-media-tile span{font-size:12px;font-weight:600;color:#3c434a}
				.ajcore-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;background:var(--c)}
				.ajcore-path{font-size:11px;overflow-wrap:anywhere;background:color-mix(in srgb,var(--c) 10%,#fff)}
				.column-ajcore_id{width:90px}.column-ajcore_type{width:120px}.column-ajcore_storage{width:26%}.column-ajcore_used{width:16%}
			</style>
			<div class="ajcore-media-tiles">
				<?php foreach ( $labels as $key => $label ) :
					$count  = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
					$active = ( 'all' === $key && '' === $current ) || $key === $current;
					if ( 0 === $count && ! $active && 'all' !== $key ) {
						continue; // Hide empty tiles.
					}
					$url    = 'all' === $key ? $base : add_query_arg( self::QUERY_VAR, $key, $base );
					$colors = self::colors();
					?>
					<a class="ajcore-media-tile<?php echo $active ? ' is-active' : ''; ?>" style="--c:<?php echo esc_attr( $colors[ $key ] ); ?>" href="<?php echo esc_url( $url ); ?>">
						<b><?php echo esc_html( number_format_i18n( $count ) ); ?></b>
						<span><?php echo esc_html( $label ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<script>
			(function () {
				if (window.ajcoreMediaDynamic) { return; }
				window.ajcoreMediaDynamic = true;
				var busy = false;
				function swap(url, push) {
					if (busy) { return; }
					busy = true;
					var list = document.getElementById('posts-filter');
					if (list) { list.style.opacity = '0.5'; }
					fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
						var doc = new DOMParser().parseFromString(html, 'text/html');
						['.ajcore-media-tiles', '#posts-filter'].forEach(function (sel) {
							var cur = document.querySelector(sel), next = doc.querySelector(sel);
							if (cur && next) { cur.replaceWith(next); }
						});
						if (push) { history.pushState({ ajcore: 1 }, '', url); }
					}).catch(function () { window.location.href = url; }).finally(function () { busy = false; var l = document.getElementById('posts-filter'); if (l) { l.style.opacity = ''; } });
				}
				document.addEventListener('click', function (e) {
					var a = e.target.closest ? e.target.closest('.ajcore-media-tile, .tablenav-pages a, .wp-list-table thead a, .wp-list-table tfoot a') : null;
					if (!a || !a.href || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') { return; }
					e.preventDefault();
					swap(a.href, true);
				});
				window.addEventListener('popstate', function () { swap(window.location.href, false); });
			})();
			</script>
			<?php
		}
	}
}
