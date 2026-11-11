<?php
/**
 * Media Library dashboard: clickable bucket tiles with counts above the list table.
 *
 * Buckets (customer / images / documents / audio-video / other) are mutually exclusive
 * and sum to the library total. "In RustFS" is an overlay (any bucket that is offloaded).
 * Filtering uses the `ajcore_bucket` query var on upload.php (list mode).
 *
 * "In use" / "Unused" and the "Used in" column come from one shared reference map
 * (see build_ref_map()), so a file is never labelled Unused while the column could show where it
 * is used. References checked: upload parent, featured image, post content (wp-image-N, block ids,
 * galleries, upload URLs incl. resized copies), post meta (SEO/social images etc.), theme settings,
 * widgets, site icon/logo and other options, and term meta.
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
			add_action( 'deleted_post', array( __CLASS__, 'flush_counts' ) );
			add_action( 'added_post_meta', array( __CLASS__, 'flush_counts' ) );
			add_action( 'updated_post_meta', array( __CLASS__, 'flush_counts' ) );
			add_action( 'deleted_post_meta', array( __CLASS__, 'flush_counts' ) );
			add_action( 'edited_term', array( __CLASS__, 'flush_counts' ) );
			add_action( 'added_option', array( __CLASS__, 'flush_for_option' ) );
			add_action( 'updated_option', array( __CLASS__, 'flush_for_option' ) );
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

		/**
		 * "Used in" column: every place this file is used, each as a link to where you can see or
		 * change it. Backed by the same reference map as the In use / Unused tiles.
		 */
		private static function render_used_in( $post_id ) {
			$refs = self::ref_map();
			$refs = isset( $refs[ $post_id ] ) ? $refs[ $post_id ] : array();
			if ( ! $refs ) {
				echo '<span style="color:#787c82" title="' . esc_attr__( 'No reference found in posts, post meta, theme settings, widgets, options or term meta.', 'ajforms' ) . '">' . esc_html__( 'Not used', 'ajforms' ) . '</span>';
				return;
			}
			$line = function ( $ref ) {
				$text = '<strong>' . esc_html( $ref['kind'] ) . '</strong> ';
				$text .= $ref['url'] ? '<a href="' . esc_url( $ref['url'] ) . '">' . esc_html( $ref['label'] ) . '</a>' : esc_html( $ref['label'] );
				if ( '' !== $ref['detail'] ) {
					$text .= ' <span style="color:#787c82">(' . esc_html( $ref['detail'] ) . ')</span>';
				}
				return '<div style="margin-bottom:3px">' . $text . '</div>';
			};
			foreach ( array_slice( $refs, 0, 3 ) as $ref ) {
				echo $line( $ref ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( count( $refs ) > 3 ) {
				echo '<details><summary style="cursor:pointer;color:#2271b1">' . esc_html( sprintf( /* translators: %d: number of further places */ __( '+%d more', 'ajforms' ), count( $refs ) - 3 ) ) . '</summary>';
				foreach ( array_slice( $refs, 3, 40 ) as $ref ) {
					echo $line( $ref ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</details>';
			}
		}

		// ------------------------------------------------------------------
		// Reference map: attachment ID => list of places it is used.
		// ------------------------------------------------------------------

		/** Cached for 10 minutes; flushed on post/meta/term/theme-setting changes. */
		private static function ref_map() {
			static $memo = null;
			if ( null !== $memo ) {
				return $memo;
			}
			$cached = get_transient( 'ajcore_media_refs' );
			if ( is_array( $cached ) ) {
				$memo = $cached;
				return $memo;
			}
			$memo = self::build_ref_map();
			set_transient( 'ajcore_media_refs', $memo, 10 * MINUTE_IN_SECONDS );
			return $memo;
		}

		/** Pull attachment IDs and upload paths out of free text (content, meta, option strings). */
		private static function extract_refs( $text ) {
			$ids   = array();
			$paths = array();
			if ( ! is_string( $text ) || '' === $text ) {
				return array( $ids, $paths );
			}
			$text = str_replace( '\\/', '/', $text ); // Block attributes store URLs JSON-escaped.
			if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
				$ids = array_merge( $ids, $m[1] );
			}
			if ( preg_match_all( '/"(?:id|mediaId|imageId|attachmentId|backgroundImageId|featuredImageId|logoId|posterId)"\s*:\s*(\d+)/', $text, $m ) ) {
				$ids = array_merge( $ids, $m[1] );
			}
			if ( preg_match_all( '/\bids="([\d,\s]+)"/', $text, $m ) ) {
				foreach ( $m[1] as $list ) {
					$ids = array_merge( $ids, preg_split( '/[^\d]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) );
				}
			}
			if ( preg_match_all( '/"ids"\s*:\s*\[([\d,\s"]+)\]/', $text, $m ) ) {
				foreach ( $m[1] as $list ) {
					$ids = array_merge( $ids, preg_split( '/[^\d]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) );
				}
			}
			if ( preg_match_all( '#uploads/([^\s"\'<>\)\]\\\\?\#]+)#i', $text, $m ) ) {
				foreach ( $m[1] as $path ) {
					$paths[] = strtolower( rawurldecode( $path ) );
				}
			}
			return array( array_map( 'intval', array_unique( $ids ) ), array_unique( $paths ) );
		}

		/** Meta / option keys that plausibly hold a media ID (but not sizes, colours, positions...). */
		private static function image_like_key( $key ) {
			return (bool) preg_match( '/image|img|logo|icon|thumb|banner|bg|background|cover|photo|avatar|media|gallery|attachment|social|og_|hero|poster/i', (string) $key )
				&& ! preg_match( '/size|width|height|ratio|position|repeat|opacity|color|type|_url$/i', (string) $key );
		}

		private static function build_ref_map() {
			global $wpdb;
			$p     = $wpdb->posts;
			$refs  = array();
			$cache = array();

			// Attachments + every on-disk path (original, resized copies) => attachment ID.
			$paths   = array();
			$att_ids = array();
			$atts    = $wpdb->get_results( "SELECT ID, post_parent FROM {$p} WHERE post_type = 'attachment'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $atts as $att ) {
				$id             = (int) $att['ID'];
				$att_ids[ $id ] = (int) $att['post_parent'];
				$file           = (string) get_post_meta( $id, '_wp_attached_file', true );
				if ( '' === $file ) {
					continue;
				}
				$dir                          = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) . '/' : '';
				$paths[ strtolower( $file ) ] = $id;
				$meta                         = wp_get_attachment_metadata( $id );
				if ( is_array( $meta ) ) {
					if ( ! empty( $meta['original_image'] ) ) {
						$paths[ strtolower( $dir . $meta['original_image'] ) ] = $id;
					}
					foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $size ) {
						if ( ! empty( $size['file'] ) ) {
							$paths[ strtolower( $dir . $size['file'] ) ] = $id;
						}
					}
				}
			}

			$add      = function ( $id, $kind, $label, $url, $detail = '' ) use ( &$refs, $att_ids ) {
				$id = (int) $id;
				if ( ! isset( $att_ids[ $id ] ) ) {
					return;
				}
				$key = md5( $kind . '|' . $label . '|' . $detail );
				if ( ! isset( $refs[ $id ][ $key ] ) && ( ! isset( $refs[ $id ] ) || count( $refs[ $id ] ) < 60 ) ) {
					$refs[ $id ][ $key ] = array(
						'kind'   => $kind,
						'label'  => $label,
						'url'    => $url,
						'detail' => $detail,
					);
				}
			};
			$post_ref = function ( $post_id ) use ( &$cache ) {
				$post_id = (int) $post_id;
				if ( ! isset( $cache[ $post_id ] ) ) {
					$post              = get_post( $post_id );
					$type              = $post ? get_post_type_object( $post->post_type ) : null;
					$cache[ $post_id ] = array(
						'label' => $post ? ( ( '' !== $post->post_title ) ? $post->post_title : '#' . $post_id ) : '#' . $post_id,
						'url'   => $post ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
						'type'  => $type ? $type->labels->singular_name : '',
					);
				}
				return $cache[ $post_id ];
			};
			$use      = function ( $ids, $found_paths, $kind, $label, $url, $detail ) use ( $add, $paths ) {
				foreach ( $ids as $id ) {
					$add( $id, $kind, $label, $url, $detail );
				}
				foreach ( $found_paths as $path ) {
					if ( isset( $paths[ $path ] ) ) {
						$add( $paths[ $path ], $kind, $label, $url, $detail );
					}
				}
			};

			// 1. Uploaded to (parent) and featured images.
			foreach ( $att_ids as $id => $parent ) {
				if ( $parent > 0 && 'trash' !== get_post_status( $parent ) ) {
					$r = $post_ref( $parent );
					$add( $id, __( 'Attached to', 'ajforms' ), $r['label'], $r['url'], $r['type'] );
				}
			}
			$thumbs = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $thumbs as $row ) {
				$r = $post_ref( $row['post_id'] );
				$add( $row['meta_value'], __( 'Featured image of', 'ajforms' ), $r['label'], $r['url'], $r['type'] );
			}

			// 2. Post content (all post types incl. reusable blocks, templates, navigation).
			$post_ids = $wpdb->get_col( "SELECT ID FROM {$p} WHERE post_type NOT IN ('attachment','revision','nav_menu_item','auto-draft','customize_changeset') AND post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( array_chunk( array_map( 'intval', $post_ids ), 100 ) as $chunk ) {
				$rows = $wpdb->get_results( "SELECT ID, post_content, post_excerpt FROM {$p} WHERE ID IN (" . implode( ',', $chunk ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( (array) $rows as $row ) {
					list( $ids, $found ) = self::extract_refs( $row['post_content'] . "\n" . $row['post_excerpt'] );
					if ( $ids || $found ) {
						$r = $post_ref( $row['ID'] );
						$use( $ids, $found, __( 'Used in', 'ajforms' ), $r['label'], $r['url'], trim( $r['type'] . ' content' ) );
					}
				}
			}

			// 3. Post meta: SEO / social images, page-builder data, custom fields (not attachments or revisions).
			$meta_rows = $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$p} WHERE post_type IN ('attachment','revision','auto-draft'))"
				. " AND meta_key NOT IN ('_thumbnail_id','_wp_attached_file','_wp_attachment_metadata','_edit_lock','_edit_last')"
				. " AND (meta_value LIKE '%uploads/%' OR meta_value LIKE '%wp-image-%' OR meta_key REGEXP 'image|img|logo|icon|thumb|banner|bg|background|cover|photo|avatar|media|gallery|attachment|social|og_|hero|poster') LIMIT 30000", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			foreach ( (array) $meta_rows as $row ) {
				list( $ids, $found ) = self::extract_refs( $row['meta_value'] );
				if ( self::image_like_key( $row['meta_key'] ) && preg_match( '/^\d+(,\d+)*$/', (string) $row['meta_value'] ) ) {
					$ids = array_merge( $ids, array_map( 'intval', explode( ',', $row['meta_value'] ) ) );
				}
				if ( $ids || $found ) {
					$r = $post_ref( $row['post_id'] );
					$use( $ids, $found, __( 'Field of', 'ajforms' ), $r['label'], $r['url'], $row['meta_key'] );
				}
			}

			// 4. Options: theme settings (logo, header, background), widgets, site icon/logo, plugin settings.
			$active = array( get_stylesheet(), get_template() );
			$opts   = $wpdb->get_results(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'"
				. " AND option_name NOT LIKE 'synchy%' AND option_name NOT LIKE 'freesiem%' AND option_name NOT LIKE '%litespeed%'"
				. " AND (option_value LIKE '%uploads/%' OR option_name LIKE 'theme\\_mods\\_%' OR option_name LIKE 'widget\\_%' OR option_name IN ('site_icon','site_logo','custom_logo','sidebars_widgets')) LIMIT 3000", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			foreach ( (array) $opts as $row ) {
				$name = $row['option_name'];
				if ( 0 === strpos( $name, 'theme_mods_' ) ) {
					$theme  = substr( $name, 11 );
					$kind   = __( 'Theme setting', 'ajforms' );
					$url    = admin_url( 'customize.php' );
					$detail = in_array( $theme, $active, true ) ? $theme : sprintf( /* translators: %s: theme folder */ __( '%s, inactive theme', 'ajforms' ), $theme );
				} elseif ( 0 === strpos( $name, 'widget_' ) || 'sidebars_widgets' === $name ) {
					$kind   = __( 'Widget', 'ajforms' );
					$url    = admin_url( 'widgets.php' );
					$detail = $name;
				} elseif ( in_array( $name, array( 'site_icon', 'site_logo', 'custom_logo' ), true ) ) {
					$kind   = __( 'Site setting', 'ajforms' );
					$url    = admin_url( 'options-general.php' );
					$detail = $name;
				} else {
					$kind   = __( 'Option', 'ajforms' );
					$url    = admin_url( 'options.php' );
					$detail = $name;
				}
				$ids   = array();
				$found = array();
				if ( in_array( $name, array( 'site_icon', 'site_logo', 'custom_logo' ), true ) && ctype_digit( (string) $row['option_value'] ) ) {
					$ids[] = (int) $row['option_value'];
				}
				$walk = function ( $value, $key ) use ( &$walk, &$ids, &$found ) {
					if ( is_array( $value ) || is_object( $value ) ) {
						foreach ( (array) $value as $k => $v ) {
							$walk( $v, $k );
						}
						return;
					}
					if ( is_string( $value ) ) {
						list( $i, $f ) = self::extract_refs( $value );
						$ids           = array_merge( $ids, $i );
						$found         = array_merge( $found, $f );
					}
					if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && self::image_like_key( $key ) ) {
						$ids[] = (int) $value;
					}
				};
				$walk( maybe_unserialize( $row['option_value'] ), '' );
				if ( $ids || $found ) {
					$use( $ids, $found, $kind, $name, $url, $detail );
				}
			}

			// 5. Term meta (category/product images etc.).
			$terms = $wpdb->get_results(
				"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_value LIKE '%uploads/%' OR meta_key REGEXP 'image|img|logo|icon|thumb|banner|cover|photo' LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			foreach ( (array) $terms as $row ) {
				list( $ids, $found ) = self::extract_refs( $row['meta_value'] );
				if ( self::image_like_key( $row['meta_key'] ) && ctype_digit( (string) $row['meta_value'] ) ) {
					$ids[] = (int) $row['meta_value'];
				}
				if ( $ids || $found ) {
					$term = get_term( (int) $row['term_id'] );
					$tn   = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . (int) $row['term_id'];
					$tu   = ( $term && ! is_wp_error( $term ) ) ? (string) get_edit_term_link( $term->term_id, $term->taxonomy ) : '';
					$use( $ids, $found, __( 'Term', 'ajforms' ), $tn, $tu, $row['meta_key'] );
				}
			}

			foreach ( $refs as $id => $list ) {
				$refs[ $id ] = array_values( $list );
			}
			return $refs;
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

			// "In use" = the shared reference map found at least one place using the file (parent, featured
			// image, content, post meta, theme settings, widgets, options, term meta). Same source as the
			// "Used in" column, so the two can never disagree.
			$used_ids = array_map( 'intval', array_keys( self::ref_map() ) );
			$used     = $used_ids ? "{$p}.ID IN (" . implode( ',', $used_ids ) . ')' : '0=1';

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
			delete_transient( 'ajcore_media_refs' );
		}

		/** Options that can reference media: theme settings, widgets, site icon/logo. Skip the noisy rest. */
		public static function flush_for_option( $option ) {
			if ( 0 === strpos( (string) $option, 'theme_mods_' ) || 0 === strpos( (string) $option, 'widget_' ) || in_array( $option, array( 'sidebars_widgets', 'site_icon', 'site_logo' ), true ) ) {
				self::flush_counts();
			}
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
