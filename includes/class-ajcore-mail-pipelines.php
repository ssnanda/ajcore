<?php
/**
 * Mailbox sub-pipelines under Pipeline (engine). Extensions register the ones they want through the 'ajcore_mail_pipelines' filter:
 *   id => array( 'label' => 'Invoices', 'module' => 'invoices', 'stages' => array( 'new' => 'New', ..., 'ignored' => 'Ignored' ) )
 * (first stage = new, last = ignored). AJCore-RA registers Invoices / Insurance / HOA; AJCore-HOA registers its own.
 *
 * Each one reads a Zoho shared mailbox (AJCore's Zoho login, the address is set per pipeline) with no filters (every message),
 * lists the emails by date, and lets staff move each through stages with a note. No parsing: the email itself is the record.
 * Items live in one table (aj_mail_items) keyed by pipeline + message id.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Mail_Pipelines {

	const CRON = 'ajcore_mail_pipelines_pass';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** id => label, ops module key, stages. Empty unless an extension registers some. */
	public static function pipelines() {
		$out = array();
		foreach ( (array) apply_filters( 'ajcore_mail_pipelines', array() ) as $id => $p ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || empty( $p['label'] ) || empty( $p['stages'] ) || ! is_array( $p['stages'] ) ) {
				continue;
			}
			$out[ $id ] = array( 'label' => (string) $p['label'], 'module' => ! empty( $p['module'] ) ? (string) $p['module'] : $id, 'stages' => $p['stages'], 'label_stages' => isset( $p['label_stages'] ) && is_array( $p['label_stages'] ) ? $p['label_stages'] : array(), 'parser' => isset( $p['parser'] ) && is_callable( $p['parser'] ) ? $p['parser'] : null, 'parser_v' => isset( $p['parser_v'] ) ? (int) $p['parser_v'] : 0 );
		}
		return $out;
	}

	public static function init() {
		add_filter( 'ajcore_pipeline_views', array( __CLASS__, 'register_views' ) );
		add_filter( 'ajcore_ops_modules', array( __CLASS__, 'ops_modules' ) );
		add_filter( 'ajcore_ops_sections', array( __CLASS__, 'ops_sections' ) );
		add_filter( 'ajcore_ops_route_sections', array( __CLASS__, 'ops_route_sections' ) );
		add_filter( 'ajcore_ops_counts', array( __CLASS__, 'ops_counts' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_pass' ) );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + 1200, 'hourly', self::CRON );
			}
		} );
	}

	// ── registration with AJCore ────────────────────────────────────────────────

	public static function register_views( $views ) {
		foreach ( self::pipelines() as $id => $p ) {
			$views[ $id ] = array(
				'label'  => $p['label'],
				'badge'  => static function () use ( $id ) {
					return self::new_count( $id );
				},
				'render' => static function () use ( $id ) {
					self::instance()->render_view( $id );
				},
			);
		}
		return $views;
	}

	public static function ops_modules( $modules ) {
		$modules = (array) $modules;
		foreach ( self::pipelines() as $p ) {
			$modules[] = $p['module'];
		}
		return $modules;
	}

	public static function ops_sections( $sections ) {
		$sections = (array) $sections;
		foreach ( self::pipelines() as $p ) {
			$sections[ $p['module'] ] = $p['label'];
		}
		return $sections;
	}

	public static function ops_route_sections( $map ) {
		$map = (array) $map;
		foreach ( self::pipelines() as $p ) {
			$map[ $p['module'] ] = $p['module'];
		}
		return $map;
	}

	public static function ops_counts( $counts ) {
		try {
			foreach ( self::pipelines() as $id => $p ) {
				$n                       = self::new_count( $id );
				$counts[ $p['module'] ] = array( 'value' => $n, 'label' => 'New', 'alert' => $n > 0 );
			}
		} catch ( \Throwable $e ) {
			// Counts are optional.
		}
		return $counts;
	}

	public static function catalog( $catalog ) {
		$catalog   = (array) $catalog;
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/mail-pipelines/{pipeline}', 'auth' => 'Admin', 'purpose' => 'Items of a mailbox sub-pipeline (invoices, insurance): filters stage, q; stats per stage.', 'app' => 'OPS pipeline' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail-pipelines/{pipeline}/(process|stage|settings)', 'auth' => 'Admin', 'purpose' => 'Read the mailbox now; move items to a stage; save mailbox/enabled.', 'app' => 'OPS pipeline' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET|POST', 'path' => '/ops/mail-pipelines/{pipeline}/items/{id}', 'auth' => 'Admin', 'purpose' => 'One email with its text (fetched on first open); POST edits note/stage.', 'app' => 'OPS pipeline' );
		return $catalog;
	}

	public static function new_count( $id ) {
		$self = self::instance();
		if ( ! isset( self::pipelines()[ $id ] ) || ! $self->ensure_table() ) {
			return 0;
		}
		$pdb = $self->pdb();
		return (int) $pdb->get_var( $pdb->prepare( "SELECT COUNT(*) FROM `{$self->table()}` WHERE pipeline = %s AND stage = 'new' AND label = ''", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		return $this->pdb()->prefix . 'aj_mail_items';
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
			if ( ! $pdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE 'label'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pdb->query( "ALTER TABLE `{$tbl}` ADD COLUMN label varchar(16) NOT NULL DEFAULT '' AFTER stage" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( ! $pdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE 'body_html'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pdb->query( "ALTER TABLE `{$tbl}` ADD COLUMN body_html mediumtext NULL AFTER body" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			// Parsed fields (filled by a pipeline's parser): what the email is, its policy / account / reference number, amount, due date, property and tenant.
			foreach ( array(
				'kind'          => "varchar(40) NOT NULL DEFAULT ''",
				'ref_no'        => "varchar(100) NOT NULL DEFAULT ''",
				'amount'        => 'decimal(12,2) NULL',
				'due_date'      => 'date NULL',
				'property_tag'  => "varchar(190) NOT NULL DEFAULT ''",
				'property_name' => "varchar(190) NOT NULL DEFAULT ''",
				'tenant'        => "varchar(190) NOT NULL DEFAULT ''",
				'facts'         => 'longtext NULL',
				'facts_v'       => 'smallint(5) unsigned NOT NULL DEFAULT 0',
			) as $col => $def ) {
				if ( ! $pdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$pdb->query( "ALTER TABLE `{$tbl}` ADD COLUMN {$col} {$def}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			if ( ! get_option( 'ajcore_mp_invoice_stages_v2' ) ) { // Invoices: New > Verified > Paid (was Approved)
				$pdb->query( "UPDATE `{$tbl}` SET stage = 'verified' WHERE pipeline = 'invoices' AND stage = 'approved'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				update_option( 'ajcore_mp_invoice_stages_v2', '1', false );
			}
			return $done = true;
		}
		$charset = $pdb->get_charset_collate();
		$pdb->query( "CREATE TABLE IF NOT EXISTS `{$tbl}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline varchar(40) NOT NULL,
			message_id varchar(190) NOT NULL,
			subject varchar(500) NOT NULL DEFAULT '',
			sender varchar(255) NOT NULL DEFAULT '',
			summary text NULL,
			body mediumtext NULL,
			body_html mediumtext NULL,
			body_debug text NULL,
			received_at datetime NULL,
			stage varchar(20) NOT NULL DEFAULT 'new',
			label varchar(16) NOT NULL DEFAULT '',
			notes text NULL,
			kind varchar(40) NOT NULL DEFAULT '',
			ref_no varchar(100) NOT NULL DEFAULT '',
			amount decimal(12,2) NULL,
			due_date date NULL,
			property_tag varchar(190) NOT NULL DEFAULT '',
			property_name varchar(190) NOT NULL DEFAULT '',
			tenant varchar(190) NOT NULL DEFAULT '',
			facts longtext NULL,
			facts_v smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pipe_msg (pipeline, message_id),
			KEY pipe_stage (pipeline, stage),
			KEY received (received_at)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $done = $kit['table_exists']( $pdb, $tbl );
	}

	// ── settings (per pipeline) ─────────────────────────────────────────────────

	public function settings( $id ) {
		$opt = get_option( 'ajcore_mp_' . $id, array() );
		return array_merge( array( 'mailbox' => '', 'enabled' => '1', 'since' => '2026-01-01', 'cursor' => 0, 'last_run' => array() ), is_array( $opt ) ? $opt : array() );
	}

	private function save_settings( $id, array $s ) {
		update_option( 'ajcore_mp_' . $id, $s, false );
	}

	private function zoho() {
		return class_exists( 'AJCore_Zoho_Mail' ) ? AJCore_Zoho_Mail::instance() : null;
	}

	// ── ingestion ───────────────────────────────────────────────────────────────

	/** Same walk as Purchases: newest page plus the not-yet-caught-up pages; stores each unseen message (no filters). */
	public function run_pass( $id, $max_new = 60 ) {
		$zoho = $this->zoho();
		$s    = $this->settings( $id );
		if ( ! $zoho || ! $zoho->is_connected() ) {
			return new WP_Error( 'zoho_not_connected', __( 'Zoho is not connected.', 'ajcore' ) );
		}
		if ( '' === $s['mailbox'] ) {
			return new WP_Error( 'no_mailbox', __( 'Set the mailbox first.', 'ajcore' ) );
		}
		if ( ! $this->ensure_table() ) {
			return new WP_Error( 'no_table', __( 'The table could not be created.', 'ajcore' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$pdb     = $this->pdb();
		$tbl     = $this->table();
		$summary = array( 'scanned' => 0, 'new' => 0, 'errors' => array(), 'complete' => false );
		$cursor  = max( 0, (int) $s['cursor'] );
		$pages   = array_unique( array( 0, $cursor ) );
		$guard   = 0;
		$t0      = microtime( true );
		$stopped = false;
		while ( $pages && $summary['new'] < $max_new && $guard++ < 40 && microtime( true ) - $t0 <= 25 ) {
			$start = (int) array_shift( $pages );
			$rows  = $zoho->get_recent_messages( 50, 'all', $start, $s['mailbox'] );
			if ( is_wp_error( $rows ) ) {
				$summary['errors'][] = $rows->get_error_message();
				break;
			}
			$summary['scanned'] += count( $rows );
			$ids   = wp_list_pluck( $rows, 'message_id' );
			$known = array();
			if ( $ids ) {
				$ph    = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
				$known = array_flip( (array) $pdb->get_col( $pdb->prepare( "SELECT message_id FROM `{$tbl}` WHERE pipeline = %s AND message_id IN ({$ph})", array_merge( array( $id ), $ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$all_known = true;
			$older     = 0;
			foreach ( $rows as $row ) {
				if ( '' !== (string) $row['received_at'] && (string) $row['received_at'] < $s['since'] . ' 00:00:00' ) {
					$older++;
					continue; // before the window (2026 by default)
				}
				if ( isset( $known[ $row['message_id'] ] ) ) {
					continue;
				}
				$sender_txt = trim( $row['from_name'] . ' <' . $row['from_email'] . '>' );
				$label      = AJCore_Marketing::is_marketing_sender( $sender_txt ) ? 'marketing' : ''; // a marked sender is labelled as it comes in
				$all_known = false;
				if ( $summary['new'] >= $max_new || microtime( true ) - $t0 > 25 ) { // each request stays short: the browser asks again
					$stopped = true;
					break;
				}
				$pdb->insert( $tbl, array(
					'pipeline'    => $id,
					'message_id'  => $row['message_id'],
					'subject'     => mb_substr( (string) $row['subject'], 0, 500 ),
					'sender'      => mb_substr( trim( $row['from_name'] . ' <' . $row['from_email'] . '>' ), 0, 255 ),
					'summary'     => (string) $row['summary'],
					'received_at' => '' !== (string) $row['received_at'] ? $row['received_at'] : current_time( 'mysql', true ),
					'stage'       => 'new',
					'label'       => $label,
					'created_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
				) );
				$summary['new']++;
			}
			if ( $rows && $older === count( $rows ) && ! $stopped ) { // a whole page older than the window: nothing newer is left to read
				$summary['complete'] = true;
				$cursor              = 0;
				break;
			}
			if ( count( $rows ) < 50 && ! $stopped ) {
				$summary['complete'] = $start >= $cursor;
				if ( $start >= $cursor ) {
					$cursor = 0;
				}
				break;
			}
			if ( $start === $cursor && $all_known ) {
				$cursor  = $start + 50;
				$pages[] = $cursor;
			}
		}
		$s['cursor']   = $cursor;
		$s['last_run'] = array( 'at' => current_time( 'mysql' ), 'new' => $summary['new'], 'scanned' => $summary['scanned'], 'errors' => $summary['errors'] );
		$this->save_settings( $id, $s );
		$summary['staged'] = $this->apply_label_stages( $id );
		return $summary;
	}

	/**
	 * Zoho labels set the stage: a pipeline's label_stages (e.g. PAID => paid) moves every email carrying that Zoho label to that stage
	 * (from New / Verified only; Ignored and later stages are left alone). Reads the label's message list, writes nothing to Zoho.
	 */
	public function apply_label_stages( $id ) {
		$p = self::pipelines();
		$p = isset( $p[ $id ] ) ? $p[ $id ] : array();
		$map = isset( $p['label_stages'] ) ? $p['label_stages'] : array();
		$zoho = $this->zoho();
		$s    = $this->settings( $id );
		if ( ! $map || ! $zoho || '' === $s['mailbox'] ) {
			return 0;
		}
		$ck = 'ajcore_mp_lbl_' . md5( $s['mailbox'] );
		$labels = get_transient( $ck );
		if ( ! is_array( $labels ) ) {
			$labels = $zoho->list_labels( $s['mailbox'] );
			if ( is_wp_error( $labels ) ) {
				return 0;
			}
			set_transient( $ck, $labels, 10 * MINUTE_IN_SECONDS );
		}
		$pdb   = $this->pdb();
		$tbl   = $this->table();
		$moved = 0;
		foreach ( $map as $name => $stage ) {
			if ( ! isset( $p['stages'][ $stage ] ) ) {
				continue;
			}
			foreach ( $labels as $l ) {
				if ( 0 !== strcasecmp( (string) $l['name'], (string) $name ) ) {
					continue;
				}
				$mids = array();
				for ( $start = 0, $pg = 0; $pg < 10; $pg++, $start += 200 ) {
					$rows = $zoho->get_messages_by_label( $l['id'], $s['mailbox'], $start, 200 );
					if ( is_wp_error( $rows ) || ! $rows ) {
						break;
					}
					foreach ( $rows as $r ) {
						$mids[] = (string) $r['message_id'];
					}
					if ( count( $rows ) < 200 ) {
						break;
					}
				}
				foreach ( array_chunk( $mids, 100 ) as $chunk ) {
					$ph = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
					$moved += (int) $pdb->query( $pdb->prepare( "UPDATE `{$tbl}` SET stage = %s, updated_at = %s WHERE pipeline = %s AND stage IN ('new', 'verified', 'approved') AND stage <> %s AND message_id IN ({$ph})", array_merge( array( $stage, current_time( 'mysql' ), $id, $stage ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		}
		return $moved;
	}

	// ── links: a policy / account number remembered against a property ───────────

	private function links_table() {
		return $this->pdb()->prefix . 'aj_mail_links';
	}

	private function ensure_links() {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		$pdb = $this->pdb();
		$kit = $this->kit();
		$tbl = $this->links_table();
		if ( ! $kit['table_exists']( $pdb, $tbl ) ) {
			$charset = $pdb->get_charset_collate();
			$pdb->query( "CREATE TABLE IF NOT EXISTS `{$tbl}` (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pipeline varchar(40) NOT NULL,
				link_type varchar(20) NOT NULL,
				link_key varchar(120) NOT NULL,
				property_tag varchar(190) NOT NULL DEFAULT '',
				property_name varchar(190) NOT NULL DEFAULT '',
				tenant varchar(190) NOT NULL DEFAULT '',
				created_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY pipe_key (pipeline, link_type, link_key)
			) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $done = $kit['table_exists']( $pdb, $tbl );
	}

	/** The property remembered for a reference number (type "ref": a policy, account or association account number), or null. */
	public static function link_lookup( $pipeline, $type, $key ) {
		$self = self::instance();
		$key  = strtolower( trim( (string) $key ) );
		if ( '' === $key || ! $self->ensure_links() ) {
			return null;
		}
		$pdb = $self->pdb();
		$row = $pdb->get_row( $pdb->prepare( "SELECT property_tag, property_name, tenant FROM `{$self->links_table()}` WHERE pipeline = %s AND link_type = %s AND link_key = %s", $pipeline, $type, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/** Remembers a property for a key; later emails with the same key take it. Returns the number of already stored emails updated. */
	public function link_set( $pipeline, $type, $key, $tag, $name, $tenant = '' ) {
		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key || ! $this->ensure_links() ) {
			return 0;
		}
		$pdb = $this->pdb();
		if ( in_array( $type, array( 'assoc', 'vendor' ), true ) ) {
			// An association key is only usable while every email names the same property; a second property makes it ambiguous (empty).
			$pdb->query( $pdb->prepare( "INSERT INTO `{$this->links_table()}` (pipeline, link_type, link_key, property_tag, property_name, tenant, created_at) VALUES (%s, %s, %s, %s, %s, '', %s) ON DUPLICATE KEY UPDATE property_name = IF(property_tag = VALUES(property_tag), property_name, ''), property_tag = IF(property_tag = VALUES(property_tag), property_tag, '')", $pipeline, $type, $key, $tag, $name, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return 0;
		}
		$pdb->query( $pdb->prepare( "INSERT INTO `{$this->links_table()}` (pipeline, link_type, link_key, property_tag, property_name, tenant, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE property_tag = VALUES(property_tag), property_name = VALUES(property_name), tenant = IF(VALUES(tenant) <> '', VALUES(tenant), tenant)", $pipeline, $type, $key, $tag, $name, $tenant, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $pdb->query( $pdb->prepare( "UPDATE `{$this->table()}` SET property_tag = %s, property_name = %s WHERE pipeline = %s AND LOWER(ref_no) = %s AND property_tag = ''", $tag, $name, $pipeline, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Runs a pipeline's parser over stored mail that has not been parsed by the current parser version (facts_v < parser_v). Fetches the
	 * email text from Zoho when it is not stored yet (up to $max_fetch per call), stays within $budget seconds. The parser gets
	 * ( item, text, html ) and returns kind / ref_no / amount / due_date / property_tag / property_name / tenant / facts / label.
	 */
	public function parse_items( $id, $max_fetch = 20, $budget = 25 ) {
		$p      = self::pipelines();
		$p      = isset( $p[ $id ] ) ? $p[ $id ] : array();
		$parser = isset( $p['parser'] ) ? $p['parser'] : null;
		$v      = isset( $p['parser_v'] ) ? (int) $p['parser_v'] : 0;
		$out    = array( 'parsed' => 0, 'fetched' => 0, 'left' => 0, 'errors' => array() );
		if ( ! $parser || $v < 1 || ! $this->ensure_table() ) {
			return $out;
		}
		$s    = $this->settings( $id );
		$zoho = $this->zoho();
		$pdb  = $this->pdb();
		$tbl  = $this->table();
		$t0   = microtime( true );
		$rows = (array) $pdb->get_results( $pdb->prepare( "SELECT id, message_id, subject, sender, summary, received_at, body, body_html, body_debug, label, stage FROM `{$tbl}` WHERE pipeline = %s AND facts_v < %d AND received_at >= %s AND ( facts IS NULL OR facts NOT LIKE '%%\"manual\"%%' ) ORDER BY received_at DESC LIMIT 600", $id, $v, $s['since'] . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			if ( microtime( true ) - $t0 > $budget ) {
				$out['left']++;
				continue;
			}
			$body = (string) $r->body;
			$html = (string) $r->body_html;
			if ( '' === trim( $body ) || null === $r->body_debug ) {
				if ( $out['fetched'] >= $max_fetch || ! $zoho || '' === $s['mailbox'] ) {
					$out['left']++;
					continue;
				}
				$m = $zoho->intake_message( (string) $r->message_id, $s['mailbox'] );
				$out['fetched']++;
				if ( is_wp_error( $m ) ) {
					$out['errors'][] = $m->get_error_message();
					$out['left']++;
					continue;
				}
				$body = mb_substr( (string) $m['body_text'], 0, 20000 );
				$html = (string) ( $m['body_html'] ?? '' );
				$pdb->update( $tbl, array( 'body' => $body, 'body_debug' => (string) ( $m['body_debug'] ?? '' ), 'body_html' => $html ), array( 'id' => (int) $r->id ) );
			}
			try {
				$f = call_user_func( $parser, array( 'subject' => (string) $r->subject, 'sender' => (string) $r->sender, 'summary' => (string) $r->summary, 'received_at' => (string) $r->received_at, 'pipeline' => $id ), '' !== trim( $body ) ? $body : (string) $r->summary, $html );
			} catch ( \Throwable $e ) {
				$out['errors'][] = $e->getMessage();
				$f               = array();
			}
			$f   = is_array( $f ) ? $f : array();
			$set = array(
				'kind'          => mb_substr( (string) ( $f['kind'] ?? '' ), 0, 40 ),
				'ref_no'        => mb_substr( (string) ( $f['ref_no'] ?? '' ), 0, 100 ),
				'amount'        => isset( $f['amount'] ) && is_numeric( $f['amount'] ) ? round( (float) $f['amount'], 2 ) : null,
				'due_date'      => ! empty( $f['due_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f['due_date'] ) ? (string) $f['due_date'] : null,
				'property_tag'  => mb_substr( (string) ( $f['property_tag'] ?? '' ), 0, 190 ),
				'property_name' => mb_substr( (string) ( $f['property_name'] ?? '' ), 0, 190 ),
				'tenant'        => mb_substr( (string) ( $f['tenant'] ?? '' ), 0, 190 ),
				'facts'         => ! empty( $f['facts'] ) && is_array( $f['facts'] ) ? wp_json_encode( $f['facts'] ) : '',
				'facts_v'       => $v,
			);
			if ( 'notice' === ( $f['label'] ?? '' ) && '' === (string) $r->label ) {
				$set['label'] = 'notice';
			}
			if ( ! empty( $f['stage'] ) && 'new' === (string) $r->stage && isset( $p['stages'][ $f['stage'] ] ) ) { // e.g. "Paid via ACH" moves a New invoice to Paid; later stages are never overridden
				$set['stage'] = (string) $f['stage'];
			}
			$pdb->update( $tbl, $set, array( 'id' => (int) $r->id ) );
			$out['parsed']++;
			foreach ( ( isset( $f['learn'] ) && is_array( $f['learn'] ) ) ? $f['learn'] : array() as $lk ) { // a property learned for a policy / account / association: its other emails take it
				if ( ! empty( $lk['key'] ) && ! empty( $lk['property_tag'] ) ) {
					$this->link_set( $id, (string) ( $lk['type'] ?? 'ref' ), (string) $lk['key'], (string) $lk['property_tag'], (string) ( $lk['property_name'] ?? '' ), (string) ( $lk['tenant'] ?? '' ) );
					$out['learned'] = ( isset( $out['learned'] ) ? $out['learned'] : 0 ) + 1;
				}
			}
		}
		if ( ! empty( $out['learned'] ) ) { // earlier emails that were waiting for a property are parsed again
			$out['left'] += (int) $pdb->query( $pdb->prepare( "UPDATE `{$tbl}` SET facts_v = 0 WHERE pipeline = %s AND property_tag = '' AND facts LIKE %s", $id, '%"no_property"%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$out['errors'] = array_values( array_unique( $out['errors'] ) );
		return $out;
	}

	public function rest_parse( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		return rest_ensure_response( $this->parse_items( $id ) );
	}

	/** Removes stored mail older than the window (2026 by default). The mailbox itself is not touched. */
	public function purge_old( $id ) {
		if ( ! $this->ensure_table() ) {
			return new WP_Error( 'no_table', __( 'The table could not be created.', 'ajcore' ) );
		}
		$s   = $this->settings( $id );
		$pdb = $this->pdb();
		$n   = (int) $pdb->query( $pdb->prepare( "DELETE FROM `{$this->table()}` WHERE pipeline = %s AND received_at < %s", $id, $s['since'] . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$s['cursor'] = 0;
		$this->save_settings( $id, $s );
		return array( 'since' => $s['since'], 'removed' => $n );
	}

	public function rest_purge( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		$r = $this->purge_old( $id );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function cron_pass() {
		$self = self::instance();
		foreach ( array_keys( self::pipelines() ) as $id ) {
			$s = $self->settings( $id );
			if ( '1' === $s['enabled'] && '' !== $s['mailbox'] ) {
				$self->run_pass( $id, 60 );
				for ( $i = 0; $i < 6; $i++ ) { // read new emails' details (type, policy / account #, amount, property), a few batches per hour
					$r = $self->parse_items( $id, 20, 25 );
					if ( empty( $r['left'] ) || ( empty( $r['parsed'] ) && empty( $r['fetched'] ) ) ) {
						break;
					}
				}
			}
		}
	}

	// ── REST ────────────────────────────────────────────────────────────────────

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public function register_routes() {
		$ns   = AJCore_Extensions::rest_namespace();
		$ops  = array( 'AJCore_Extensions', 'can_manage_ops' );
		$base = '/ops/mail-pipelines/(?P<pipe>[a-z0-9_-]+)';
		register_rest_route( $ns, $base, array( 'methods' => 'GET', 'callback' => array( $this, 'rest_list' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/process', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_process' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/parse', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_parse' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/purge', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_purge' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/stage', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_stage' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/marketing', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_marketing' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/marketing/delete', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_delete_marketing' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/senders', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_senders' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/settings', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_settings' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/items/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_item' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/items/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_item_update' ), 'permission_callback' => $ops ) );
	}

	private function pipe( WP_REST_Request $request ) {
		$id = (string) $request['pipe'];
		return isset( self::pipelines()[ $id ] ) ? $id : null;
	}

	private function bad_pipe() {
		return new WP_Error( 'unknown_pipeline', __( 'Unknown pipeline.', 'ajcore' ), array( 'status' => 404 ) );
	}

	private function item_out( $r, $with_body = false ) {
		$out = array( 'id' => (int) $r->id, 'subject' => (string) $r->subject, 'sender' => (string) $r->sender, 'summary' => (string) $r->summary, 'received_at' => (string) $r->received_at, 'stage' => (string) $r->stage, 'label' => (string) $r->label, 'notes' => (string) $r->notes );
		$out['kind']          = isset( $r->kind ) ? (string) $r->kind : '';
		$out['ref_no']        = isset( $r->ref_no ) ? (string) $r->ref_no : '';
		$out['amount']        = isset( $r->amount ) && null !== $r->amount ? (float) $r->amount : null;
		$out['due_date']      = isset( $r->due_date ) ? (string) $r->due_date : '';
		$out['property_tag']  = isset( $r->property_tag ) ? (string) $r->property_tag : '';
		$out['property_name'] = isset( $r->property_name ) ? (string) $r->property_name : '';
		$out['tenant']        = isset( $r->tenant ) ? (string) $r->tenant : '';
		if ( isset( $r->facts ) && '' !== (string) $r->facts ) {
			$f = json_decode( (string) $r->facts, true );
			$out['facts'] = is_array( $f ) ? $f : array();
		}
		if ( $with_body ) {
			$out['body']       = (string) $r->body;
			$out['body_html']  = (string) $r->body_html;
			$out['body_debug'] = (string) $r->body_debug;
		}
		return $out;
	}

	public function rest_list( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		if ( ! $this->ensure_table() ) {
			return new WP_Error( 'no_table', __( 'The table could not be created.', 'ajcore' ), array( 'status' => 500 ) );
		}
		$pdb    = $this->pdb();
		$tbl    = $this->table();
		$where  = array( 'pipeline = %s' );
		$params = array( $id );
		$lab    = sanitize_key( (string) $request->get_param( 'label' ) );
		$view   = in_array( $lab, array( 'marketing', 'notice' ), true ) ? $lab : '';
		$where[] = '' === $view ? "label = ''" : "label = '{$view}'"; // the normal list hides labelled mail; a label shows only its own
		$kind   = sanitize_key( (string) $request->get_param( 'kind' ) );
		if ( '' !== $kind ) {
			$where[]  = 'kind = %s';
			$params[] = $kind;
		}
		$issue = sanitize_key( (string) $request->get_param( 'issue' ) );
		if ( in_array( $issue, array( 'no_property', 'no_ref', 'no_amount', 'unparsed' ), true ) ) {
			$where[] = 'unparsed' === $issue ? 'facts_v = 0' : "facts LIKE '%%\"{$issue}\"%%'";
		}
		$stage  = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( '' === $view && isset( self::pipelines()[ $id ]['stages'][ $stage ] ) ) {
			$where[]  = 'stage = %s';
			$params[] = $stage;
		}
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like     = '%' . $pdb->esc_like( $q ) . '%';
			$where[]  = '( subject LIKE %s OR sender LIKE %s OR summary LIKE %s OR notes LIKE %s )';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$limit = min( 500, max( 1, (int) ( $request->get_param( 'limit' ) ?: 300 ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = (array) $pdb->get_results( $pdb->prepare( "SELECT id, subject, sender, summary, received_at, stage, label, notes, kind, ref_no, amount, due_date, property_tag, property_name, tenant, facts FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY received_at DESC, id DESC LIMIT {$limit}", $params ) );
		$counts = array_fill_keys( array_keys( self::pipelines()[ $id ]['stages'] ), 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $pdb->get_results( $pdb->prepare( "SELECT stage, COUNT(*) AS n FROM `{$tbl}` WHERE pipeline = %s AND label = '' GROUP BY stage", $id ) ) as $c ) {
			$counts[ (string) $c->stage ] = (int) $c->n;
		}
		$marketing = (int) $pdb->get_var( $pdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE pipeline = %s AND label = 'marketing'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$notices   = (int) $pdb->get_var( $pdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE pipeline = %s AND label = 'notice'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$kinds     = array();
		foreach ( (array) $pdb->get_results( $pdb->prepare( "SELECT kind, COUNT(*) AS n FROM `{$tbl}` WHERE pipeline = %s AND label = '' AND kind <> '' GROUP BY kind ORDER BY n DESC", $id ) ) as $k ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$kinds[ (string) $k->kind ] = (int) $k->n;
		}
		$issues = array();
		foreach ( array( 'no_property', 'no_ref', 'no_amount' ) as $iss ) {
			$issues[ $iss ] = (int) $pdb->get_var( $pdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE pipeline = %s AND label = '' AND facts LIKE %s", $id, '%"' . $iss . '"%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$unparsed = (int) $pdb->get_var( $pdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE pipeline = %s AND label = '' AND facts_v < %d AND received_at >= %s", $id, (int) self::pipelines()[ $id ]['parser_v'], $this->settings( $id )['since'] . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$s = $this->settings( $id );
		return rest_ensure_response( array(
			'label'    => self::pipelines()[ $id ]['label'],
			'stages'   => self::pipelines()[ $id ]['stages'],
			'items'    => array_map( array( $this, 'item_out' ), $rows ),
			'counts'   => $counts,
			'marketing' => $marketing,
			'notices'  => $notices,
			'kinds'    => $kinds,
			'issues'   => $issues,
			'parsable' => null !== self::pipelines()[ $id ]['parser'],
			'unparsed' => $unparsed,
			'senders'  => AJCore_Marketing::senders(),
			'mailbox'  => $s['mailbox'],
			'enabled'  => '1' === (string) $s['enabled'],
			'last_run' => $s['last_run'],
		) );
	}

	public function rest_process( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		$r = $this->run_pass( $id );
		return is_wp_error( $r ) ? new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( $r );
	}

	public function rest_stage( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );
		$ids   = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $ids || ! isset( self::pipelines()[ $id ]['stages'][ $stage ] ) || ! $this->ensure_table() ) {
			return new WP_Error( 'bad_request', __( 'Select items and a stage.', 'ajcore' ), array( 'status' => 400 ) );
		}
		$pdb = $this->pdb();
		$ph  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $pdb->query( $pdb->prepare( "UPDATE `{$this->table()}` SET stage = %s, updated_at = %s WHERE pipeline = %s AND id IN ({$ph})", array_merge( array( $stage, current_time( 'mysql' ), $id ), $ids ) ) );
		return rest_ensure_response( array( 'updated' => (int) $n, 'stage' => $stage ) );
	}

	/** Set or clear the Marketing label on emails. Body: ids, label ('marketing' | '' to clear), sender_rule ('' | 'address' | 'domain'). */
	public function rest_marketing( WP_REST_Request $request ) {
		$id  = $this->pipe( $request );
		$ids = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $id || ! $ids || ! $this->ensure_table() ) {
			return new WP_Error( 'bad_request', __( 'Select emails first.', 'ajcore' ), array( 'status' => 400 ) );
		}
		$label = 'marketing' === sanitize_key( (string) $request->get_param( 'label' ) ) ? 'marketing' : '';
		$rule  = sanitize_key( (string) $request->get_param( 'sender_rule' ) );
		$pdb   = $this->pdb();
		$ph    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		if ( 'marketing' === $label && in_array( $rule, array( 'address', 'domain' ), true ) ) {
			foreach ( (array) $pdb->get_col( $pdb->prepare( "SELECT sender FROM `{$this->table()}` WHERE pipeline = %s AND id IN ({$ph})", array_merge( array( $id ), $ids ) ) ) as $sender ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				AJCore_Marketing::add_sender( (string) $sender, 'domain' === $rule );
			}
		}
		// Only live mail changes label (a deleted one stays deleted).
		$n = $pdb->query( $pdb->prepare( "UPDATE `{$this->table()}` SET label = %s, updated_at = %s WHERE pipeline = %s AND label <> 'deleted' AND id IN ({$ph})", array_merge( array( $label, current_time( 'mysql' ), $id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return rest_ensure_response( array( 'updated' => (int) $n, 'label' => $label ) );
	}

	/** Delete labelled marketing mail: moved to the Zoho Trash (recoverable there); a hidden record stays so the next read does not bring it back. */
	public function rest_delete_marketing( WP_REST_Request $request ) {
		$id  = $this->pipe( $request );
		$ids = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $id || ! $ids || ! $this->ensure_table() ) {
			return new WP_Error( 'bad_request', __( 'Select emails first.', 'ajcore' ), array( 'status' => 400 ) );
		}
		$mailbox = $this->settings( $id )['mailbox'];
		$pdb     = $this->pdb();
		$ph      = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows    = (array) $pdb->get_results( $pdb->prepare( "SELECT id, message_id FROM `{$this->table()}` WHERE pipeline = %s AND label = 'marketing' AND id IN ({$ph})", array_merge( array( $id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$zoho    = $this->zoho();
		$errors  = array();
		$done    = 0;
		foreach ( $rows as $r ) {
			if ( $zoho && '' !== $mailbox ) {
				$res = $zoho->trash_message( (string) $r->message_id, $mailbox );
				if ( is_wp_error( $res ) ) {
					$errors[] = $res->get_error_message();
				}
			}
			$pdb->update( $this->table(), array( 'label' => 'deleted', 'body' => null, 'body_html' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $r->id ) );
			$done++;
		}
		return rest_ensure_response( array( 'deleted' => $done, 'zoho_errors' => array_values( array_unique( $errors ) ) ) );
	}

	/** Remove a marketing sender rule. */
	public function rest_senders( WP_REST_Request $request ) {
		$add = strtolower( trim( (string) $request->get_param( 'add' ) ) );
		$rem = strtolower( trim( (string) $request->get_param( 'remove' ) ) );
		if ( '' !== $add && preg_match( '/^[a-z0-9@._\-]+$/', $add ) ) {
			AJCore_Marketing::add_sender( false === strpos( $add, '@' ) ? 'x@' . $add : $add, false === strpos( $add, '@' ) );
		}
		if ( '' !== $rem ) {
			AJCore_Marketing::remove_sender( $rem );
		}
		return rest_ensure_response( array( 'senders' => AJCore_Marketing::senders() ) );
	}

	public function rest_settings( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		$s            = $this->settings( $id );
		$s['mailbox'] = strtolower( sanitize_email( (string) $request->get_param( 'mailbox' ) ) );
		$s['enabled'] = $request->get_param( 'enabled' ) ? '1' : '0';
		$s['cursor']  = 0;
		$this->save_settings( $id, $s );
		return rest_ensure_response( array( 'mailbox' => $s['mailbox'], 'enabled' => '1' === $s['enabled'] ) );
	}

	private function load_item( $id, $item_id ) {
		$pdb = $this->pdb();
		return $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$this->table()}` WHERE pipeline = %s AND id = %d", $id, absint( $item_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** One email with its text; the text is fetched from Zoho the first time it is opened and kept. */
	public function rest_item( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id || ! $this->ensure_table() ) {
			return $this->bad_pipe();
		}
		$row = $this->load_item( $id, $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Not found.', 'ajcore' ), array( 'status' => 404 ) );
		}
		$zoho    = $this->zoho();
		$mailbox = $this->settings( $id )['mailbox'];
		if ( ( null === $row->body_debug || null === $row->body_html || '' !== (string) $row->body_debug ) && $zoho && '' !== $mailbox ) { // never fetched, no rich HTML yet, or the last try only got the preview
			$m = $zoho->intake_message( (string) $row->message_id, $mailbox );
			if ( ! is_wp_error( $m ) ) {
				$row->body       = mb_substr( (string) $m['body_text'], 0, 20000 );
				$row->body_debug = (string) ( $m['body_debug'] ?? '' );
				$row->body_html  = (string) ( $m['body_html'] ?? '' );
				$this->pdb()->update( $this->table(), array( 'body' => $row->body, 'body_debug' => $row->body_debug, 'body_html' => $row->body_html ), array( 'id' => (int) $row->id ) );
			}
		}
		return rest_ensure_response( array( 'item' => $this->item_out( $row, true ) ) );
	}

	public function rest_item_update( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id || ! $this->ensure_table() ) {
			return $this->bad_pipe();
		}
		$row = $this->load_item( $id, $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Not found.', 'ajcore' ), array( 'status' => 404 ) );
		}
		$new = array( 'updated_at' => current_time( 'mysql' ) );
		if ( null !== $request->get_param( 'notes' ) ) {
			$new['notes'] = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		}
		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( '' !== $stage && isset( self::pipelines()[ $id ]['stages'][ $stage ] ) ) {
			$new['stage'] = $stage;
		}
		foreach ( array( 'property_tag', 'property_name', 'tenant', 'ref_no' ) as $f ) {
			if ( null !== $request->get_param( $f ) ) {
				$new[ $f ] = mb_substr( sanitize_text_field( (string) $request->get_param( $f ) ), 0, 190 );
			}
		}
		if ( null !== $request->get_param( 'amount' ) ) {
			$amt           = trim( (string) $request->get_param( 'amount' ) );
			$new['amount'] = '' === $amt ? null : round( (float) preg_replace( '/[^0-9.\-]/', '', $amt ), 2 );
		}
		if ( isset( $new['property_tag'] ) || isset( $new['tenant'] ) || isset( $new['ref_no'] ) || array_key_exists( 'amount', $new ) ) { // set by hand: kept when the parser runs again
			$fx           = json_decode( (string) $row->facts, true );
			$fx           = is_array( $fx ) ? $fx : array();
			$fx['manual'] = true;
			unset( $fx['issues'] );
			$new['facts']   = wp_json_encode( $fx );
			$new['facts_v'] = max( (int) $row->facts_v, (int) ( self::pipelines()[ $id ]['parser_v'] ?? 0 ) );
		}
		$this->pdb()->update( $this->table(), $new, array( 'id' => (int) $row->id ) );
		$linked = 0;
		if ( ! empty( $request->get_param( 'link' ) ) ) { // "use this property for every email with the same policy / account number"
			$ref = isset( $new['ref_no'] ) ? $new['ref_no'] : (string) $row->ref_no;
			$tag = isset( $new['property_tag'] ) ? $new['property_tag'] : (string) $row->property_tag;
			if ( '' !== $ref && '' !== $tag ) {
				$linked = $this->link_set( $id, 'ref', $ref, $tag, isset( $new['property_name'] ) ? $new['property_name'] : (string) $row->property_name, isset( $new['tenant'] ) ? $new['tenant'] : (string) $row->tenant );
			}
		}
		return rest_ensure_response( array( 'success' => true, 'linked' => $linked ) );
	}

	// ── WP admin view (inside the Pipeline tab) ─────────────────────────────────
	// Same screen as AJOps > Pipeline > <name>. Plain JS; text goes in via textContent.

	public function render_view( $id ) {
		$p = self::pipelines()[ $id ];
		$zoho = $this->zoho();
		if ( ! $zoho || ! $zoho->is_connected() ) {
			echo '<div class="ajmr-card ajmr-empty">' . esc_html__( 'Zoho is not connected. Open Connections above to connect it.', 'ajcore' ) . '</div>';
			return;
		}
		?>
		<div id="ajmp">
			<div class="ajmr-row" id="ajmp-tiles"></div>
			<div class="ajmr-row ajmr-row-tools">
				<label class="ajmr-tile ajmr-search"><input type="search" id="ajmp-q" placeholder="<?php echo esc_attr__( 'Search subject, sender, note…', 'ajcore' ); ?>" autocomplete="off"></label>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajmp-clear"><span><?php esc_html_e( 'Clear', 'ajcore' ); ?></span></button>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajmp-run"><span><?php esc_html_e( 'Sync', 'ajcore' ); ?></span></button>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajmp-purge"><span><?php esc_html_e( 'Purge old data', 'ajcore' ); ?></span></button>
			</div>
			<div class="ajmr-filters"><span class="ajmr-fnote" id="ajmp-status"></span></div>
			<div class="ajmp-issues" id="ajmp-issues" hidden></div>
			<div class="ajmr-panel" id="ajmp-settings" hidden>
				<h3><?php esc_html_e( 'Mailbox', 'ajcore' ); ?></h3>
				<p><input type="email" id="ajmp-mailbox" class="regular-text" placeholder="name@yourdomain.com"> <label><input type="checkbox" id="ajmp-enabled"> <?php esc_html_e( 'Read every hour', 'ajcore' ); ?></label> <button type="button" class="button button-primary" id="ajmp-save"><?php esc_html_e( 'Save', 'ajcore' ); ?></button></p>
			</div>
			<div class="ajmr-panel" id="ajmp-senders-box" hidden><h3><?php esc_html_e( 'Marketing senders (their future mail is labelled automatically)', 'ajcore' ); ?></h3><div id="ajmp-senders"></div><p><input type="text" id="ajmp-sender-in" class="regular-text" placeholder="sender@domain.com or domain.com"> <button type="button" class="button" id="ajmp-sender-add"><?php esc_html_e( 'Add', 'ajcore' ); ?></button></p></div>
			<div id="ajmp-bulk" class="ajmr-note" hidden></div>
			<div class="ajmr-card"><table class="widefat striped" id="ajmp-table"><thead><tr><th style="width:28px"><input type="checkbox" id="ajmp-all"></th><th><?php esc_html_e( 'Time', 'ajcore' ); ?></th><th><?php esc_html_e( 'Email', 'ajcore' ); ?></th><th class="ajmp-pc"><?php esc_html_e( 'Type', 'ajcore' ); ?></th><th class="ajmp-pc"><?php esc_html_e( 'Policy / account #', 'ajcore' ); ?></th><th class="ajmp-pc" style="text-align:right"><?php esc_html_e( 'Amount', 'ajcore' ); ?></th><th class="ajmp-pc"><?php esc_html_e( 'Property / tenant', 'ajcore' ); ?></th><th><?php esc_html_e( 'Stage', 'ajcore' ); ?></th></tr></thead><tbody></tbody></table></div>
			<div class="ajmr-modal" id="ajmp-detail" hidden><div class="ajmr-modal-box"><div id="ajmp-detail-body"></div></div></div>
		</div>
		<script>
		( function () {
			var PIPE = <?php echo wp_json_encode( $id ); ?>, rest = <?php echo wp_json_encode( rest_url( 'ajcore/v1/ops/mail-pipelines/' ) ); ?> + PIPE, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var st = { stage: '', q: '', label: '', kind: '', issue: '' }, data = null, selected = {};
			function $( i ) { return document.getElementById( i ); }
			function el( t, c, x ) { var e = document.createElement( t ); if ( c ) { e.className = c; } if ( x != null ) { e.textContent = x; } return e; }
			function api( path, opts ) { opts = opts || {}; opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} ); return fetch( rest + path, opts ).then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) { throw new Error( d.message || 'Request failed' ); } return d; } ); } ); }
			function post( path, body ) { return api( path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } ); }
			function emailFrame( html ) { var f = document.createElement( 'iframe' ); f.setAttribute( 'sandbox', 'allow-popups' ); f.setAttribute( 'referrerpolicy', 'no-referrer' ); f.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\'; font-src https: data:"><base target="_blank"><style>body{margin:10px;font:14px/1.45 -apple-system,Segoe UI,Arial,sans-serif;color:#111;word-wrap:break-word}img{max-width:100%;height:auto}</style>' + html; f.style.cssText = 'width:100%;height:680px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;resize:vertical;'; return f; }
			var KIND = { bill: 'Bill', past_due: 'Past due', cancel_notice: 'Cancellation', reinstatement: 'Reinstated', renewal: 'Renewal', payment: 'Payment', application: 'Application', policy_change: 'Policy change', interested_party: 'Interested party', renters_proof: 'Renters proof', renters_required: 'Renters required', audit: 'Audit', agency: 'Agency', scan: 'Scan', policy_notice: 'Policy notice', charge: 'Charge', statement: 'Statement', upcoming_payment: 'Upcoming payment', recurring_payment: 'Recurring payment', violation: 'Violation', arc: 'ARC', work_order: 'Work order', board_task: 'Board task', request: 'Request', board_thread: 'Board / management', account: 'Account', info: 'Info', promo: 'Promo', other: 'Other', ledger_request: 'Ledger request', counsel: 'Counsel', litigation: 'Litigation', bankruptcy: 'Bankruptcy', lien: 'Lien', foreclosure: 'Foreclosure', legal_bill: 'Legal bill', enforcement: 'Enforcement', vendor: 'Vendor', status_check: 'Status check', docs_sent: 'Documents sent', reply: 'Our reply', approval: 'Approved', question: 'Question', ack: 'Acknowledged', lender_request: 'Lender request', terminated: 'Terminated', transfer: 'Ownership transfer', fee: 'Fee', resale_request: 'Resale request', payoff: 'Payoff', certificate: 'Certificate', premium: 'Premium', claim: 'Claim', nonrenewal: 'Non-renewal', binder: 'Binder', collection: 'Collection', utility: 'Utility', paid: 'Paid', receipt: 'Receipt', vendor_thread: 'Vendor thread', quote: 'Quote' };
			function dayKey( s ) { return ( s || '' ).slice( 0, 10 ) || '—'; }
			function dayLabel( k ) { var d = new Date( k + 'T12:00:00' ); return isNaN( d ) ? k : d.toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } ); }
			function load() {
				var q = new URLSearchParams(); if ( st.stage ) { q.set( 'stage', st.stage ); } if ( st.q ) { q.set( 'q', st.q ); } if ( st.label ) { q.set( 'label', st.label ); } if ( st.kind ) { q.set( 'kind', st.kind ); } if ( st.issue ) { q.set( 'issue', st.issue ); }
				api( '?' + q.toString() ).then( function ( d ) { var first = ! data; data = d; render(); if ( first && d.mailbox && ! ( d.last_run && typeof d.last_run.at === 'string' ) ) { $( 'ajmp-run' ).click(); } if ( ! d.mailbox ) { $( 'ajmp-settings' ).hidden = false; } } ).catch( function ( e ) { $( 'ajmp-status' ).textContent = e.message; } );
			}
			function render() {
				var tiles = $( 'ajmp-tiles' ); tiles.innerHTML = '';
				Object.keys( data.stages ).forEach( function ( k ) {
					if ( k === 'ignored' ) { return; }
					var t = el( 'a', 'ajmr-tile ajmr-stat' + ( st.stage === k ? ' is-on' : '' ) ); t.href = '#';
					var tx = el( 'span', 'ajmr-tile-txt' ); tx.appendChild( el( 'small', null, data.stages[ k ] ) ); tx.appendChild( el( 'strong', null, String( data.counts[ k ] || 0 ) ) ); t.appendChild( tx );
					t.addEventListener( 'click', function ( e ) { e.preventDefault(); st.label = ''; st.stage = st.stage === k ? '' : k; selected = {}; load(); } ); tiles.appendChild( t );
				} );
				// The Marketing label: a filter on this pipeline's own mail (not a pipeline of its own).
				var mt = el( 'a', 'ajmr-tile ajmr-stat' + ( st.label === 'marketing' ? ' is-on' : '' ) ); mt.href = '#'; mt.title = 'Emails labelled Marketing: see, preview and delete them';
				var mtx = el( 'span', 'ajmr-tile-txt' ); mtx.appendChild( el( 'small', null, 'Marketing' ) ); mtx.appendChild( el( 'strong', null, String( data.marketing || 0 ) ) ); mt.appendChild( mtx );
				mt.addEventListener( 'click', function ( e ) { e.preventDefault(); st.label = st.label === 'marketing' ? '' : 'marketing'; st.stage = ''; selected = {}; load(); } ); tiles.appendChild( mt );
				var nt = el( 'a', 'ajmr-tile ajmr-stat' + ( st.label === 'notice' ? ' is-on' : '' ) ); nt.href = '#'; nt.title = 'Newsletters, announcements, account and login mail';
				var ntx = el( 'span', 'ajmr-tile-txt' ); ntx.appendChild( el( 'small', null, 'Notices' ) ); ntx.appendChild( el( 'strong', null, String( data.notices || 0 ) ) ); nt.appendChild( ntx );
				nt.addEventListener( 'click', function ( e ) { e.preventDefault(); st.label = st.label === 'notice' ? '' : 'notice'; st.stage = ''; st.issue = ''; st.kind = ''; selected = {}; load(); } ); tiles.appendChild( nt );
				// Parsed pipelines (Insurance, HOA): type + needs-attention filters and the extra columns.
				var iss = $( 'ajmp-issues' ); iss.innerHTML = ''; iss.hidden = ! data.parsable;
				document.querySelectorAll( '.ajmp-pc' ).forEach( function ( c ) { c.style.display = data.parsable ? '' : 'none'; } );
				if ( data.parsable ) {
					var ks = Object.keys( data.kinds || {} ), ksel = el( 'select' ); ksel.appendChild( new Option( 'All types', '' ) );
					ks.forEach( function ( k ) { ksel.appendChild( new Option( ( KIND[ k ] || k ) + ' (' + data.kinds[ k ] + ')', k ) ); } ); ksel.value = st.kind || ''; ksel.addEventListener( 'change', function () { st.kind = ksel.value; selected = {}; load(); } ); iss.appendChild( ksel );
					iss.appendChild( el( 'span', 'ajmp-fix', 'Needs attention:' ) );
					[ [ 'no_property', 'No property' ], [ 'no_ref', 'No policy / account #' ], [ 'no_amount', 'No amount' ] ].forEach( function ( x ) {
						var n = ( data.issues || {} )[ x[ 0 ] ] || 0, on = st.issue === x[ 0 ]; var b = el( 'a', 'ajmp-chip' + ( on ? ' is-on' : '' ) + ( n ? '' : ' is-zero' ) ); b.href = '#'; b.appendChild( document.createTextNode( x[ 1 ] ) ); b.appendChild( el( 'b', null, String( n ) ) );
						b.addEventListener( 'click', function ( e ) { e.preventDefault(); if ( ! n && ! on ) { return; } st.issue = on ? '' : x[ 0 ]; st.label = ''; selected = {}; load(); } ); iss.appendChild( b );
					} );
					if ( data.unparsed ) { iss.appendChild( el( 'span', 'ajmp-fix', data.unparsed + ' not read yet: click Sync' ) ); }
				}
				var sp = $( 'ajmp-senders-box' ); sp.hidden = st.label !== 'marketing'; var sl = $( 'ajmp-senders' ); sl.innerHTML = '';
				if ( ! ( data.senders || [] ).length ) { sl.appendChild( el( 'span', 'description', 'None yet.' ) ); }
				( data.senders || [] ).forEach( function ( s ) { var b = el( 'button', 'button button-small', s + '  ×' ); b.type = 'button'; b.style.marginRight = '6px'; b.title = 'Remove'; b.addEventListener( 'click', function () { post( '/senders', { remove: s } ).then( load ); } ); sl.appendChild( b ); } );
				var side = el( 'div', 'ajmr-mailboxes' ), g = el( 'button', 'ajmr-tile ajmr-tile-sm' ); g.type = 'button';
				var gt = el( 'span', 'ajmr-tile-txt' ); gt.appendChild( el( 'small', null, 'Mailbox' ) ); gt.appendChild( el( 'strong', null, data.mailbox ? data.mailbox.split( '@' )[ 0 ] : 'Not set' ) ); g.appendChild( gt );
				g.addEventListener( 'click', function () { var p = $( 'ajmp-settings' ); p.hidden = ! p.hidden; } ); side.appendChild( g ); tiles.appendChild( side );
				$( 'ajmp-mailbox' ).value = data.mailbox || ''; $( 'ajmp-enabled' ).checked = !! data.enabled;
				var lr = data.last_run, note = '';
				if ( lr && typeof lr.at === 'string' ) { note = 'Last pass ' + lr.at + ' · ' + lr.scanned + ' scanned · ' + lr[ 'new' ] + ' new' + ( lr.errors && lr.errors.length ? ' · ERROR: ' + lr.errors.join( ' | ' ) : '' ); }
				if ( note && ! $( 'ajmp-status' ).textContent ) { $( 'ajmp-status' ).textContent = note; }
				var tb = $( 'ajmp-table' ).querySelector( 'tbody' ); tb.innerHTML = '';
				if ( ! data.items.length ) { var r0 = el( 'tr' ), c0 = el( 'td', null, 'No emails.' ); c0.colSpan = 8; r0.appendChild( c0 ); tb.appendChild( r0 ); }
				var days = [], by = {};
				data.items.forEach( function ( i ) { var k = dayKey( i.received_at ); if ( ! by[ k ] ) { by[ k ] = []; days.push( k ); } by[ k ].push( i ); } );
				days.sort().reverse().forEach( function ( k ) {
					var hr = el( 'tr', 'ajra-pu-day' ), hc = el( 'td', null, dayLabel( k ) + '  ·  ' + by[ k ].length + ( by[ k ].length === 1 ? ' email' : ' emails' ) ); hc.colSpan = 8; hr.appendChild( hc ); tb.appendChild( hr );
					by[ k ].forEach( function ( i ) {
						var tr = el( 'tr' ); tr.style.cursor = 'pointer';
						var td = el( 'td' ); td.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
						var cb = el( 'input' ); cb.type = 'checkbox'; cb.checked = !! selected[ i.id ]; cb.addEventListener( 'change', function () { if ( cb.checked ) { selected[ i.id ] = true; } else { delete selected[ i.id ]; } syncBulk(); } ); td.appendChild( cb ); tr.appendChild( td );
						tr.appendChild( el( 'td', null, ( i.received_at || '' ).slice( 11, 16 ) ) );
						var m = el( 'td' ); m.appendChild( el( 'strong', null, i.subject ) ); m.appendChild( el( 'div', 'description', ( i.sender || '' ) + ( i.notes ? ' · ' + i.notes : '' ) ) ); tr.appendChild( m );
						if ( data.parsable ) {
							var kd = el( 'td' ); if ( i.kind ) { kd.appendChild( el( 'span', 'ajmp-kind' + ( /^(past_due|cancel_notice|violation|bankruptcy|foreclosure|lien|litigation|collection|nonrenewal)$/.test( i.kind ) ? ' is-hot' : '' ), KIND[ i.kind ] || i.kind ) ); } else { kd.textContent = '—'; } tr.appendChild( kd );
							var rf = el( 'td', null, i.ref_no || '—' ); rf.style.whiteSpace = 'nowrap'; tr.appendChild( rf );
							var am = el( 'td' ); am.style.cssText = 'text-align:right;white-space:nowrap;'; am.appendChild( document.createTextNode( i.amount == null ? '—' : '$' + Number( i.amount ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) ) ); if ( i.due_date ) { am.appendChild( el( 'div', 'description', ( i.kind === 'cancel_notice' ? 'effective ' : 'due ' ) + i.due_date ) ); } tr.appendChild( am );
							var pr = el( 'td' ); if ( i.property_name || i.property_tag ) { pr.appendChild( el( 'strong', null, i.property_tag || i.property_name ) ); if ( i.property_name && i.property_tag && i.property_name.toLowerCase().indexOf( i.property_tag.toLowerCase() ) !== 0 ) { pr.appendChild( el( 'div', 'description', i.property_name ) ); } } else if ( i.facts && i.facts.role === 'board' ) { pr.appendChild( el( 'span', 'description', 'board' ) ); } else if ( i.kind && ! /^(info|account|promo|agency|scan|board_thread|other|vendor|ack|reply|paid|receipt|counsel|vendor_thread|certificate|claim)$/.test( i.kind ) ) { pr.appendChild( el( 'span', 'ajmp-none', 'no property' ) ); } else { pr.textContent = '—'; } if ( i.tenant ) { pr.appendChild( el( 'div', 'description', i.tenant ) ); } else if ( i.facts && i.facts.association ) { pr.appendChild( el( 'div', 'description', i.facts.association ) ); } tr.appendChild( pr );
						}
						var s = el( 'td' ); s.appendChild( el( 'span', 'ajcore-status-pill ' + ( i.stage === 'new' ? '' : ( i.stage === 'ignored' ? 'dup' : 'active' ) ), data.stages[ i.stage ] || i.stage ) ); tr.appendChild( s );
						tr.addEventListener( 'click', function () { openDetail( i.id ); } ); tb.appendChild( tr );
					} );
				} );
				syncBulk();
			}
			function syncBulk() {
				var ids = Object.keys( selected ), box = $( 'ajmp-bulk' ); box.innerHTML = ''; box.hidden = ! ids.length; if ( ! ids.length ) { return; }
				box.appendChild( el( 'strong', null, ids.length + ' selected ' ) );
				if ( st.label === 'marketing' ) {
					var del = el( 'button', 'button button-primary', 'Delete (to Zoho Trash)' ); del.type = 'button'; del.addEventListener( 'click', function () { if ( ! confirm( 'Move ' + ids.length + ' email(s) to the Zoho Trash? They stay recoverable there.' ) ) { return; } post( '/marketing/delete', { ids: ids.map( Number ) } ).then( function ( d ) { selected = {}; $( 'ajmp-status' ).textContent = d.deleted + ' deleted' + ( d.zoho_errors.length ? ' · Zoho: ' + d.zoho_errors.join( ' | ' ) : '' ); load(); } ).catch( function ( e ) { alert( e.message ); } ); } );
					var nm = el( 'button', 'button', 'Not marketing' ); nm.type = 'button'; nm.addEventListener( 'click', function () { post( '/marketing', { ids: ids.map( Number ), label: '' } ).then( function () { selected = {}; load(); } ); } );
					box.appendChild( del ); box.appendChild( document.createTextNode( ' ' ) ); box.appendChild( nm ); return;
				}
				var mk = el( 'button', 'button', 'Marketing' ); mk.type = 'button'; mk.title = 'Label as Marketing'; mk.addEventListener( 'click', function () { var rule = confirm( 'Label as Marketing.\n\nAlso label future mail from these senders automatically?\nOK = yes (by sender address)   Cancel = only these emails' ) ? 'address' : ''; post( '/marketing', { ids: ids.map( Number ), label: 'marketing', sender_rule: rule } ).then( function () { selected = {}; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); box.appendChild( mk ); box.appendChild( document.createTextNode( ' ' ) );
				Object.keys( data.stages ).forEach( function ( k ) { var b = el( 'button', 'button', 'Mark ' + data.stages[ k ].toLowerCase() ); b.type = 'button'; b.addEventListener( 'click', function () { post( '/stage', { ids: ids.map( Number ), stage: k } ).then( function () { selected = {}; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); box.appendChild( b ); box.appendChild( document.createTextNode( ' ' ) ); } );
			}
			function openDetail( id ) {
				var wrap = $( 'ajmp-detail' ), box = $( 'ajmp-detail-body' ); box.innerHTML = ''; box.appendChild( el( 'p', null, 'Loading…' ) ); wrap.hidden = false;
				api( '/items/' + id ).then( function ( d ) {
					var i = d.item; box.innerHTML = ''; box.appendChild( el( 'h2', null, i.subject ) ); box.appendChild( el( 'p', 'description', i.sender + ' · ' + i.received_at ) );
					if ( i.body_debug ) { var w = el( 'div', null, 'Only the preview could be read: ' + i.body_debug ); w.style.cssText = 'color:#b45309;font-size:12px;margin:4px 0;'; box.appendChild( w ); }
					if ( i.body_html ) { box.appendChild( emailFrame( i.body_html ) ); } var pre = el( 'pre', null, i.body || i.summary ); if ( i.body_html ) { pre.hidden = true; var tg = el( 'a', null, 'Show plain text' ); tg.href = '#'; tg.addEventListener( 'click', function ( ev ) { ev.preventDefault(); pre.hidden = ! pre.hidden; } ); box.appendChild( tg ); } pre.style.cssText = 'max-height:380px;overflow:auto;white-space:pre-wrap;font-size:12px;background:#f8fafc;padding:10px;border-radius:8px;'; box.appendChild( pre );
					var pf = null;
					if ( data.parsable ) {
						pf = {}; var fb = el( 'div', 'ajmp-facts' );
						[ [ 'property_tag', 'Property (address)' ], [ 'tenant', 'Tenant / insured' ], [ 'ref_no', 'Policy / account #' ], [ 'amount', 'Amount' ] ].forEach( function ( f ) { var lb = el( 'label' ); lb.appendChild( el( 'span', null, f[ 1 ] ) ); var inp = el( 'input' ); inp.type = 'text'; inp.value = i[ f[ 0 ] ] == null ? '' : i[ f[ 0 ] ]; pf[ f[ 0 ] ] = inp; lb.appendChild( inp ); fb.appendChild( lb ); } );
						var lk = el( 'label', 'ajmp-link' ); var lkc = el( 'input' ); lkc.type = 'checkbox'; lkc.checked = ! i.property_tag; lk.appendChild( lkc ); lk.appendChild( document.createTextNode( ' Use this property for every email with the same policy / account #' ) ); pf.link = lkc; fb.appendChild( lk );
						if ( i.facts ) { var fx = []; [ 'carrier', 'category', 'policy_type', 'effective', 'association', 'role', 'late_fee', 'decision', 'request_type' ].forEach( function ( k ) { if ( i.facts[ k ] ) { fx.push( k.replace( '_', ' ' ) + ': ' + i.facts[ k ] ); } } ); if ( i.facts.policy_nos && i.facts.policy_nos.length ) { fx.push( 'policies: ' + i.facts.policy_nos.join( ', ' ) ); } if ( i.facts.note ) { fx.push( i.facts.note ); } if ( fx.length ) { fb.appendChild( el( 'div', 'description', fx.join( '  ·  ' ) ) ); } }
						box.appendChild( fb );
					}
					var notes = el( 'textarea' ); notes.rows = 2; notes.className = 'large-text'; notes.value = i.notes; notes.placeholder = 'Note'; box.appendChild( notes );
					var bar = el( 'p' ); function act( label, stage, primary ) { var b = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), label ); b.type = 'button'; b.addEventListener( 'click', function () { var body = { notes: notes.value }; if ( stage ) { body.stage = stage; } if ( pf ) { [ 'property_tag', 'tenant', 'ref_no', 'amount' ].forEach( function ( k ) { body[ k ] = pf[ k ].value; } ); if ( pf.property_tag.value !== ( i.property_tag || '' ) ) { body.property_name = ''; } if ( pf.link.checked ) { body.link = 1; } } post( '/items/' + id, body ).then( function () { wrap.hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); bar.appendChild( b ); bar.appendChild( document.createTextNode( ' ' ) ); }
					var mkb = el( 'button', 'button', st.label === 'marketing' ? 'Not marketing' : 'Marketing' ); mkb.type = 'button'; mkb.addEventListener( 'click', function () { var on = st.label !== 'marketing'; var rule = on && confirm( 'Label as Marketing.\n\nAlso label future mail from this sender automatically?\nOK = yes   Cancel = only this email' ) ? 'address' : ''; post( '/marketing', { ids: [ id ], label: on ? 'marketing' : '', sender_rule: rule } ).then( function () { wrap.hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } ); } );
					act( 'Save note', '', true ); bar.appendChild( mkb ); bar.appendChild( document.createTextNode( ' ' ) );
					if ( st.label === 'marketing' ) { var dl = el( 'button', 'button', 'Delete (to Zoho Trash)' ); dl.type = 'button'; dl.addEventListener( 'click', function () { post( '/marketing/delete', { ids: [ id ] } ).then( function () { wrap.hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); bar.appendChild( dl ); bar.appendChild( document.createTextNode( ' ' ) ); }
					Object.keys( data.stages ).forEach( function ( k ) { if ( k !== i.stage ) { act( 'Mark ' + data.stages[ k ].toLowerCase(), k, false ); } } );
					var close = el( 'a', null, 'Close' ); close.href = '#'; close.addEventListener( 'click', function ( e ) { e.preventDefault(); wrap.hidden = true; } ); bar.appendChild( close ); box.appendChild( bar );
				} ).catch( function ( e ) { box.innerHTML = ''; box.appendChild( el( 'p', null, e.message ) ); } );
			}
			var qt = null; $( 'ajmp-q' ).addEventListener( 'input', function ( e ) { clearTimeout( qt ); qt = setTimeout( function () { st.q = e.target.value.trim(); load(); }, 350 ); } );
			$( 'ajmp-sender-add' ).addEventListener( 'click', function () { var v = $( 'ajmp-sender-in' ).value.trim(); if ( v ) { post( '/senders', { add: v } ).then( function () { $( 'ajmp-sender-in' ).value = ''; load(); } ); } } );
			$( 'ajmp-clear' ).addEventListener( 'click', function () { st = { stage: '', q: '', label: '', kind: '', issue: '' }; $( 'ajmp-q' ).value = ''; selected = {}; load(); } );
			$( 'ajmp-all' ).addEventListener( 'change', function ( e ) { selected = {}; if ( e.target.checked && data ) { data.items.forEach( function ( i ) { selected[ i.id ] = true; } ); } render(); } );
			$( 'ajmp-detail' ).addEventListener( 'click', function ( e ) { if ( e.target === this ) { this.hidden = true; } } );
			document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { $( 'ajmp-detail' ).hidden = true; } } );
			$( 'ajmp-save' ).addEventListener( 'click', function () { post( '/settings', { mailbox: $( 'ajmp-mailbox' ).value.trim(), enabled: $( 'ajmp-enabled' ).checked ? 1 : 0 } ).then( function () { $( 'ajmp-status' ).textContent = 'Saved. Reading…'; load(); setTimeout( function () { $( 'ajmp-run' ).click(); }, 300 ); } ).catch( function ( e ) { $( 'ajmp-status' ).textContent = e.message; } ); } );
			$( 'ajmp-purge' ).addEventListener( 'click', function () {
				if ( ! confirm( 'Remove every stored email received before 2026?\n\n(The mailbox itself is not touched.)' ) ) { return; }
				$( 'ajmp-status' ).textContent = 'Purging…';
				post( '/purge', {} ).then( function ( d ) { $( 'ajmp-status' ).textContent = 'Purged before ' + d.since + ': ' + d.removed + ' emails removed'; selected = {}; load(); } ).catch( function ( e ) { $( 'ajmp-status' ).textContent = e.message; } );
			} );
			$( 'ajmp-run' ).addEventListener( 'click', function () {
				if ( ! data || ! data.mailbox ) { $( 'ajmp-status' ).textContent = 'Set the mailbox first (gear tile), then Save.'; $( 'ajmp-settings' ).hidden = false; return; }
				// Sync = read the mailbox to the end, then read each email and fill in its details (type, policy / account #, amount, property).
				var b = this, stx = $( 'ajmp-status' ), nNew = 0, nScan = 0, errs = [], rd = 0, bad = 0; b.disabled = true; stx.textContent = 'Syncing: reading the mailbox…';
				function readAll() {
					return post( '/process', {} ).then( function ( d ) {
						nNew += d.new || 0; nScan += d.scanned || 0; stx.textContent = 'Syncing: reading the mailbox… ' + nNew + ' new';
						if ( d.errors && d.errors.length ) { bad++; errs = d.errors; } else { bad = 0; errs = []; }
						if ( ! d.complete && bad < 3 && ++rd < 25 ) { return readAll(); }
						return d.complete;
					} );
				}
				readAll().then( function ( complete ) {
					var msg = nNew + ' new, ' + nScan + ' scanned' + ( complete ? '' : ' (more to read: Sync again)' ) + ( errs.length ? ' · ' + errs.join( ' | ' ) : '' );
					if ( ! data || ! data.parsable ) { stx.textContent = msg; return; }
					var n = 0, done = 0, perr = [];
					function again() {
						return post( '/parse', {} ).then( function ( r ) { done += r.parsed || 0; if ( r.errors && r.errors.length ) { perr = r.errors; } stx.textContent = 'Syncing: reading emails… ' + done + ' done' + ( r.left ? ', ' + r.left + ' to go' : '' ); if ( r.left && r.parsed + r.fetched > 0 && ++n < 120 ) { return again(); } return r.left || 0; } );
					}
					return again().then( function ( left ) { stx.textContent = msg + ' · ' + done + ' emails read' + ( left ? ', ' + left + ' left: Sync again' : ': all done' ) + ( perr.length ? ' · ' + perr.join( ' | ' ) : '' ); } );
				} ).catch( function ( e ) { stx.textContent = e.message; } ).then( function () { b.disabled = false; load(); } );
			} );
			load();
		} )();
		</script>
		<?php
	}
}
