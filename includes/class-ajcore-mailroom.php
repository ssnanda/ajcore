<?php
/**
 * Mailroom: one Client Portal tab for the shared mailboxes AJCore reads.
 *
 * Replaces the separate "Gmail Intake" and "Zoho Mail" tabs. Two collapsed connection buttons (Gmail, Zoho; green =
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
		$tabs[ self::TAB ] = array( 'label' => __( 'Mailroom', 'ajcore' ) );
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
			'cog'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		);
		return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	public static function render_tab( $tab ) {
		if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$gi  = AJCore_Gmail_Intake::instance();
		$zm  = AJCore_Zoho_Mail::instance();
		$src = isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : 'gmail';
		$src = in_array( $src, array( 'gmail', 'zoho' ), true ) ? $src : 'gmail';

		$sources = array(
			'gmail' => array( 'label' => 'Gmail', 'ok' => $gi->is_connected() ),
			'zoho'  => array( 'label' => 'Zoho', 'ok' => $zm->is_connected() ),
		);
		$pending = $gi->is_connected() ? $gi->pending_count() : 0;

		self::styles();
		echo '<div class="ajmr">';
		$zm->render_notices();

		// Header: title + the two connection buttons.
		echo '<div class="ajmr-head"><div class="ajmr-title"><span class="ajmr-title-ico">' . self::icon( 'mail', 18 ) . '</span><h2>' . esc_html__( 'Mailroom', 'ajcore' ) . '</h2></div><div class="ajmr-chips">';
		foreach ( $sources as $key => $s ) {
			printf(
				'<button type="button" class="ajmr-chip %1$s" data-target="ajmr-panel-%2$s" aria-expanded="false" title="%3$s"><i class="ajmr-dot"></i>%4$s<span class="ajmr-chev">%5$s</span></button>',
				$s['ok'] ? 'is-ok' : 'is-bad',
				esc_attr( $key ),
				esc_attr( $s['ok'] ? __( 'Connected. Click for settings.', 'ajcore' ) : __( 'Not connected. Click for settings.', 'ajcore' ) ),
				esc_html( $s['label'] ),
				self::icon( 'chevron', 14 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</div></div>';

		// Collapsed connection panels.
		echo '<section id="ajmr-panel-gmail" class="ajmr-panel" hidden><h3>' . self::icon( 'cog', 14 ) . ' ' . esc_html__( 'Gmail', 'ajcore' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$gi->render_connection_panel();
		echo '</section><section id="ajmr-panel-zoho" class="ajmr-panel" hidden><h3>' . self::icon( 'cog', 14 ) . ' ' . esc_html__( 'Zoho', 'ajcore' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$zm->render_connection_panel();
		echo '</section>';

		// Source switch.
		echo '<nav class="ajmr-switch">';
		printf(
			'<a class="%1$s" href="%2$s">%3$s %4$s%5$s</a>',
			'gmail' === $src ? 'is-on' : '',
			esc_url( self::url( 'gmail' ) ),
			self::icon( 'queue' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Gmail intake queue', 'ajcore' ),
			$pending > 0 ? ' <b class="ajmr-badge">' . (int) $pending . '</b>' : ''
		);
		printf(
			'<a class="%1$s" href="%2$s">%3$s %4$s</a>',
			'zoho' === $src ? 'is-on' : '',
			esc_url( self::url( 'zoho' ) ),
			self::icon( 'inbox' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Zoho shared inbox', 'ajcore' )
		);
		echo '</nav>';

		if ( 'gmail' === $src ) {
			if ( $gi->is_connected() ) {
				$gi->render_queue();
			} else {
				self::empty_state( __( 'Gmail is not connected.', 'ajcore' ) );
			}
		} else {
			$zm->render_inbox_panel();
		}
		echo '</div>';

		?>
		<script>
		( function () {
			var chips = document.querySelectorAll( '.ajmr-chip' );
			chips.forEach( function ( chip ) {
				chip.addEventListener( 'click', function () {
					var target = document.getElementById( chip.getAttribute( 'data-target' ) );
					var open = target && target.hasAttribute( 'hidden' );
					document.querySelectorAll( '.ajmr-panel' ).forEach( function ( p ) { p.setAttribute( 'hidden', '' ); } );
					chips.forEach( function ( c ) { c.setAttribute( 'aria-expanded', 'false' ); c.classList.remove( 'is-open' ); } );
					if ( open ) { target.removeAttribute( 'hidden' ); chip.setAttribute( 'aria-expanded', 'true' ); chip.classList.add( 'is-open' ); }
				} );
			} );
			// After a save / connect / disconnect / error, open the panel that action belongs to so its message is visible.
			var qs = window.location.search;
			var want = /gmail-intake-(saved|connected|disconnected|error)/.test( qs ) ? 'ajmr-panel-gmail' : '';
			if ( want ) { var c = document.querySelector( '.ajmr-chip[data-target="' + want + '"]' ); if ( c ) { c.click(); } }
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
		.ajmr-chips { display:flex; gap:8px; }
		.ajmr-chip { display:inline-flex; align-items:center; gap:8px; padding:7px 12px; border-radius:999px; border:1px solid; font-size:13px; font-weight:600; cursor:pointer; line-height:1; }
		.ajmr-chip.is-ok { background:#ecfdf5; border-color:#86efac; color:#166534; }
		.ajmr-chip.is-bad { background:#fef2f2; border-color:#fca5a5; color:#991b1b; }
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
		.ajmr-stat { display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:12px; border:1px solid var(--ajmr-line); background:#fff; text-decoration:none; color:var(--ajmr-ink); }
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
		.ajmr .ajcore-status-pill.disabled { background:#fee2e2; color:#991b1b; }
		.ajmr .ajmr-filter { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
		</style>
		<?php
	}
}
