<?php
/**
 * One-click provisioning for the private feedback destination behind the Rate Us
 * prompt: an AJ Forms form plus the page that renders it. Both are created only
 * when they are missing, so the button is safe to press twice, and neither is
 * removed automatically — an administrator deletes them like any other content.
 */
defined( 'ABSPATH' ) || exit;

final class AJCore_Reviews_Setup {
	const OPTION     = 'ajcore_reviews_feedback';
	const FORM_TITLE = 'What Can We Improve';
	const PAGE_SLUG  = 'what-can-we-improve';

	private static function forms_table() { global $wpdb; return $wpdb->prefix . 'aj_forms_forms'; }

	/** AJ Forms ships with AJ Core, but the table only exists once activation has run. */
	public static function forms_available() {
		global $wpdb;
		$table = self::forms_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function stored() { return (array) get_option( self::OPTION, array() ); }

	/** The page we created previously, or an existing page already sitting on the slug. */
	public static function page_id() {
		$stored = self::stored();
		$id     = (int) ( $stored['page_id'] ?? 0 );
		if ( $id && get_post_type( $id ) === 'page' && ! in_array( get_post_status( $id ), array( false, 'trash' ), true ) ) { return $id; }
		$page = get_page_by_path( self::PAGE_SLUG );
		return $page ? (int) $page->ID : 0;
	}

	/** The form we created previously, or an existing form already using the title. */
	public static function form_id() {
		global $wpdb;
		if ( ! self::forms_available() ) { return 0; }
		$stored = self::stored();
		$table  = self::forms_table();
		$id     = (int) ( $stored['form_id'] ?? 0 );
		if ( $id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND status != 'deleted'", $id ) ) === $id ) { return $id; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE title = %s AND status != 'deleted' ORDER BY id ASC LIMIT 1", self::FORM_TITLE ) );
	}

	/**
	 * The URL to offer as the private feedback destination: the real permalink
	 * when the page exists, otherwise where the page would be created.
	 */
	public static function suggested_url() {
		$page = self::page_id();
		$url  = $page ? get_permalink( $page ) : home_url( '/' . self::PAGE_SLUG . '/' );
		return is_string( $url ) ? $url : '';
	}

	/** True when that URL is a page that actually exists right now. */
	public static function page_exists() { return self::page_id() > 0; }

	/**
	 * Fields for the feedback form. Everything except the feedback itself is
	 * optional: a visitor who picked 1–4 stars is being asked what went wrong,
	 * and demanding contact details first is the quickest way to lose the answer.
	 */
	public static function schema() {
		$field = function( $id, $type, $label, $required = false, $extra = array() ) {
			return array_merge( array(
				'id'            => $id,
				'type'          => $type,
				'label'         => $label,
				'field_name'    => $id,
				'placeholder'   => '',
				'required'      => (bool) $required,
				'css_class'     => '',
				'width'         => 100,
				'help_text'     => '',
				'default_value' => '',
				'conversational'    => false,
				'conversation_step' => 'final_contact',
				'branch_map'    => array(),
				'flow_rules'    => array(),
			), $extra );
		};
		$options = function( $values ) {
			return array_map( function( $value ) { return array( 'label' => $value, 'value' => $value ); }, $values );
		};

		return array(
			'version' => 1,
			'source'  => 'ajcore',
			'fields'  => array(
				$field( 'experience', 'select', __( 'How would you rate your visit?', 'ajcore' ), false, array(
					'options' => $options( array(
						__( 'Very good', 'ajcore' ), __( 'Good', 'ajcore' ), __( 'Okay', 'ajcore' ),
						__( 'Poor', 'ajcore' ), __( 'Very poor', 'ajcore' ),
					) ),
				) ),
				$field( 'feedback', 'textarea', __( 'What can we improve?', 'ajcore' ), true, array(
					'placeholder' => __( 'Tell us what happened and what we could have done better.', 'ajcore' ),
					'help_text'   => __( 'This goes straight to the practice. It is not published anywhere.', 'ajcore' ),
				) ),
				$field( 'visitor_name', 'text', __( 'Your name (optional)', 'ajcore' ), false, array( 'width' => 50 ) ),
				$field( 'visitor_email', 'email', __( 'Email (optional)', 'ajcore' ), false, array(
					'width'     => 50,
					'help_text' => __( 'Only needed if you would like a reply.', 'ajcore' ),
				) ),
			),
			'settings' => array(
				'submit_text'     => __( 'Send Feedback', 'ajcore' ),
				'success_message' => __( 'Thank you — your feedback has been sent to our team.', 'ajcore' ),
				'form_description' => __( 'Your feedback is private. It is sent to the practice and is never published as a review or testimonial.', 'ajcore' ),
			),
		);
	}

	private static function page_content( $form_id ) {
		return "<!-- wp:paragraph -->\n<p>"
			. esc_html__( 'We are sorry your visit fell short. Tell us what happened — this goes privately to our team and is never published.', 'ajcore' )
			. "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[ajforms id=\"" . (int) $form_id . "\"]\n<!-- /wp:shortcode -->";
	}

	/**
	 * Create whatever is missing and return what the site now has. Idempotent:
	 * an existing form or page is adopted rather than duplicated, and a page that
	 * already renders some AJ Forms form is left untouched.
	 *
	 * @return array|WP_Error
	 */
	public static function provision() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'access_denied' ); }
		if ( ! self::forms_available() ) { return new WP_Error( 'forms_unavailable' ); }

		$created = array( 'form' => false, 'page' => false, 'shortcode' => false );
		$table   = self::forms_table();

		$form_id = self::form_id();
		if ( ! $form_id ) {
			$now      = current_time( 'mysql' );
			$inserted = $wpdb->insert( $table, array(
				'title'       => self::FORM_TITLE,
				'form_schema' => wp_json_encode( self::schema() ),
				'status'      => 'published',
				'created_at'  => $now,
				'updated_at'  => $now,
			), array( '%s', '%s', '%s', '%s', '%s' ) );
			if ( ! $inserted ) { return new WP_Error( 'storage_failed' ); }
			$form_id        = (int) $wpdb->insert_id;
			$created['form'] = true;
		}

		$page_id = self::page_id();
		if ( ! $page_id ) {
			$page_id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'What Can We Improve?', 'ajcore' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => self::page_content( $form_id ),
			), true );
			if ( is_wp_error( $page_id ) ) { return $page_id; }
			$page_id         = (int) $page_id;
			$created['page'] = true;
		} elseif ( false === strpos( (string) get_post_field( 'post_content', $page_id ), '[ajforms' ) ) {
			// The page exists but shows no form — add ours rather than replacing what is there.
			wp_update_post( array(
				'ID'           => $page_id,
				'post_content' => trim( (string) get_post_field( 'post_content', $page_id ) . "\n\n" . self::page_content( $form_id ) ),
			) );
			$created['shortcode'] = true;
		}

		update_option( self::OPTION, array( 'form_id' => $form_id, 'page_id' => $page_id ), false );

		$url = get_permalink( $page_id );
		return array(
			'form_id' => $form_id,
			'page_id' => $page_id,
			'url'     => is_string( $url ) ? $url : '',
			'created' => $created,
		);
	}
}
