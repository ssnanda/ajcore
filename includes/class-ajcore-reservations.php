<?php
/**
 * AJCore Reservation system.
 *
 * Handles the prepay-only, no-cancellation, no-refund, no-rescheduling
 * reservation flow for Stripe-backed reservation products (e.g. Conference Room).
 *
 * Business rules enforced here:
 *   - Overall availability window: 8:00 AM – 10:00 PM in configured timezone.
 *   - Business hours (Mon–Fri 9:00 AM – 5:00 PM) → business_hours pricing.
 *   - Everything else → after_hours_weekend pricing.
 *   - Local conflict check blocks: confirmed, paid, paid_pending_calendar.
 *   - Pending_payment blocks for 15 minutes then expires.
 *   - No cancellation, no refund, no rescheduling.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Reservations {

	const BOOKING_WINDOW_START_HOUR = 8;
	const BOOKING_WINDOW_END_HOUR   = 22;
	const BUSINESS_START_HOUR       = 9;
	const BUSINESS_END_HOUR         = 17;
	const PENDING_HOLD_MINUTES      = 15;

	// ──────────────────────────────────────────────────────────────────────────
	// Table helpers
	// ──────────────────────────────────────────────────────────────────────────

	public static function get_reservations_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_reservations';
	}

	public static function get_resources_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_reservation_resources';
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Pricing logic
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Determine whether a start time falls in business hours or after-hours/weekend.
	 *
	 * @param string $start_at_utc MySQL datetime string in UTC.
	 * @param string $timezone     PHP timezone string.
	 * @return string 'business_hours' or 'after_hours_weekend'.
	 */
	public static function determine_pricing_type( $start_at_utc, $timezone = 'America/New_York' ) {
		try {
			$tz = new DateTimeZone( $timezone );
			$dt = new DateTime( $start_at_utc, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( $tz );
		} catch ( Exception $e ) {
			return 'after_hours_weekend';
		}

		$dow  = (int) $dt->format( 'N' );
		$hour = (int) $dt->format( 'G' );
		$min  = (int) $dt->format( 'i' );

		$is_weekday      = $dow >= 1 && $dow <= 5;
		$hour_decimal    = $hour + ( $min / 60 );
		$in_business_hrs = $hour_decimal >= self::BUSINESS_START_HOUR
		                   && $hour_decimal < self::BUSINESS_END_HOUR;

		return ( $is_weekday && $in_business_hrs ) ? 'business_hours' : 'after_hours_weekend';
	}

	/**
	 * Validate that the requested time slot falls within 8:00 AM – 10:00 PM.
	 *
	 * @param string $start_at_utc UTC datetime string.
	 * @param string $end_at_utc   UTC datetime string.
	 * @param string $timezone     PHP timezone string.
	 * @return true|WP_Error
	 */
	public static function validate_booking_window( $start_at_utc, $end_at_utc, $timezone = 'America/New_York' ) {
		try {
			$tz         = new DateTimeZone( $timezone );
			$dt_start   = new DateTime( $start_at_utc, new DateTimeZone( 'UTC' ) );
			$dt_end     = new DateTime( $end_at_utc, new DateTimeZone( 'UTC' ) );
			$dt_start->setTimezone( $tz );
			$dt_end->setTimezone( $tz );
		} catch ( Exception $e ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Invalid reservation date or time.', 'ajforms' ) );
		}

		if ( $dt_start >= $dt_end ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservation end time must be after start time.', 'ajforms' ) );
		}

		$start_hour   = (int) $dt_start->format( 'G' );
		$start_min    = (int) $dt_start->format( 'i' );
		$end_hour     = (int) $dt_end->format( 'G' );
		$end_min      = (int) $dt_end->format( 'i' );
		$start_decimal = $start_hour + ( $start_min / 60 );
		$end_decimal   = $end_hour + ( $end_min / 60 );

		if ( $start_decimal < self::BOOKING_WINDOW_START_HOUR ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations cannot start before 8:00 AM.', 'ajforms' ) );
		}

		if ( $end_decimal > self::BOOKING_WINDOW_END_HOUR ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations cannot end after 10:00 PM.', 'ajforms' ) );
		}

		if ( $dt_start < new DateTime( 'now', $tz ) ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservation start time must be in the future.', 'ajforms' ) );
		}

		return true;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Conflict checking
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Check for local reservation conflicts for a resource.
	 *
	 * Blocks statuses: confirmed, paid, paid_pending_calendar.
	 * Also blocks pending_payment records created within the last PENDING_HOLD_MINUTES.
	 *
	 * @param int    $resource_id   DB ID of the reservation resource.
	 * @param string $start_at      MySQL datetime (UTC).
	 * @param string $end_at        MySQL datetime (UTC).
	 * @param string $exclude_uuid  Reservation UUID to exclude (for checking own record).
	 * @return true|WP_Error True if no conflict; WP_Error with code 'reservation_conflict'.
	 */
	public static function check_local_conflict( $resource_id, $start_at, $end_at, $exclude_uuid = '' ) {
		global $wpdb;

		$table = self::get_reservations_table();

		$hard_block_statuses   = array( 'confirmed', 'paid', 'paid_pending_calendar' );
		$status_placeholders   = implode( ',', array_fill( 0, count( $hard_block_statuses ), '%s' ) );
		$hold_cutoff           = gmdate( 'Y-m-d H:i:s', time() - ( self::PENDING_HOLD_MINUTES * 60 ) );

		$params = array_merge(
			$hard_block_statuses,
			array( $hold_cutoff, (int) $resource_id, $start_at, $end_at )
		);

		$where_uuid = '';
		if ( '' !== $exclude_uuid ) {
			$where_uuid = $wpdb->prepare( ' AND reservation_uuid <> %s', sanitize_text_field( $exclude_uuid ) );
		}

		$sql = $wpdb->prepare(
			"SELECT id, reservation_uuid, start_at, end_at, status
			 FROM `{$table}`
			 WHERE (
			     status IN ({$status_placeholders})
			     OR (status = 'pending_payment' AND created_at >= %s)
			 )
			 AND resource_id = %d
			 AND start_at < %s
			 AND end_at   > %s
			 LIMIT 1",
			$params
		);

		$conflict = $wpdb->get_row( $sql . $where_uuid );

		if ( $conflict ) {
			return new WP_Error(
				'reservation_conflict',
				__( 'The selected time slot is not available. Please choose a different time.', 'ajforms' ),
				array( 'conflicting_reservation' => (array) $conflict )
			);
		}

		return true;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Reservation lifecycle
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Generate a short friendly reference for a reservation (e.g. "RES-00042").
	 *
	 * @param int $reservation_id DB auto-increment ID.
	 * @return string
	 */
	public static function generate_friendly_reference( $reservation_id ) {
		return 'RES-' . str_pad( (string) absint( $reservation_id ), 5, '0', STR_PAD_LEFT );
	}

	/**
	 * Create a new reservation record in pending_payment status.
	 *
	 * @param array $data {
	 *     Required fields:
	 *     @type int    $wp_user_id
	 *     @type string $stripe_customer_id
	 *     @type int    $resource_id
	 *     @type string $resource_key
	 *     @type string $resource_name
	 *     @type string $stripe_price_id
	 *     @type string $pricing_type          'business_hours' or 'after_hours_weekend'
	 *     @type float  $amount
	 *     @type string $currency
	 *     @type string $start_at              UTC datetime
	 *     @type string $end_at                UTC datetime
	 *     @type string $timezone
	 *     @type string $customer_name
	 *     @type string $customer_email
	 *     @type string $customer_notes
	 *     Optional:
	 *     @type string $zoho_calendar_uid
	 *     @type string $zoho_calendar_id
	 *     @type string $zoho_resource_uid
	 * }
	 * @return array|WP_Error Array with 'reservation_uuid' and 'id', or WP_Error.
	 */
	public static function create_pending_reservation( $data ) {
		global $wpdb;

		$table = self::get_reservations_table();

		$uuid = wp_generate_uuid4();

		$insert = array(
			'reservation_uuid'     => $uuid,
			'stripe_customer_id'   => sanitize_text_field( (string) ( $data['stripe_customer_id'] ?? '' ) ),
			'wp_user_id'           => absint( $data['wp_user_id'] ?? 0 ),
			'resource_id'          => absint( $data['resource_id'] ?? 0 ),
			'resource_key'         => sanitize_key( $data['resource_key'] ?? '' ),
			'resource_name'        => sanitize_text_field( (string) ( $data['resource_name'] ?? '' ) ),
			'zoho_calendar_uid'    => sanitize_text_field( (string) ( $data['zoho_calendar_uid'] ?? '' ) ),
			'zoho_calendar_id'     => sanitize_text_field( (string) ( $data['zoho_calendar_id'] ?? '' ) ),
			'zoho_resource_uid'    => sanitize_text_field( (string) ( $data['zoho_resource_uid'] ?? '' ) ),
			'zoho_event_id'        => '',
			'stripe_checkout_session_id' => '',
			'stripe_payment_intent_id'   => '',
			'stripe_invoice_id'          => '',
			'stripe_price_id'      => sanitize_text_field( (string) ( $data['stripe_price_id'] ?? '' ) ),
			'pricing_type'         => in_array( $data['pricing_type'] ?? '', array( 'business_hours', 'after_hours_weekend' ), true )
			                          ? sanitize_key( $data['pricing_type'] )
			                          : 'after_hours_weekend',
			'amount'               => round( (float) ( $data['amount'] ?? 0 ), 2 ),
			'currency'             => strtolower( sanitize_key( $data['currency'] ?? 'usd' ) ),
			'start_at'             => sanitize_text_field( (string) ( $data['start_at'] ?? '' ) ),
			'end_at'               => sanitize_text_field( (string) ( $data['end_at'] ?? '' ) ),
			'timezone'             => sanitize_text_field( (string) ( $data['timezone'] ?? 'America/New_York' ) ),
			'status'               => 'pending_payment',
			'customer_name'        => sanitize_text_field( (string) ( $data['customer_name'] ?? '' ) ),
			'customer_email'       => sanitize_email( (string) ( $data['customer_email'] ?? '' ) ),
			'customer_notes'       => sanitize_textarea_field( (string) ( $data['customer_notes'] ?? '' ) ),
			'admin_notes'          => '',
			'raw_zoho_data'        => '',
			'raw_stripe_data'      => '',
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		);

		$formats = array(
			'%s', '%s', '%d', '%d', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%f', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s',
		);

		$result = $wpdb->insert( $table, $insert, $formats );

		if ( false === $result ) {
			return new WP_Error( 'reservation_db_error', __( 'Could not create reservation record.', 'ajforms' ) );
		}

		$id = (int) $wpdb->insert_id;

		self::log_reservation_event(
			'reservation_created',
			array(
				'reservation_uuid'   => $uuid,
				'reservation_id'     => $id,
				'stripe_customer_id' => $insert['stripe_customer_id'],
				'wp_user_id'         => $insert['wp_user_id'],
				'resource_key'       => $insert['resource_key'],
				'pricing_type'       => $insert['pricing_type'],
				'start_at'           => $insert['start_at'],
				'end_at'             => $insert['end_at'],
			)
		);

		return array(
			'id'               => $id,
			'reservation_uuid' => $uuid,
		);
	}

	/**
	 * Attach a Stripe checkout session ID to a pending reservation.
	 *
	 * @param string $reservation_uuid
	 * @param string $session_id
	 * @return bool
	 */
	public static function attach_stripe_checkout_session( $reservation_uuid, $session_id ) {
		global $wpdb;

		$table = self::get_reservations_table();

		$updated = $wpdb->update(
			$table,
			array(
				'stripe_checkout_session_id' => sanitize_text_field( (string) $session_id ),
				'status'                     => 'pending_payment',
				'updated_at'                 => current_time( 'mysql' ),
			),
			array( 'reservation_uuid' => sanitize_text_field( (string) $reservation_uuid ) ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		self::log_reservation_event(
			'reservation_payment_started',
			array(
				'reservation_uuid'           => $reservation_uuid,
				'stripe_checkout_session_id' => $session_id,
			)
		);

		return false !== $updated;
	}

	/**
	 * Handle a successful Stripe payment for a reservation.
	 *
	 * Called from the Stripe webhook handler after checkout.session.completed.
	 * Updates the reservation to 'paid' and attempts Zoho calendar creation.
	 *
	 * @param string $session_id Stripe checkout session ID.
	 * @param array  $session    Full session object from Stripe.
	 * @param array  $settings   Plugin settings.
	 * @return true|WP_Error
	 */
	public static function handle_payment_success( $session_id, $session, $settings ) {
		global $wpdb;

		$table = self::get_reservations_table();

		$reservation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE stripe_checkout_session_id = %s LIMIT 1",
				sanitize_text_field( (string) $session_id )
			)
		);

		if ( ! $reservation ) {
			$uuid = isset( $session['metadata']['reservation_uuid'] ) ? sanitize_text_field( (string) $session['metadata']['reservation_uuid'] ) : '';
			if ( '' !== $uuid ) {
				$reservation = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM `{$table}` WHERE reservation_uuid = %s LIMIT 1",
						$uuid
					)
				);
			}
		}

		if ( ! $reservation ) {
			return new WP_Error( 'reservation_not_found', __( 'No reservation found for this payment session.', 'ajforms' ) );
		}

		$payment_intent_id = isset( $session['payment_intent'] ) && is_string( $session['payment_intent'] )
		                     ? sanitize_text_field( $session['payment_intent'] )
		                     : '';
		$invoice_id        = isset( $session['invoice'] ) && is_string( $session['invoice'] )
		                     ? sanitize_text_field( $session['invoice'] )
		                     : '';
		$amount_total      = isset( $session['amount_total'] ) ? round( (float) $session['amount_total'] / 100, 2 ) : (float) $reservation->amount;
		$currency          = isset( $session['currency'] ) ? strtolower( sanitize_key( $session['currency'] ) ) : (string) $reservation->currency;

		$wpdb->update(
			$table,
			array(
				'status'                   => 'paid',
				'stripe_payment_intent_id' => $payment_intent_id,
				'stripe_invoice_id'        => $invoice_id,
				'amount'                   => $amount_total,
				'currency'                 => $currency,
				'raw_stripe_data'          => wp_json_encode( $session ),
				'updated_at'               => current_time( 'mysql' ),
			),
			array( 'id' => (int) $reservation->id ),
			array( '%s', '%s', '%s', '%f', '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::log_reservation_event(
			'reservation_payment_succeeded',
			array(
				'reservation_uuid'           => $reservation->reservation_uuid,
				'reservation_id'             => $reservation->id,
				'stripe_customer_id'         => $reservation->stripe_customer_id,
				'stripe_checkout_session_id' => $session_id,
				'stripe_payment_intent_id'   => $payment_intent_id,
				'amount'                     => $amount_total,
				'currency'                   => $currency,
			)
		);

		$res_array = (array) $reservation;

		// Attempt Zoho calendar creation.
		$zoho_result = self::attempt_zoho_calendar_event( $res_array, $settings );

		if ( is_wp_error( $zoho_result ) ) {
			$wpdb->update(
				$table,
				array( 'status' => 'paid_pending_calendar', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $reservation->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			self::log_reservation_event(
				'reservation_paid_pending_calendar',
				array(
					'reservation_uuid' => $reservation->reservation_uuid,
					'reservation_id'   => $reservation->id,
					'error'            => $zoho_result->get_error_message(),
				)
			);

			return true;
		}

		$event_id      = $zoho_result['event_id'] ?? '';
		$zoho_raw_data = wp_json_encode( $zoho_result['raw_data'] ?? array() );

		$wpdb->update(
			$table,
			array(
				'status'         => 'confirmed',
				'zoho_event_id'  => sanitize_text_field( $event_id ),
				'raw_zoho_data'  => $zoho_raw_data,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => (int) $reservation->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::log_reservation_event(
			'reservation_confirmed',
			array(
				'reservation_uuid' => $reservation->reservation_uuid,
				'reservation_id'   => $reservation->id,
				'zoho_event_id'    => $event_id,
			)
		);

		return true;
	}

	/**
	 * Attempt to create or build a Zoho calendar event/URL.
	 *
	 * Tries authenticated event creation first; falls back to building a
	 * prefilled schedule-appointment URL if only URL mode is configured.
	 *
	 * @param array $reservation Reservation data array.
	 * @param array $settings    Plugin settings.
	 * @return array|WP_Error
	 */
	public static function attempt_zoho_calendar_event( $reservation, $settings ) {
		if ( ! class_exists( 'AJCore_Zoho_Calendar' ) ) {
			return new WP_Error( 'zoho_unavailable', __( 'Zoho Calendar class not loaded.', 'ajforms' ) );
		}

		$api_token    = ! empty( $settings['zoho_access_token'] ) ? trim( (string) $settings['zoho_access_token'] )
			: ( ! empty( $settings['zoho_api_token'] ) ? trim( (string) $settings['zoho_api_token'] ) : '' );
		$calendar_uid = ! empty( $reservation['zoho_calendar_uid'] ) ? $reservation['zoho_calendar_uid'] : ( $settings['zoho_calendar_uid'] ?? '' );

		if ( '' !== $api_token && '' !== $calendar_uid ) {
			return AJCore_Zoho_Calendar::create_zoho_calendar_event( $reservation, $settings );
		}

		$schedule_url = ! empty( $reservation['zoho_schedule_url'] ) ? $reservation['zoho_schedule_url'] : '';
		if ( '' === $schedule_url && ! empty( $settings['zoho_schedule_appointment_url'] ) ) {
			$schedule_url = $settings['zoho_schedule_appointment_url'];
		}

		if ( '' === $schedule_url ) {
			return new WP_Error( 'zoho_unavailable', __( 'Neither Zoho API credentials nor Schedule Appointment URL are configured.', 'ajforms' ) );
		}

		$friendly_ref = self::generate_friendly_reference( $reservation['id'] ?? 0 );
		$reason       = sprintf(
			/* translators: %s reservation reference */
			__( 'Conference Room Reservation - Paid via AJCore - Reservation #%s', 'ajforms' ),
			$friendly_ref
		);

		$timezone      = ! empty( $reservation['timezone'] ) ? $reservation['timezone'] : ( $settings['zoho_default_timezone'] ?? 'America/New_York' );
		$prefilled_url = AJCore_Zoho_Calendar::build_zoho_schedule_appointment_url(
			$schedule_url,
			$reservation['customer_name']  ?? '',
			$reservation['customer_email'] ?? '',
			$reservation['start_at']       ?? '',
			$timezone,
			$reason
		);

		if ( is_wp_error( $prefilled_url ) ) {
			return $prefilled_url;
		}

		return array(
			'event_id'              => '',
			'zoho_appointment_url'  => $prefilled_url,
			'raw_data'              => array( 'schedule_appointment_url' => $prefilled_url ),
		);
	}

	/**
	 * Store a generated Zoho appointment URL on a reservation record.
	 *
	 * @param int    $reservation_id
	 * @param string $url
	 */
	public static function store_zoho_appointment_url( $reservation_id, $url ) {
		global $wpdb;

		$table = self::get_reservations_table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT raw_zoho_data FROM `{$table}` WHERE id = %d LIMIT 1", absint( $reservation_id ) ) );

		$raw = array();
		if ( $existing && ! empty( $existing->raw_zoho_data ) ) {
			$decoded = json_decode( $existing->raw_zoho_data, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		$raw['schedule_appointment_url'] = esc_url_raw( $url );

		$wpdb->update(
			$table,
			array( 'raw_zoho_data' => wp_json_encode( $raw ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => absint( $reservation_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Data retrieval
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Get all reservations for a customer.
	 *
	 * @param string $stripe_customer_id
	 * @param int    $wp_user_id Fallback if stripe_customer_id is empty.
	 * @return array
	 */
	public static function get_customer_reservations( $stripe_customer_id, $wp_user_id = 0 ) {
		global $wpdb;

		$table              = self::get_reservations_table();
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$wp_user_id         = absint( $wp_user_id );

		if ( '' !== $stripe_customer_id && $wp_user_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE stripe_customer_id = %s OR wp_user_id = %d ORDER BY start_at DESC LIMIT 100",
					$stripe_customer_id,
					$wp_user_id
				)
			);
		}

		if ( '' !== $stripe_customer_id ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE stripe_customer_id = %s ORDER BY start_at DESC LIMIT 100",
					$stripe_customer_id
				)
			);
		}

		if ( $wp_user_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE wp_user_id = %d ORDER BY start_at DESC LIMIT 100",
					$wp_user_id
				)
			);
		}

		return array();
	}

	/**
	 * Get a single reservation by UUID.
	 *
	 * @param string $uuid
	 * @return object|null
	 */
	public static function get_reservation_by_uuid( $uuid ) {
		global $wpdb;

		$table = self::get_reservations_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE reservation_uuid = %s LIMIT 1",
				sanitize_text_field( (string) $uuid )
			)
		);
	}

	/**
	 * Get a reservation by Stripe checkout session ID.
	 *
	 * @param string $session_id
	 * @return object|null
	 */
	public static function get_reservation_by_session_id( $session_id ) {
		global $wpdb;

		$table = self::get_reservations_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE stripe_checkout_session_id = %s LIMIT 1",
				sanitize_text_field( (string) $session_id )
			)
		);
	}

	/**
	 * Get all active reservation resources.
	 *
	 * @return array
	 */
	public static function get_all_resources() {
		global $wpdb;

		$table = self::get_resources_table();

		return $wpdb->get_results(
			"SELECT * FROM `{$table}` WHERE active = 1 ORDER BY id ASC"
		);
	}

	/**
	 * Get a resource by its key.
	 *
	 * @param string $resource_key
	 * @return object|null
	 */
	public static function get_resource_by_key( $resource_key ) {
		global $wpdb;

		$table = self::get_resources_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE resource_key = %s LIMIT 1",
				sanitize_key( (string) $resource_key )
			)
		);
	}

	/**
	 * Get a resource by its DB ID.
	 *
	 * @param int $resource_id
	 * @return object|null
	 */
	public static function get_resource_by_id( $resource_id ) {
		global $wpdb;

		$table = self::get_resources_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d LIMIT 1",
				absint( $resource_id )
			)
		);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Admin – all reservations
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Get all reservations with optional filters for admin view.
	 *
	 * @param array $filters {
	 *     @type string $status       Filter by status.
	 *     @type string $resource_key Filter by resource_key.
	 *     @type string $pricing_type Filter by pricing_type.
	 *     @type string $date_from    Filter start_at >= this date (YYYY-MM-DD).
	 *     @type string $date_to      Filter start_at <= this date (YYYY-MM-DD).
	 *     @type int    $limit        Max records (default 200).
	 * }
	 * @return array
	 */
	public static function get_all_reservations( $filters = array() ) {
		global $wpdb;

		$table  = self::get_reservations_table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $filters['status'] );
		}

		if ( ! empty( $filters['resource_key'] ) ) {
			$where[]  = 'resource_key = %s';
			$params[] = sanitize_key( $filters['resource_key'] );
		}

		if ( ! empty( $filters['pricing_type'] ) && in_array( $filters['pricing_type'], array( 'business_hours', 'after_hours_weekend' ), true ) ) {
			$where[]  = 'pricing_type = %s';
			$params[] = $filters['pricing_type'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'start_at >= %s';
			$params[] = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'start_at <= %s';
			$params[] = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
		}

		$limit = absint( $filters['limit'] ?? 200 );
		if ( $limit < 1 || $limit > 1000 ) {
			$limit = 200;
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY start_at DESC LIMIT {$limit}";

		if ( ! empty( $params ) ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		}

		return $wpdb->get_results( $sql );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Status helpers
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Human-readable label for a reservation status.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function get_reservation_status_label( $status ) {
		$labels = array(
			'pending_payment'       => __( 'Pending Payment', 'ajforms' ),
			'paid'                  => __( 'Paid', 'ajforms' ),
			'confirmed'             => __( 'Confirmed', 'ajforms' ),
			'paid_pending_calendar' => __( 'Paid – Pending Calendar', 'ajforms' ),
			'cancelled'             => __( 'Cancelled', 'ajforms' ),
			'failed'                => __( 'Failed', 'ajforms' ),
			'refunded'              => __( 'Refunded', 'ajforms' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', sanitize_key( (string) $status ) ) );
	}

	/**
	 * CSS class suffix for a reservation status (for badge coloring).
	 *
	 * @param string $status
	 * @return string 'good', 'warn', or 'bad'
	 */
	public static function get_reservation_status_class( $status ) {
		$good = array( 'confirmed', 'paid' );
		$warn = array( 'pending_payment', 'paid_pending_calendar' );
		$bad  = array( 'cancelled', 'failed', 'refunded' );

		if ( in_array( $status, $good, true ) ) {
			return 'good';
		}
		if ( in_array( $status, $warn, true ) ) {
			return 'warn';
		}
		if ( in_array( $status, $bad, true ) ) {
			return 'bad';
		}
		return 'warn';
	}

	/**
	 * Human-readable label for a pricing type.
	 *
	 * @param string $pricing_type
	 * @param array  $settings     Plugin settings (may override labels).
	 * @return string
	 */
	public static function get_pricing_type_label( $pricing_type, $settings = array() ) {
		if ( 'business_hours' === $pricing_type ) {
			return ! empty( $settings['reservation_business_hours_label'] )
				? sanitize_text_field( $settings['reservation_business_hours_label'] )
				: __( 'Business Hours (Mon–Fri 9am–5pm)', 'ajforms' );
		}

		return ! empty( $settings['reservation_after_hours_label'] )
			? sanitize_text_field( $settings['reservation_after_hours_label'] )
			: __( 'After-Hours / Weekend', 'ajforms' );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Event log
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Log a reservation event to the AJCore portal event log.
	 *
	 * Piggybacks on the existing aj_portal_event_log table.
	 *
	 * @param string $event_type
	 * @param array  $args
	 */
	public static function log_reservation_event( $event_type, $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'aj_portal_event_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$args = is_array( $args ) ? $args : array();

		$severity = isset( $args['severity'] ) ? sanitize_key( $args['severity'] ) : 'info';
		if ( ! in_array( $severity, array( 'info', 'warning', 'error', 'debug' ), true ) ) {
			$severity = 'info';
		}

		$wpdb->insert(
			$table,
			array(
				'event_type'        => sanitize_text_field( 'reservation_' . ltrim( (string) $event_type, 'reservation_' ) ),
				'severity'          => $severity,
				'source'            => 'reservations',
				'correlation_id'    => isset( $args['reservation_uuid'] ) ? sanitize_text_field( (string) $args['reservation_uuid'] ) : '',
				'site_uuid'         => (string) get_option( 'ajcore_site_uuid', '' ),
				'stripe_customer_id' => isset( $args['stripe_customer_id'] ) ? sanitize_text_field( (string) $args['stripe_customer_id'] ) : '',
				'wp_user_id_before' => 0,
				'wp_user_id_after'  => isset( $args['wp_user_id'] ) ? absint( $args['wp_user_id'] ) : 0,
				'actor_user_id'     => get_current_user_id(),
				'actor_email'       => '',
				'details'           => wp_json_encode( $args ),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}
}
