<?php
/**
 * Marketing: emails labelled "marketing" from any mailbox pipeline (Purchases, Invoices, Insurance, HOA, ...). They leave the pipeline's own
 * list and collect in one Marketing tab where they can all be seen, previewed and deleted. Delete moves the mail to the Zoho Trash (recoverable
 * there) and keeps a hidden record so the next read does not bring it back. A sender can also be marked so its future mail is labelled
 * automatically.
 *
 * Pipelines call AJCore_Marketing::label() / ::known() / ::is_marketing_sender(); AJCore_Mail_Pipelines does this itself, and AJCore-RA does it for Purchases.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Marketing {

	const SENDERS = 'ajcore_marketing_senders';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		add_filter( 'ajcore_pipeline_views', array( __CLASS__, 'register_view' ), 99 );
		add_filter( 'ajcore_ops_modules', array( __CLASS__, 'ops_modules' ) );
		add_filter( 'ajcore_ops_sections', array( __CLASS__, 'ops_sections' ) );
		add_filter( 'ajcore_ops_route_sections', array( __CLASS__, 'ops_route_sections' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
	}

	// ── registration with AJCore ────────────────────────────────────────────────

	public static function register_view( $views ) {
		$views['marketing'] = array(
			'label'  => __( 'Marketing', 'ajcore' ),
			'badge'  => static function () {
				return 0; // a pile to clear, not work to do: no alert badge
			},
			'render' => static function () {
				self::instance()->render_view();
			},
		);
		return $views;
	}

	public static function ops_modules( $modules ) {
		$modules   = (array) $modules;
		$modules[] = 'marketing';
		return $modules;
	}

	public static function ops_sections( $sections ) {
		$sections              = (array) $sections;
		$sections['marketing'] = 'Marketing';
		return $sections;
	}

	public static function ops_route_sections( $map ) {
		$map              = (array) $map;
		$map['marketing'] = 'marketing';
		return $map;
	}

	public static function catalog( $catalog ) {
		$catalog   = (array) $catalog;
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/marketing', 'auth' => 'Admin', 'purpose' => 'Emails labelled marketing from every pipeline (filters q, pipeline), counts per pipeline, marketing senders.', 'app' => 'OPS pipeline' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/marketing/{id}', 'auth' => 'Admin', 'purpose' => 'One marketing email with its full HTML (fetched on first open).', 'app' => 'OPS pipeline' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/marketing/(delete|restore|senders)', 'auth' => 'Admin', 'purpose' => 'Delete (to the Zoho Trash) or restore labelled emails; add/remove a marketing sender.', 'app' => 'OPS pipeline' );
		return $catalog;
	}

	// ── database ────────────────────────────────────────────────────────────────

	private function kit() {
		return class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	private function pdb() {
		$kit = $this->kit();
		return $kit['portal_db']();
	}

	private function table() {
		return $this->pdb()->prefix . 'aj_mail_marketing';
	}

	public function ensure_table() {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		$pdb = $this->pdb();
		$kit = $this->kit();
		$tbl = $this->table();
		if ( $kit['table_exists']( $pdb, $tbl ) ) {
			return $done = true;
		}
		$charset = $pdb->get_charset_collate();
		$pdb->query( "CREATE TABLE IF NOT EXISTS `{$tbl}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline varchar(40) NOT NULL DEFAULT '',
			mailbox varchar(190) NOT NULL DEFAULT '',
			message_id varchar(190) NOT NULL,
			subject varchar(500) NOT NULL DEFAULT '',
			sender varchar(255) NOT NULL DEFAULT '',
			summary text NULL,
			body mediumtext NULL,
			body_html mediumtext NULL,
			body_debug text NULL,
			received_at datetime NULL,
			status varchar(12) NOT NULL DEFAULT 'marketing',
			trash_note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY mbox_msg (mailbox, message_id),
			KEY status_rcv (status, received_at)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $done = $kit['table_exists']( $pdb, $tbl );
	}

	// ── API for pipelines ───────────────────────────────────────────────────────

	/** Labels one email as marketing. $m: pipeline, mailbox, message_id, subject, sender, summary, received_at. */
	public static function label( array $m ) {
		$self = self::instance();
		if ( ! $self->ensure_table() || empty( $m['message_id'] ) ) {
			return false;
		}
		$pdb = $self->pdb();
		return false !== $pdb->query( $pdb->prepare(
			"INSERT IGNORE INTO `{$self->table()}` (pipeline, mailbox, message_id, subject, sender, summary, received_at, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s, 'marketing', %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(string) ( $m['pipeline'] ?? '' ),
			strtolower( (string) ( $m['mailbox'] ?? '' ) ),
			(string) $m['message_id'],
			mb_substr( (string) ( $m['subject'] ?? '' ), 0, 500 ),
			mb_substr( (string) ( $m['sender'] ?? '' ), 0, 255 ),
			(string) ( $m['summary'] ?? '' ),
			(string) ( $m['received_at'] ?? current_time( 'mysql', true ) ),
			current_time( 'mysql' ),
			current_time( 'mysql' )
		) );
	}

	/** Message ids of a mailbox that are already labelled (any status, so deleted ones are not read back in). */
	public static function known( $mailbox, array $ids ) {
		$self = self::instance();
		if ( ! $ids || ! $self->ensure_table() ) {
			return array();
		}
		$pdb = $self->pdb();
		$ph  = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		return array_flip( (array) $pdb->get_col( $pdb->prepare( "SELECT message_id FROM `{$self->table()}` WHERE mailbox = %s AND message_id IN ({$ph})", array_merge( array( strtolower( (string) $mailbox ) ), array_values( $ids ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** The addresses / domains whose mail is labelled marketing automatically. */
	public static function senders() {
		$list = get_option( self::SENDERS, array() );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	/** Does the "Name <addr@domain>" sender match a marketing sender rule (full address or domain)? */
	public static function is_marketing_sender( $sender ) {
		$addr = strtolower( self::address_of( $sender ) );
		if ( '' === $addr ) {
			return false;
		}
		$domain = false !== strpos( $addr, '@' ) ? substr( $addr, strrpos( $addr, '@' ) + 1 ) : $addr;
		foreach ( self::senders() as $rule ) {
			$rule = strtolower( ltrim( $rule, '@' ) );
			if ( $rule === $addr || $rule === $domain || ( false === strpos( $rule, '@' ) && '.' . $rule === substr( $domain, -strlen( $rule ) - 1 ) ) ) {
				return true; // the full address, the domain, or a subdomain of it
			}
		}
		return false;
	}

	public static function address_of( $sender ) {
		return preg_match( '/<([^>]+)>/', (string) $sender, $m ) ? trim( $m[1] ) : trim( (string) $sender );
	}

	/** Adds a sender rule: the full address, or its domain when $domain is true. */
	public static function add_sender( $sender, $domain = false ) {
		$addr = strtolower( self::address_of( $sender ) );
		if ( '' === $addr || false === strpos( $addr, '@' ) ) {
			return;
		}
		$rule = $domain ? substr( $addr, strrpos( $addr, '@' ) + 1 ) : $addr;
		$list = self::senders();
		if ( ! in_array( $rule, $list, true ) ) {
			$list[] = $rule;
			update_option( self::SENDERS, $list, false );
		}
	}

	// ── REST ────────────────────────────────────────────────────────────────────

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( $ns, '/ops/marketing', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_list' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/marketing/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_item' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/marketing/delete', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_delete' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/marketing/restore', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_restore' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/marketing/senders', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_senders' ), 'permission_callback' => $ops ) );
	}

	private function item_out( $r, $full = false ) {
		$out = array( 'id' => (int) $r->id, 'pipeline' => (string) $r->pipeline, 'mailbox' => (string) $r->mailbox, 'subject' => (string) $r->subject, 'sender' => (string) $r->sender, 'summary' => (string) $r->summary, 'received_at' => (string) $r->received_at );
		if ( $full ) {
			$out['body']       = (string) $r->body;
			$out['body_html']  = (string) $r->body_html;
			$out['body_debug'] = (string) $r->body_debug;
		}
		return $out;
	}

	public function rest_list( WP_REST_Request $request ) {
		if ( ! $this->ensure_table() ) {
			return new WP_Error( 'no_table', __( 'The table could not be created.', 'ajcore' ), array( 'status' => 500 ) );
		}
		$pdb    = $this->pdb();
		$tbl    = $this->table();
		$where  = array( "status = 'marketing'" );
		$params = array();
		$pipe   = sanitize_key( (string) $request->get_param( 'pipeline' ) );
		if ( '' !== $pipe ) {
			$where[]  = 'pipeline = %s';
			$params[] = $pipe;
		}
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like     = '%' . $pdb->esc_like( $q ) . '%';
			$where[]  = '( subject LIKE %s OR sender LIKE %s OR summary LIKE %s )';
			$params   = array_merge( $params, array( $like, $like, $like ) );
		}
		$sql  = "SELECT id, pipeline, mailbox, subject, sender, summary, received_at FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY received_at DESC, id DESC LIMIT 500';
		$rows = (array) ( $params ? $pdb->get_results( $pdb->prepare( $sql, $params ) ) : $pdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by   = array();
		foreach ( (array) $pdb->get_results( "SELECT pipeline, COUNT(*) n FROM `{$tbl}` WHERE status = 'marketing' GROUP BY pipeline" ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$by[ (string) $c->pipeline ] = (int) $c->n;
		}
		$labels = array();
		foreach ( AJCore_Mail_Pipelines::pipelines() as $id => $p ) {
			$labels[ $id ] = $p['label'];
		}
		$labels = (array) apply_filters( 'ajcore_marketing_pipeline_labels', $labels + array( 'purchases' => 'Purchases' ) );
		return rest_ensure_response( array( 'items' => array_map( array( $this, 'item_out' ), $rows ), 'counts' => $by, 'pipelines' => $labels, 'senders' => self::senders() ) );
	}

	/** One email with its full HTML; fetched from Zoho the first time and kept. */
	public function rest_item( WP_REST_Request $request ) {
		if ( ! $this->ensure_table() ) {
			return new WP_Error( 'no_table', __( 'The table could not be created.', 'ajcore' ), array( 'status' => 500 ) );
		}
		$pdb = $this->pdb();
		$row = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$this->table()}` WHERE id = %d", absint( $request['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Not found.', 'ajcore' ), array( 'status' => 404 ) );
		}
		if ( null === $row->body_html && class_exists( 'AJCore_Zoho_Mail' ) && '' !== (string) $row->mailbox ) {
			$m = AJCore_Zoho_Mail::instance()->intake_message( (string) $row->message_id, (string) $row->mailbox );
			if ( ! is_wp_error( $m ) ) {
				$row->body       = mb_substr( (string) $m['body_text'], 0, 20000 );
				$row->body_html  = (string) ( $m['body_html'] ?? '' );
				$row->body_debug = (string) ( $m['body_debug'] ?? '' );
				$pdb->update( $this->table(), array( 'body' => $row->body, 'body_html' => $row->body_html, 'body_debug' => $row->body_debug ), array( 'id' => (int) $row->id ) );
			}
		}
		return rest_ensure_response( array( 'item' => $this->item_out( $row, true ) ) );
	}

	private function ids( WP_REST_Request $request ) {
		return array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
	}

	/** Delete = move to the Zoho Trash (recoverable there) and hide here for good, so the next read does not bring it back. */
	public function rest_delete( WP_REST_Request $request ) {
		$ids = $this->ids( $request );
		if ( ! $ids || ! $this->ensure_table() ) {
			return new WP_Error( 'bad_request', __( 'Select emails first.', 'ajcore' ), array( 'status' => 400 ) );
		}
		$pdb   = $this->pdb();
		$tbl   = $this->table();
		$ph    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = (array) $pdb->get_results( $pdb->prepare( "SELECT id, mailbox, message_id FROM `{$tbl}` WHERE status = 'marketing' AND id IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$zoho  = class_exists( 'AJCore_Zoho_Mail' ) ? AJCore_Zoho_Mail::instance() : null;
		$done  = 0;
		$notes = array();
		foreach ( $rows as $r ) {
			$note = '';
			if ( $zoho && '' !== (string) $r->mailbox ) {
				$res = $zoho->trash_message( (string) $r->message_id, (string) $r->mailbox );
				if ( is_wp_error( $res ) ) {
					$note    = $res->get_error_message();
					$notes[] = $note;
				}
			}
			$pdb->update( $tbl, array( 'status' => 'deleted', 'trash_note' => mb_substr( $note, 0, 250 ), 'body' => null, 'body_html' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $r->id ) );
			$done++;
		}
		return rest_ensure_response( array( 'deleted' => $done, 'zoho_errors' => array_values( array_unique( $notes ) ) ) );
	}

	/** Not marketing after all: drop the label; the pipeline picks the email up again on its next read. */
	public function rest_restore( WP_REST_Request $request ) {
		$ids = $this->ids( $request );
		if ( ! $ids || ! $this->ensure_table() ) {
			return new WP_Error( 'bad_request', __( 'Select emails first.', 'ajcore' ), array( 'status' => 400 ) );
		}
		$pdb = $this->pdb();
		$ph  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$n   = $pdb->query( $pdb->prepare( "DELETE FROM `{$this->table()}` WHERE status = 'marketing' AND id IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// A pipeline that stores its own cursor re-reads from the newest mail; reset the mail pipelines so restored emails come back at once.
		foreach ( array_keys( AJCore_Mail_Pipelines::pipelines() ) as $pid ) {
			$s = get_option( 'ajcore_mp_' . $pid );
			if ( is_array( $s ) ) {
				$s['cursor'] = 0;
				update_option( 'ajcore_mp_' . $pid, $s, false );
			}
		}
		return rest_ensure_response( array( 'restored' => (int) $n ) );
	}

	public function rest_senders( WP_REST_Request $request ) {
		$list = self::senders();
		$add  = strtolower( trim( (string) $request->get_param( 'add' ) ) );
		$rem  = strtolower( trim( (string) $request->get_param( 'remove' ) ) );
		if ( '' !== $add && ! in_array( $add, $list, true ) && preg_match( '/^[a-z0-9@._\-]+$/', $add ) ) {
			$list[] = $add;
		}
		if ( '' !== $rem ) {
			$list = array_values( array_diff( $list, array( $rem ) ) );
		}
		update_option( self::SENDERS, $list, false );
		return rest_ensure_response( array( 'senders' => $list ) );
	}

	// ── WP admin view (a sub-tab of Pipeline) ───────────────────────────────────

	public function render_view() {
		?>
		<div id="ajmk">
			<div class="ajmr-row ajmr-row-tools">
				<label class="ajmr-tile ajmr-search"><input type="search" id="ajmk-q" placeholder="<?php echo esc_attr__( 'Search subject, sender…', 'ajcore' ); ?>" autocomplete="off"></label>
				<select id="ajmk-pipe" class="ajmr-select"></select>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajmk-clear"><span><?php esc_html_e( 'Clear', 'ajcore' ); ?></span></button>
			</div>
			<div class="ajmr-filters"><span class="ajmr-fnote" id="ajmk-status"></span></div>
			<div id="ajmk-bulk" class="ajmr-note" hidden></div>
			<div class="ajmr-card"><table class="widefat striped" id="ajmk-table"><thead><tr><th style="width:28px"><input type="checkbox" id="ajmk-all"></th><th><?php esc_html_e( 'Time', 'ajcore' ); ?></th><th><?php esc_html_e( 'Email', 'ajcore' ); ?></th><th><?php esc_html_e( 'From pipeline', 'ajcore' ); ?></th></tr></thead><tbody></tbody></table></div>
			<div class="ajmr-panel" id="ajmk-senders-box"><h3><?php esc_html_e( 'Marketing senders (future mail is labelled automatically)', 'ajcore' ); ?></h3><div id="ajmk-senders"></div><p><input type="text" id="ajmk-sender-in" class="regular-text" placeholder="sender@domain.com or domain.com"> <button type="button" class="button" id="ajmk-sender-add"><?php esc_html_e( 'Add', 'ajcore' ); ?></button></p></div>
			<div class="ajmr-modal" id="ajmk-detail" hidden><div class="ajmr-modal-box"><div id="ajmk-detail-body"></div></div></div>
		</div>
		<script>
		( function () {
			var rest = <?php echo wp_json_encode( rest_url( 'ajcore/v1/ops/marketing' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var st = { q: '', pipeline: '' }, data = null, selected = {};
			function $( i ) { return document.getElementById( i ); }
			function el( t, c, x ) { var e = document.createElement( t ); if ( c ) { e.className = c; } if ( x != null ) { e.textContent = x; } return e; }
			function api( path, opts ) { opts = opts || {}; opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} ); return fetch( rest + path, opts ).then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) { throw new Error( d.message || 'Request failed' ); } return d; } ); } ); }
			function post( path, body ) { return api( path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } ); }
			function emailFrame( html ) { var f = document.createElement( 'iframe' ); f.setAttribute( 'sandbox', 'allow-popups' ); f.setAttribute( 'referrerpolicy', 'no-referrer' ); f.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\'; font-src https: data:"><base target="_blank"><style>body{margin:10px;font:14px/1.45 -apple-system,Segoe UI,Arial,sans-serif;color:#111;word-wrap:break-word}img{max-width:100%;height:auto}</style>' + html; f.style.cssText = 'width:100%;height:680px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;resize:vertical;'; return f; }
			function dayKey( s ) { return ( s || '' ).slice( 0, 10 ) || '—'; }
			function dayLabel( k ) { var d = new Date( k + 'T12:00:00' ); return isNaN( d ) ? k : d.toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } ); }
			function load() {
				var q = new URLSearchParams(); if ( st.q ) { q.set( 'q', st.q ); } if ( st.pipeline ) { q.set( 'pipeline', st.pipeline ); }
				api( '?' + q.toString() ).then( function ( d ) { data = d; render(); } ).catch( function ( e ) { $( 'ajmk-status' ).textContent = e.message; } );
			}
			function render() {
				var sel = $( 'ajmk-pipe' ); sel.innerHTML = ''; var o0 = el( 'option', null, 'All pipelines' ); o0.value = ''; sel.appendChild( o0 );
				Object.keys( data.pipelines ).forEach( function ( k ) { var n = data.counts[ k ] || 0; if ( ! n && k !== st.pipeline ) { return; } var o = el( 'option', null, data.pipelines[ k ] + ' (' + n + ')' ); o.value = k; if ( k === st.pipeline ) { o.selected = true; } sel.appendChild( o ); } );
				var tb = $( 'ajmk-table' ).querySelector( 'tbody' ); tb.innerHTML = '';
				if ( ! data.items.length ) { var r0 = el( 'tr' ), c0 = el( 'td', null, 'No marketing emails.' ); c0.colSpan = 4; r0.appendChild( c0 ); tb.appendChild( r0 ); }
				var days = [], by = {};
				data.items.forEach( function ( i ) { var k = dayKey( i.received_at ); if ( ! by[ k ] ) { by[ k ] = []; days.push( k ); } by[ k ].push( i ); } );
				days.sort().reverse().forEach( function ( k ) {
					var hr = el( 'tr', 'ajra-pu-day' ), hc = el( 'td', null, dayLabel( k ) + '  ·  ' + by[ k ].length ); hc.colSpan = 4; hr.appendChild( hc ); tb.appendChild( hr );
					by[ k ].forEach( function ( i ) {
						var tr = el( 'tr' ); tr.style.cursor = 'pointer';
						var td = el( 'td' ); td.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
						var cb = el( 'input' ); cb.type = 'checkbox'; cb.checked = !! selected[ i.id ]; cb.addEventListener( 'change', function () { if ( cb.checked ) { selected[ i.id ] = true; } else { delete selected[ i.id ]; } syncBulk(); } ); td.appendChild( cb ); tr.appendChild( td );
						tr.appendChild( el( 'td', null, ( i.received_at || '' ).slice( 11, 16 ) ) );
						var m = el( 'td' ); m.appendChild( el( 'strong', null, i.subject ) ); m.appendChild( el( 'div', 'description', i.sender ) ); tr.appendChild( m );
						tr.appendChild( el( 'td', null, data.pipelines[ i.pipeline ] || i.pipeline ) );
						tr.addEventListener( 'click', function () { openDetail( i.id ); } ); tb.appendChild( tr );
					} );
				} );
				var sb = $( 'ajmk-senders' ); sb.innerHTML = '';
				if ( ! data.senders.length ) { sb.appendChild( el( 'span', 'description', 'None yet.' ) ); }
				data.senders.forEach( function ( s ) { var b = el( 'button', 'button button-small', s + '  ×' ); b.type = 'button'; b.style.marginRight = '6px'; b.title = 'Remove'; b.addEventListener( 'click', function () { post( '/senders', { remove: s } ).then( load ); } ); sb.appendChild( b ); } );
				syncBulk();
			}
			function syncBulk() {
				var ids = Object.keys( selected ), box = $( 'ajmk-bulk' ); box.innerHTML = ''; box.hidden = ! ids.length; if ( ! ids.length ) { return; }
				box.appendChild( el( 'strong', null, ids.length + ' selected ' ) );
				var del = el( 'button', 'button button-primary', 'Delete (to Zoho Trash)' ); del.type = 'button'; del.addEventListener( 'click', function () { if ( ! confirm( 'Move ' + ids.length + ' email(s) to the Zoho Trash? They stay recoverable there.' ) ) { return; } post( '/delete', { ids: ids.map( Number ) } ).then( function ( d ) { selected = {}; $( 'ajmk-status' ).textContent = d.deleted + ' deleted' + ( d.zoho_errors.length ? ' · Zoho: ' + d.zoho_errors.join( ' | ' ) : '' ); load(); } ).catch( function ( e ) { alert( e.message ); } ); } );
				var res = el( 'button', 'button', 'Not marketing' ); res.type = 'button'; res.addEventListener( 'click', function () { post( '/restore', { ids: ids.map( Number ) } ).then( function () { selected = {}; load(); } ); } );
				box.appendChild( del ); box.appendChild( document.createTextNode( ' ' ) ); box.appendChild( res );
			}
			function openDetail( id ) {
				var wrap = $( 'ajmk-detail' ), box = $( 'ajmk-detail-body' ); box.innerHTML = ''; box.appendChild( el( 'p', null, 'Loading…' ) ); wrap.hidden = false;
				api( '/' + id ).then( function ( d ) {
					var i = d.item; box.innerHTML = ''; box.appendChild( el( 'h2', null, i.subject ) ); box.appendChild( el( 'p', 'description', i.sender + ' · ' + i.received_at ) );
					if ( i.body_debug ) { var w = el( 'div', null, 'Only the preview could be read: ' + i.body_debug ); w.style.cssText = 'color:#b45309;font-size:12px;margin:4px 0;'; box.appendChild( w ); }
					if ( i.body_html ) { box.appendChild( emailFrame( i.body_html ) ); } else { var pre = el( 'pre', null, i.body || i.summary ); pre.style.cssText = 'max-height:380px;overflow:auto;white-space:pre-wrap;font-size:12px;background:#f8fafc;padding:10px;border-radius:8px;'; box.appendChild( pre ); }
					var bar = el( 'p' ); function act( label, fn, primary ) { var b = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), label ); b.type = 'button'; b.addEventListener( 'click', fn ); bar.appendChild( b ); bar.appendChild( document.createTextNode( ' ' ) ); }
					act( 'Delete (to Zoho Trash)', function () { post( '/delete', { ids: [ id ] } ).then( function () { wrap.hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } ); }, true );
					act( 'Not marketing', function () { post( '/restore', { ids: [ id ] } ).then( function () { wrap.hidden = true; load(); } ); }, false );
					var cl = el( 'a', null, 'Close' ); cl.href = '#'; cl.addEventListener( 'click', function ( e ) { e.preventDefault(); wrap.hidden = true; } ); bar.appendChild( cl ); box.appendChild( bar );
				} ).catch( function ( e ) { box.innerHTML = ''; box.appendChild( el( 'p', null, e.message ) ); } );
			}
			var qt = null; $( 'ajmk-q' ).addEventListener( 'input', function ( e ) { clearTimeout( qt ); qt = setTimeout( function () { st.q = e.target.value.trim(); load(); }, 350 ); } );
			$( 'ajmk-pipe' ).addEventListener( 'change', function ( e ) { st.pipeline = e.target.value; load(); } );
			$( 'ajmk-clear' ).addEventListener( 'click', function () { st = { q: '', pipeline: '' }; $( 'ajmk-q' ).value = ''; selected = {}; load(); } );
			$( 'ajmk-all' ).addEventListener( 'change', function ( e ) { selected = {}; if ( e.target.checked && data ) { data.items.forEach( function ( i ) { selected[ i.id ] = true; } ); } render(); } );
			$( 'ajmk-detail' ).addEventListener( 'click', function ( e ) { if ( e.target === this ) { this.hidden = true; } } );
			document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { $( 'ajmk-detail' ).hidden = true; } } );
			$( 'ajmk-sender-add' ).addEventListener( 'click', function () { var v = $( 'ajmk-sender-in' ).value.trim(); if ( v ) { post( '/senders', { add: v } ).then( function () { $( 'ajmk-sender-in' ).value = ''; load(); } ); } } );
			load();
		} )();
		</script>
		<?php
	}
}
