<?php
/**
 * Mailroom: one Client Portal tab for the shared mailboxes AJCore reads.
 *
 * Replaces the separate "Pipeline" and "Zoho Mail" tabs. Two collapsed connection buttons (Gmail, Zoho; green =
 * connected, red = not) hold each mailbox's settings; below them a switch picks the Gmail intake queue (the automated
 * filing pipeline) or the live Zoho shared inbox. Old tab URLs (tab=gmail-intake / tab=zoho-mail) redirect here.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Mailroom {

	const TAB = 'mailroom';

	public static function init() {
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_tab' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_old_tabs' ) );
	}

	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'Pipeline', 'ajcore' ) );
		return $tabs;
	}

	public static function url( $src = 'gmail', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB, 'src' => $src ), $args ), admin_url( 'admin.php' ) );
	}

	/** Bookmarks, emails and OAuth return links that still point at the two old tabs. */
	public static function redirect_old_tabs() {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_GET['page'], $_GET['tab'] ) || 'ajforms-client-portal' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$old = sanitize_key( wp_unslash( $_GET['tab'] ) );
		if ( ! in_array( $old, array( 'gmail-intake', 'zoho-mail' ), true ) ) {
			return;
		}
		$args = array_diff_key( wp_unslash( $_GET ), array( 'page' => 1, 'tab' => 1 ) );
		wp_safe_redirect( self::url( 'zoho-mail' === $old ? 'zoho' : 'gmail', $args ) );
		exit;
	}

	private static function icon( $name, $size = 16 ) {
		$paths = array(
			'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'inbox'   => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
			'queue'   => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
			'alert'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
			'check'   => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
			'chevron' => '<path d="m6 9 6 6 6-6"/>',
			'search'  => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
			'x'       => '<path d="M18 6 6 18M6 6l12 12"/>',
			'refresh' => '<path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/><path d="M3 12a9 9 0 0 1 15.5-6.3L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
			'trash'   => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
			'cog'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		);
		return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	public static function render_tab( $tab ) {
		if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$gi = AJCore_Gmail_Intake::instance();
		$zm = AJCore_Zoho_Mail::instance();
		$f  = AJCore_Gmail_Intake::queue_filters();
		$n  = $gi->queue_counts();

		// Connection state: green = connected and on, grey = connected but switched off (Gmail), red = not connected.
		$state = array(
			'gmail' => $gi->is_active() ? 'is-ok' : ( $gi->is_connected() ? 'is-off' : 'is-bad' ),
			'zoho'  => $zm->is_connected() ? 'is-ok' : 'is-bad',
		);
		$tips = array(
			'is-ok'  => __( 'Connected', 'ajcore' ),
			'is-off' => __( 'Connected but switched off', 'ajcore' ),
			'is-bad' => __( 'Not connected', 'ajcore' ),
		);
		// A tile link keeps the other filters; the search box is added on top by JS.
		$link = static function ( array $over ) use ( $f ) {
			$args = array();
			$cur  = array_merge( array( 'gi_source' => $f['source'], 'gi_status' => $f['status'], 's' => $f['search'] ), $over );
			if ( 'all' !== $cur['gi_source'] ) {
				$args['gi_source'] = $cur['gi_source'];
			}
			if ( 'pending' !== $cur['gi_status'] ) {
				$args['gi_status'] = $cur['gi_status'];
			}
			if ( '' !== $cur['s'] ) {
				$args['s'] = $cur['s'];
			}
			$args['gi_processed'] = 1; // browsing the queue must not trigger a mailbox pass + reload each time
			return self::url( 'gmail', $args );
		};

		self::styles();
		echo '<div class="ajmr">';
		$zm->render_notices();
		echo '<div class="ajmr-head"><div class="ajmr-title"><span class="ajmr-title-ico">' . self::icon( 'mail', 18 ) . '</span><h2>' . esc_html__( 'Pipeline', 'ajcore' ) . '</h2></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Row 1: the focus (All mailboxes, Pending, Filed) on the left; the two mailbox tiles small, on the far right.
		echo '<div class="ajmr-row ajmr-row-main">';
		$all_pending = (int) $n['all']['pending'];
		printf( '<div class="ajmr-tile is-all%s">', 'all' === $f['source'] ? ' is-on' : '' );
		printf(
			'<a class="ajmr-tile-main" href="%1$s"><span class="ajmr-tile-ico">%2$s</span><span class="ajmr-tile-txt"><small>%3$s</small><strong%4$s>%5$d</strong><em>%6$s</em></span></a></div>',
			esc_url( $link( array( 'gi_source' => 'all' ) ) ),
			self::icon( 'mail', 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'All mailboxes', 'ajcore' ),
			$all_pending > 0 ? ' class="is-alert"' : '',
			$all_pending,
			esc_html__( 'pending', 'ajcore' )
		);
		printf(
			'<a class="ajmr-tile ajmr-stat is-amber%1$s" href="%2$s"><span class="ajmr-tile-ico">%3$s</span><span class="ajmr-tile-txt"><small>%4$s</small><strong>%5$d</strong></span></a>',
			'pending' === $f['status'] ? ' is-on' : '',
			esc_url( $link( array( 'gi_status' => 'pending' ) ) ),
			self::icon( 'alert', 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Pending', 'ajcore' ),
			(int) $n[ $f['source'] ]['pending']
		);
		printf(
			'<a class="ajmr-tile ajmr-stat is-green%1$s" href="%2$s"><span class="ajmr-tile-ico">%3$s</span><span class="ajmr-tile-txt"><small>%4$s</small><strong>%5$d</strong></span></a>',
			'filed' === $f['status'] ? ' is-on' : '',
			esc_url( $link( array( 'gi_status' => 'filed' ) ) ),
			self::icon( 'check', 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Filed', 'ajcore' ),
			(int) $n[ $f['source'] ]['filed']
		);

		// The two mailboxes: small, far right. A disconnected one shows "Disconnected" and opens its connection settings.
		echo '<div class="ajmr-mailboxes">';
		$boxes = array(
			'gmail' => array( 'label' => 'Gmail', 'icon' => 'mail', 'cls' => 'is-gmail' ),
			'zoho'  => array( 'label' => 'Zoho', 'icon' => 'inbox', 'cls' => 'is-zoho' ),
		);
		foreach ( $boxes as $key => $box ) {
			$connected = 'is-bad' !== $state[ $key ];
			$pending   = (int) $n[ $key ]['pending'];
			printf( '<div class="ajmr-tile ajmr-tile-sm %1$s%2$s%3$s">', esc_attr( $box['cls'] ), $f['source'] === $key ? ' is-on' : '', $connected ? '' : ' is-disconnected' );
			if ( $connected ) {
				printf(
					'<a class="ajmr-tile-main" href="%1$s"><span class="ajmr-tile-ico">%2$s</span><span class="ajmr-tile-txt"><small>%3$s</small><strong%4$s>%5$d</strong><em>%6$s</em></span></a>',
					esc_url( $link( array( 'gi_source' => $key ) ) ),
					self::icon( $box['icon'], 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $box['label'] ),
					$pending > 0 ? ' class="is-alert"' : '',
					$pending,
					esc_html__( 'pending', 'ajcore' )
				);
			} else {
				printf(
					'<button type="button" class="ajmr-tile-main ajmr-open-panel" data-target="ajmr-panel-%1$s"><span class="ajmr-tile-ico">%2$s</span><span class="ajmr-tile-txt"><small>%3$s</small><strong class="is-off">%4$s</strong><em>%5$s</em></span></button>',
					esc_attr( $key ),
					self::icon( $box['icon'], 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $box['label'] ),
					esc_html__( 'Disconnected', 'ajcore' ),
					esc_html__( 'click to connect', 'ajcore' )
				);
			}
			printf(
				'<span class="ajmr-tile-conn"><i class="ajmr-dot %1$s" title="%2$s"></i><button type="button" class="ajmr-gear" data-target="ajmr-panel-%3$s" aria-expanded="false" title="%4$s">%5$s</button></span></div>',
				esc_attr( $state[ $key ] ),
				esc_attr( $tips[ $state[ $key ] ] ),
				esc_attr( $key ),
				esc_attr__( 'Connection settings', 'ajcore' ),
				self::icon( 'cog', 14 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</div></div>';

		// Row 2: search, then Clear / Refresh / Reset log. Everything filters as soon as it is picked.
		echo '<div class="ajmr-row ajmr-row-tools">';
		printf(
			'<label class="ajmr-tile ajmr-search"><span class="ajmr-tile-ico">%1$s</span><input type="search" id="ajmr-search" value="%2$s" placeholder="%3$s" data-url="%4$s" autocomplete="off"></label>',
			self::icon( 'search', 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $f['search'] ),
			esc_attr__( 'Search subject, sender, company, customer…', 'ajcore' ),
			esc_url( $link( array( 's' => '' ) ) )
		);
		printf( '<a class="ajmr-tile ajmr-tool" href="%1$s">%2$s<span>%3$s</span></a>', esc_url( self::url( 'gmail', array( 'gi_processed' => 1 ) ) ), self::icon( 'x', 16 ), esc_html__( 'Clear', 'ajcore' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<button type="button" id="ajcore-gmail-intake-refresh" class="ajmr-tile ajmr-tool">%1$s<span>%2$s</span></button>', self::icon( 'refresh', 16 ), esc_html__( 'Refresh', 'ajcore' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<button type="button" id="ajcore-gmail-intake-reset-log" class="ajmr-tile ajmr-tool is-danger">%1$s<span>%2$s</span></button>', self::icon( 'trash', 16 ), esc_html__( 'Reset log', 'ajcore' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		// Filtering to a mailbox that is not connected: say so and offer the connection settings, instead of an empty list.
		if ( in_array( $f['source'], array( 'gmail', 'zoho' ), true ) && 'is-bad' === $state[ $f['source'] ] ) {
			printf(
				'<div class="ajmr-note"><strong>%1$s</strong> %2$s <button type="button" class="button ajmr-open-panel" data-target="ajmr-panel-%3$s">%4$s</button></div>',
				esc_html( 'zoho' === $f['source'] ? 'Zoho' : 'Gmail' ),
				esc_html__( 'is disconnected. Rows already read are listed below.', 'ajcore' ),
				esc_attr( $f['source'] ),
				esc_html__( 'Open connection settings', 'ajcore' )
			);
		}

		// Collapsed connection panels (opened by the tile gears).
		echo '<section id="ajmr-panel-gmail" class="ajmr-panel" hidden><h3>' . self::icon( 'cog', 14 ) . ' ' . esc_html__( 'Gmail', 'ajcore' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$gi->render_connection_panel();
		echo '</section><section id="ajmr-panel-zoho" class="ajmr-panel" hidden><h3>' . self::icon( 'cog', 14 ) . ' ' . esc_html__( 'Zoho', 'ajcore' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$zm->render_connection_panel();
		echo '</section>';

		if ( $gi->is_active() || $zm->is_connected() ) {
			// What each mailbox is filtering on right now, and how the last pass went (so nobody has to guess).
			echo '<div class="ajmr-filters">';
			foreach ( $gi->filter_status() as $fs ) {
				printf(
					'<span class="ajmr-fchip %1$s"><b>%2$s</b> %3$s%4$s</span>',
					$fs['open'] ? 'is-open' : '',
					esc_html( $fs['label'] ),
					esc_html( $fs['text'] ),
					$fs['open'] ? ' <i>' . esc_html__( '(no filter saved: every message is read)', 'ajcore' ) . '</i>' : ''
				);
			}
			$last = get_transient( 'ajcore_gmail_intake_last_pass' );
			if ( is_array( $last ) && ! empty( $last['at'] ) ) {
				printf( '<span class="ajmr-fnote">%s</span>', esc_html( sprintf( __( 'Last pass %1$s · %2$d new', 'ajcore' ), get_date_from_gmt( get_gmt_from_date( $last['at'] ), 'M j, g:i a' ), (int) $last['checked'] ) ) );
			}
			echo '</div>';
			$gi->render_rules_panel();
			echo '<div class="ajmr-list">';
			$gi->render_queue();
			echo '</div>';
		} else {
			self::empty_state( __( 'No mailbox is connected.', 'ajcore' ) );
		}
		echo '</div>';

		?>
		<script>
		( function () {
			var gears = document.querySelectorAll( '.ajmr-gear' );
			gears.forEach( function ( gear ) {
				gear.addEventListener( 'click', function () {
					var target = document.getElementById( gear.getAttribute( 'data-target' ) );
					var open = target && target.hasAttribute( 'hidden' );
					document.querySelectorAll( '.ajmr-panel' ).forEach( function ( p ) { p.setAttribute( 'hidden', '' ); } );
					gears.forEach( function ( g ) { g.setAttribute( 'aria-expanded', 'false' ); g.classList.remove( 'is-open' ); } );
					if ( open ) { target.removeAttribute( 'hidden' ); gear.setAttribute( 'aria-expanded', 'true' ); gear.classList.add( 'is-open' ); target.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); }
				} );
			} );
			// A disconnected mailbox tile (or its banner button) opens that mailbox's connection settings.
			document.querySelectorAll( '.ajmr-open-panel' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					var g = document.querySelector( '.ajmr-gear[data-target="' + b.getAttribute( 'data-target' ) + '"]' );
					if ( g ) { g.click(); }
				} );
			} );
			// After a save / connect / disconnect / error, open the panel that action belongs to so its message is visible.
			if ( /gmail-intake-(saved|connected|disconnected|error)/.test( window.location.search ) ) {
				var g = document.querySelector( '.ajmr-gear[data-target="ajmr-panel-gmail"]' ); if ( g ) { g.click(); }
			}
			// Search filters as you type (short pause), no Filter button.
			var box = document.getElementById( 'ajmr-search' );
			if ( box ) {
				var timer = null;
				var go = function () {
					var url = new URL( box.getAttribute( 'data-url' ), window.location.href );
					if ( box.value.trim() !== '' ) { url.searchParams.set( 's', box.value.trim() ); }
					window.location.assign( url.toString() );
				};
				box.addEventListener( 'input', function () { clearTimeout( timer ); timer = setTimeout( go, 450 ); } );
				box.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); clearTimeout( timer ); go(); } } );
				if ( box.value ) { box.focus(); box.setSelectionRange( box.value.length, box.value.length ); }
			}
		} )();
		</script>
		<?php
	}

	private static function empty_state( $message ) {
		echo '<div class="ajmr-card ajmr-empty">' . esc_html( $message ) . ' ' . esc_html__( 'Open its button above to connect.', 'ajcore' ) . '</div>';
	}

	private static function styles() {
		?>
		<style>
		.ajmr { --ajmr-ink:#0f172a; --ajmr-mute:#64748b; --ajmr-line:#e2e8f0; --ajmr-bg:#f8fafc; --ajmr-brand:#4f46e5; --ajmr-ok:#16a34a; --ajmr-bad:#dc2626; --ajmr-warn:#d97706; color:var(--ajmr-ink); max-width:1200px; }
		.ajmr * { box-sizing:border-box; }
		.ajmr summary::before, .ajmr summary::after { display:none !important; }
		.ajmr summary { list-style:revert; cursor:pointer; font-weight:600; padding:4px 0; }
		.ajmr-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin:0 0 14px; }
		.ajmr-title { display:flex; align-items:center; gap:10px; }
		.ajmr-title h2 { margin:0; font-size:20px; line-height:1.2; }
		.ajmr-title-ico { display:inline-grid; place-items:center; width:32px; height:32px; border-radius:10px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; }
		.ajmr-row { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 12px; }
		.ajmr-tile { position:relative; display:flex; align-items:center; gap:12px; padding:12px 14px; min-width:150px; background:#fff; border:1px solid var(--ajmr-line); border-radius:14px; box-shadow:0 1px 2px rgba(15,23,42,.04); color:var(--ajmr-ink); text-decoration:none; font:inherit; cursor:pointer; }
		.ajmr-tile:hover { border-color:#c7d2fe; }
		.ajmr-tile.is-on { border-color:var(--ajmr-brand); box-shadow:0 0 0 3px rgba(79,70,229,.14); }
		.ajmr-tile-main { display:flex; align-items:center; gap:12px; color:inherit; text-decoration:none; padding-right:44px; }
		.ajmr-tile-ico { display:grid; place-items:center; width:36px; height:36px; border-radius:11px; flex:none; background:#eef2ff; color:#4338ca; }
		.ajmr-tile.is-gmail .ajmr-tile-ico { background:#fee2e2; color:#b91c1c; }
		.ajmr-tile.is-zoho .ajmr-tile-ico { background:#dbeafe; color:#1d4ed8; }
		.ajmr-tile-txt { display:flex; flex-direction:column; line-height:1.15; }
		.ajmr-tile-txt small { color:var(--ajmr-mute); font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
		.ajmr-tile-txt strong { font-size:22px; }
		.ajmr-tile-txt strong.is-alert { color:#b45309; }
		.ajmr-tile-txt em { font-style:normal; font-size:11px; color:var(--ajmr-mute); }
		.ajmr-row-main { align-items:stretch; }
		.ajmr-mailboxes { margin-left:auto; display:flex; gap:8px; align-items:stretch; }
		.ajmr-tile-sm { min-width:0; padding:6px 8px; gap:8px; }
		.ajmr-tile-sm .ajmr-tile-main { padding-right:34px; gap:8px; }
		.ajmr-tile-sm .ajmr-tile-ico { width:28px; height:28px; border-radius:9px; }
		.ajmr-tile-sm .ajmr-tile-txt strong { font-size:16px; }
		.ajmr-tile-sm .ajmr-tile-conn { top:5px; right:5px; }
		button.ajmr-tile-main { background:none; border:0; cursor:pointer; font:inherit; text-align:left; color:inherit; }
		.ajmr-tile-txt strong.is-off { font-size:13px; color:#b91c1c; }
		.ajmr-tile.is-disconnected { border-style:dashed; background:#fffafa; }
		.ajmr-note { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 12px; padding:10px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; color:#991b1b; font-size:13px; }
		.ajmr-tile-conn { position:absolute; top:8px; right:8px; display:flex; align-items:center; gap:6px; }
		.ajmr-dot.is-ok { background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.2); }
		.ajmr-dot.is-bad { background:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.2); }
		.ajmr-dot.is-off { background:#94a3b8; }
		.ajmr-gear { display:inline-grid; place-items:center; width:24px; height:24px; border:0; border-radius:8px; background:transparent; color:#64748b; cursor:pointer; padding:0; }
		.ajmr-gear:hover, .ajmr-gear.is-open { background:#eef2ff; color:#4338ca; }
		.ajmr-row-tools .ajmr-stat { min-width:140px; }
		.ajmr-stat.is-amber .ajmr-tile-ico { background:#fef3c7; color:#b45309; }
		.ajmr-stat.is-green .ajmr-tile-ico { background:#dcfce7; color:#15803d; }
		.ajmr-search { flex:1 1 280px; min-width:240px; cursor:text; }
		.ajmr-search .ajmr-tile-ico { background:#f1f5f9; color:#64748b; }
		.ajmr-search input { flex:1; border:0; box-shadow:none; background:transparent; font-size:14px; padding:6px 0; outline:0; min-height:0; }
		.ajmr-tool { gap:8px; padding:12px 16px; min-width:0; font-size:13px; font-weight:600; color:#334155; }
		.ajmr-tool.is-danger { color:#b91c1c; }
		.ajmr-list { margin-top:0; }
		.ajmr-chips { display:flex; gap:8px; }
		.ajmr-chip { display:inline-flex; align-items:center; gap:8px; padding:7px 12px; border-radius:999px; border:1px solid; font-size:13px; font-weight:600; cursor:pointer; line-height:1; }
		.ajmr-chip.is-ok { background:#ecfdf5; border-color:#86efac; color:#166534; }
		.ajmr-chip.is-bad { background:#fef2f2; border-color:#fca5a5; color:#991b1b; }
		.ajmr-chip.is-off { background:#f1f5f9; border-color:#cbd5e1; color:#475569; }
		.ajmr-chip.is-off .ajmr-dot { background:#94a3b8; }
		.ajmr-dot { width:9px; height:9px; border-radius:50%; background:currentColor; display:inline-block; }
		.ajmr-chip.is-ok .ajmr-dot { background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.2); }
		.ajmr-chip.is-bad .ajmr-dot { background:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.2); }
		.ajmr-chev { display:inline-flex; transition:transform .15s; opacity:.7; }
		.ajmr-chip.is-open .ajmr-chev { transform:rotate(180deg); }
		.ajmr-panel { background:#fff; border:1px solid var(--ajmr-line); border-radius:14px; padding:16px 18px; margin:0 0 14px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
		.ajmr-panel[hidden] { display:none; }
		.ajmr-panel > h3 { display:flex; align-items:center; gap:6px; margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--ajmr-mute); }
		.ajmr-switch { display:inline-flex; gap:4px; padding:4px; background:#eef2ff; border-radius:12px; margin:0 0 14px; }
		.ajmr-switch a { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:9px; font-size:13px; font-weight:600; color:#4338ca; text-decoration:none; }
		.ajmr-switch a:hover { background:rgba(255,255,255,.6); }
		.ajmr-switch a.is-on { background:#fff; color:var(--ajmr-ink); box-shadow:0 1px 3px rgba(15,23,42,.12); }
		.ajmr-badge { background:var(--ajmr-bad); color:#fff; border-radius:999px; font-size:11px; padding:1px 7px; line-height:16px; }
		.ajmr-card { background:#fff; border:1px solid var(--ajmr-line); border-radius:14px; padding:16px 18px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
		.ajmr-empty { color:var(--ajmr-mute); text-align:center; padding:28px; }
		.ajmr-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,220px)); gap:10px; margin:0 0 14px; }
		.ajmr-stat-old { display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:12px; border:1px solid var(--ajmr-line); background:#fff; text-decoration:none; color:var(--ajmr-ink); }
		.ajmr-stat:hover { border-color:#c7d2fe; }
		.ajmr-stat.is-on { border-color:var(--ajmr-brand); box-shadow:0 0 0 3px rgba(79,70,229,.12); }
		.ajmr-stat-ico { display:grid; place-items:center; width:34px; height:34px; border-radius:10px; flex:none; }
		.ajmr-stat.is-amber .ajmr-stat-ico { background:#fef3c7; color:#b45309; }
		.ajmr-stat.is-green .ajmr-stat-ico { background:#dcfce7; color:#15803d; }
		.ajmr-stat small { display:block; color:var(--ajmr-mute); font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
		.ajmr-stat strong { font-size:22px; line-height:1.1; }
		.ajmr-pills { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 12px; }
		.ajmr-pills a { padding:5px 12px; border-radius:999px; background:#f1f5f9; color:#334155; font-size:12px; font-weight:600; text-decoration:none; }
		.ajmr-pills a.is-on { background:var(--ajmr-brand); color:#fff; }
		.ajmr-pills a b { margin-left:4px; opacity:.8; }
		.ajmr-meta { color:var(--ajmr-mute); font-size:12px; margin:0 0 10px; }
		.ajmr table.widefat { border:1px solid var(--ajmr-line); border-radius:12px; overflow:hidden; box-shadow:none; border-collapse:separate; border-spacing:0; }
		.ajmr table.widefat th { background:var(--ajmr-bg); color:var(--ajmr-mute); font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; padding:10px 12px; }
		.ajmr table.widefat td { padding:10px 12px; vertical-align:top; border-top:1px solid #f1f5f9; }
		.ajmr table.widefat.striped > tbody > :nth-child(odd) { background:#fff; }
		.ajmr table.widefat tbody tr:hover td { background:#f8fafc; }
		.ajmr .ajcore-status-pill { display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:700; background:#fef3c7; color:#92400e; }
		.ajmr .ajcore-status-pill.active { background:#dcfce7; color:#166534; }
		.ajmr .ajcore-status-pill.dup { background:#e2e8f0; color:#475569; }
		.ajmr-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 12px; font-size:12px; }
		.ajmr-fchip { background:#fff; border:1px solid var(--ajmr-line); border-radius:999px; padding:5px 12px; color:#334155; word-break:break-word; }
		.ajmr-fchip b { margin-right:4px; }
		.ajmr-fchip.is-open { background:#fffbeb; border-color:#fcd34d; color:#92400e; }
		.ajmr-fnote { color:var(--ajmr-mute); }
		.ajmr-bulk { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 10px; padding:8px 12px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:10px; font-size:13px; }
		.ajmr-bulk[hidden] { display:none; }
		.ajmr .ajcore-status-pill.disabled { background:#fee2e2; color:#991b1b; }
		.ajmr-src { display:inline-block; padding:1px 8px; border-radius:6px; font-size:10px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; vertical-align:1px; }
		.ajmr-src.is-gmail { background:#fee2e2; color:#b91c1c; }
		.ajmr-src.is-zoho { background:#dbeafe; color:#1d4ed8; }
		.ajmr-rules { background:#fff; border:1px solid var(--ajmr-line); border-radius:14px; padding:8px 16px; margin:0 0 14px; }
		.ajmr-rules-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:4px 28px; padding:6px 0 10px; }
		.ajmr-rules h4 { margin:10px 0 4px; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--ajmr-mute); }
		.ajmr-rules ul { margin:0; padding-left:16px; list-style:disc; font-size:12px; color:#334155; }
		.ajmr-rules li { margin:0 0 3px; }
		.ajmr .ajmr-filter { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
		</style>
		<?php
	}
}
