<?php
/**
 * Zoho Calendar integration helpers for AJCore reservations.
 *
 * Provides URL building and API stubs. Actual authenticated event creation
 * requires Zoho OAuth tokens configured per-site. If auth is not available,
 * methods return a WP_Error with code 'zoho_unavailable' so callers can
 * gracefully mark reservations as paid_pending_calendar.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Zoho_Calendar {

	/**
	 * Build a prefilled Zoho Schedule Appointment URL from a template.
	 *
	 * Template placeholders (exact strings from Zoho):
	 *   [name]                  → customer name (URL-encoded)
	 *   [EmailId]               → customer email (URL-encoded)
	 *   [MM/dd/yyyy]            → date formatted MM/dd/yyyy
	 *   [13:00]                 → time formatted HH:mm (24-hour)
	 *   [Reason for Appointment]→ reason string (URL-encoded)
	 *
	 * @param string $url_template  The raw Zoho appointment URL with placeholders.
	 * @param string $customer_name Customer full name.
	 * @param string $customer_email Customer email address.
	 * @param string $start_at      Start datetime in 'Y-m-d H:i:s' (UTC or site-local).
	 * @param string $timezone      PHP timezone string, e.g. 'America/New_York'.
	 * @param string $reason        Reason / description to inject.
	 * @return string|WP_Error Fully prefilled URL, or WP_Error on failure.
	 */
	public static function build_zoho_schedule_appointment_url(
		$url_template,
		$customer_name,
		$customer_email,
		$start_at,
		$timezone = 'America/New_York',
		$reason = ''
	) {
		$url_template = trim( (string) $url_template );
		if ( '' === $url_template ) {
			return new WP_Error( 'zoho_unavailable', __( 'Zoho Schedule Appointment URL is not configured.', 'ajforms' ) );
		}

		try {
			$tz = new DateTimeZone( $timezone );
			$dt = new DateTime( $start_at, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( $tz );
		} catch ( Exception $e ) {
			return new WP_Error( 'zoho_datetime_error', __( 'Invalid reservation date/time for Zoho URL builder.', 'ajforms' ) );
		}

		$date_str = $dt->format( 'm/d/Y' );
		$time_str = $dt->format( 'H:i' );

		$replacements = array(
			'[name]'                   => rawurlencode( sanitize_text_field( (string) $customer_name ) ),
			'[EmailId]'                => rawurlencode( sanitize_email( (string) $customer_email ) ),
			'[MM/dd/yyyy]'             => rawurlencode( $date_str ),
			'[13:00]'                  => rawurlencode( $time_str ),
			'[Reason for Appointment]' => rawurlencode( sanitize_text_field( (string) $reason ) ),
		);

		$url = str_replace( array_keys( $replacements ), array_values( $replacements ), $url_template );

		return esc_url_raw( $url );
	}

	/**
	 * Check Zoho Resource free/busy status via the configured API URL.
	 *
	 * The free/busy URL template should contain `{resourceuid}` which is
	 * substituted with the resource UID. Authentication (bearer token) must
	 * be configured via the Zoho API auth settings.
	 *
	 * @param string $resource_uid   Zoho Resource UID.
	 * @param string $freebusy_url   URL template containing `{resourceuid}`.
	 * @param string $start_at       ISO 8601 start datetime.
	 * @param string $end_at         ISO 8601 end datetime.
	 * @param string $api_token      Bearer token for Zoho API auth.
	 * @return array|WP_Error Array with 'is_free' bool, or WP_Error if unavailable.
	 */
	public static function check_zoho_resource_freebusy(
		$resource_uid,
		$freebusy_url,
		$start_at,
		$end_at,
		$api_token = ''
	) {
		if ( '' === trim( (string) $resource_uid ) || '' === trim( (string) $freebusy_url ) ) {
			return new WP_Error( 'zoho_unavailable', __( 'Zoho Resource UID or Free/Busy URL is not configured.', 'ajforms' ) );
		}

		if ( '' === trim( (string) $api_token ) ) {
			return new WP_Error( 'zoho_unavailable', __( 'Zoho API token is not configured. Cannot check availability.', 'ajforms' ) );
		}

		$url = str_replace( '{resourceuid}', rawurlencode( sanitize_text_field( (string) $resource_uid ) ), $freebusy_url );

		$response = wp_remote_get(
			add_query_arg(
				array(
					'start' => rawurlencode( $start_at ),
					'end'   => rawurlencode( $end_at ),
				),
				$url
			),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'zoho_api_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || ! is_array( $body ) ) {
			return new WP_Error(
				'zoho_api_error',
				sprintf( __( 'Zoho free/busy API returned HTTP %d.', 'ajforms' ), $code )
			);
		}

		$is_free = self::parse_freebusy_response( $body, $start_at, $end_at );

		return array(
			'is_free'  => $is_free,
			'raw_data' => $body,
		);
	}

	/**
	 * Parse a Zoho free/busy API response to determine if slot is free.
	 *
	 * @param array  $body     Decoded JSON response from Zoho.
	 * @param string $start_at Requested start.
	 * @param string $end_at   Requested end.
	 * @return bool True if the slot appears free, false if busy.
	 */
	private static function parse_freebusy_response( $body, $start_at, $end_at ) {
		if ( empty( $body ) || ! is_array( $body ) ) {
			return true;
		}

		$busy_slots = array();
		if ( ! empty( $body['busytime'] ) && is_array( $body['busytime'] ) ) {
			$busy_slots = $body['busytime'];
		} elseif ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
			$busy_slots = $body['data'];
		}

		if ( empty( $busy_slots ) ) {
			return true;
		}

		try {
			$req_start = new DateTime( $start_at );
			$req_end   = new DateTime( $end_at );
		} catch ( Exception $e ) {
			return true;
		}

		foreach ( $busy_slots as $slot ) {
			if ( empty( $slot['start'] ) || empty( $slot['end'] ) ) {
				continue;
			}
			try {
				$slot_start = new DateTime( (string) $slot['start'] );
				$slot_end   = new DateTime( (string) $slot['end'] );
			} catch ( Exception $e ) {
				continue;
			}

			if ( $slot_start < $req_end && $slot_end > $req_start ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get Zoho availability for a resource in a time range.
	 * Alias for check_zoho_resource_freebusy with a clearer name for caller context.
	 *
	 * @param string $resource_uid
	 * @param string $freebusy_url
	 * @param string $start_at
	 * @param string $end_at
	 * @param string $api_token
	 * @return array|WP_Error
	 */
	/**
	 * Fetch all Zoho Calendar events for a date range, for display on the booking calendar.
	 *
	 * @param string $calendar_uid Zoho calendar UID.
	 * @param string $start_at     Range start (ISO 8601).
	 * @param string $end_at       Range end (ISO 8601).
	 * @param string $timezone     PHP timezone string.
	 * @param string $api_token    Bearer token.
	 * @return array|WP_Error Array of ['start'=>DateTime,'end'=>DateTime,'title'=>string], or WP_Error.
	 */
	public static function get_events_for_range( $calendar_uid, $start_at, $end_at, $timezone = 'America/New_York', $api_token = '' ) {
		if ( '' === trim( (string) $calendar_uid ) || '' === trim( (string) $api_token ) ) {
			return new WP_Error( 'zoho_unavailable', 'Zoho not configured.' );
		}

		try {
			$utc_start = ( new DateTime( $start_at ) )->setTimezone( new DateTimeZone( 'UTC' ) );
			$utc_end   = ( new DateTime( $end_at ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'zoho_datetime_error', 'Invalid date range.' );
		}

		$url = add_query_arg(
			array(
				'range'      => wp_json_encode(
					array(
						'start' => $utc_start->format( 'Ymd\THis\Z' ),
						'end'   => $utc_end->format( 'Ymd\THis\Z' ),
					)
				),
				'byinstance' => 'true',
				'timezone'   => $timezone,
			),
			'https://calendar.zoho.com/api/v1/calendars/' . rawurlencode( sanitize_text_field( (string) $calendar_uid ) ) . '/events'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || ! is_array( $body ) ) {
			return new WP_Error( 'zoho_api_error', sprintf( 'Zoho Calendar API returned HTTP %d.', $code ) );
		}

		$raw_events = array();
		if ( isset( $body['events'] ) && is_array( $body['events'] ) ) {
			$raw_events = $body['events'];
		} elseif ( isset( $body['data']['events'] ) && is_array( $body['data']['events'] ) ) {
			$raw_events = $body['data']['events'];
		} elseif ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$raw_events = $body['data'];
		} elseif ( ! empty( $body ) && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			$raw_events = $body;
		}

		$result = array();
		foreach ( $raw_events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$slot = self::extract_calendar_event_range( $event, $timezone );
			if ( ! $slot ) {
				continue;
			}
			$title = '';
			if ( ! empty( $event['title'] ) ) {
				$title = (string) $event['title'];
			} elseif ( ! empty( $event['summary'] ) ) {
				$title = (string) $event['summary'];
			}
			$result[] = array(
				'start' => $slot['start'],
				'end'   => $slot['end'],
				'title' => $title,
			);
		}

		return $result;
	}

	public static function get_zoho_availability(
		$resource_uid,
		$freebusy_url,
		$start_at,
		$end_at,
		$api_token = ''
	) {
		return self::check_zoho_resource_freebusy( $resource_uid, $freebusy_url, $start_at, $end_at, $api_token );
	}

	/**
	 * Check a regular Zoho Calendar for events that overlap a requested slot.
	 *
	 * @param string $calendar_uid Calendar UID from Zoho Calendar/CalDAV settings.
	 * @param string $start_at     Requested start datetime.
	 * @param string $end_at       Requested end datetime.
	 * @param string $timezone     PHP timezone string.
	 * @param string $api_token    Bearer token for Zoho API auth.
	 * @return array|WP_Error Array with 'is_free' bool, or WP_Error on failure.
	 */
	public static function check_zoho_calendar_events_availability(
		$calendar_uid,
		$start_at,
		$end_at,
		$timezone = 'America/New_York',
		$api_token = ''
	) {
		if ( '' === trim( (string) $calendar_uid ) ) {
			return new WP_Error( 'zoho_unavailable', __( 'Zoho Calendar UID is not configured.', 'ajforms' ) );
		}

		if ( '' === trim( (string) $api_token ) ) {
			return new WP_Error( 'zoho_unavailable', __( 'Zoho API token is not configured. Cannot check availability.', 'ajforms' ) );
		}

		try {
			$req_start = new DateTime( $start_at );
			$req_end   = new DateTime( $end_at );
			$utc_start = clone $req_start;
			$utc_end   = clone $req_end;
			$utc_start->setTimezone( new DateTimeZone( 'UTC' ) );
			$utc_end->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'zoho_datetime_error', __( 'Invalid reservation date/time for Zoho availability check.', 'ajforms' ) );
		}

		$url = add_query_arg(
			array(
				'range'      => wp_json_encode(
					array(
						'start' => $utc_start->format( 'Ymd\THis\Z' ),
						'end'   => $utc_end->format( 'Ymd\THis\Z' ),
					)
				),
				'byinstance' => 'true',
				'timezone'   => $timezone,
			),
			'https://calendar.zoho.com/api/v1/calendars/' . rawurlencode( sanitize_text_field( (string) $calendar_uid ) ) . '/events'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'zoho_api_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || ! is_array( $body ) ) {
			return new WP_Error(
				'zoho_api_error',
				sprintf( __( 'Zoho Calendar events API returned HTTP %d.', 'ajforms' ), $code )
			);
		}

		$is_free = self::parse_calendar_events_response( $body, $req_start, $req_end, $timezone );

		return array(
			'is_free'  => $is_free,
			'raw_data' => $body,
		);
	}

	private static function parse_calendar_events_response( $body, $req_start, $req_end, $timezone ) {
		$events = array();
		if ( isset( $body['events'] ) && is_array( $body['events'] ) ) {
			$events = $body['events'];
		} elseif ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$events = $body['data'];
			if ( isset( $body['data']['events'] ) && is_array( $body['data']['events'] ) ) {
				$events = $body['data']['events'];
			}
		} elseif ( array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			$events = $body;
		}

		if ( empty( $events ) ) {
			return true;
		}

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$slot = self::extract_calendar_event_range( $event, $timezone );
			if ( ! $slot ) {
				continue;
			}

			if ( $slot['start'] < $req_end && $slot['end'] > $req_start ) {
				return false;
			}
		}

		return true;
	}

	private static function extract_calendar_event_range( $event, $timezone ) {
		$date_data = isset( $event['dateandtime'] ) && is_array( $event['dateandtime'] ) ? $event['dateandtime'] : $event;
		$start = $date_data['start'] ?? $date_data['start_time'] ?? $date_data['startTime'] ?? $event['start'] ?? $event['start_time'] ?? $event['startTime'] ?? '';
		$end   = $date_data['end'] ?? $date_data['end_time'] ?? $date_data['endTime'] ?? $event['end'] ?? $event['end_time'] ?? $event['endTime'] ?? '';

		if ( '' === $start || '' === $end ) {
			return null;
		}

		if ( is_array( $start ) ) {
			$start = $start['dateTime'] ?? $start['datetime'] ?? $start['date'] ?? '';
		}
		if ( is_array( $end ) ) {
			$end = $end['dateTime'] ?? $end['datetime'] ?? $end['date'] ?? '';
		}

		if ( '' === $start || '' === $end ) {
			return null;
		}

		try {
			$tz = new DateTimeZone( $timezone );
			$start_dt = self::parse_zoho_calendar_event_datetime( (string) $start, $tz );
			$end_dt   = self::parse_zoho_calendar_event_datetime( (string) $end, $tz );
		} catch ( Exception $e ) {
			return null;
		}

		return array(
			'start' => $start_dt,
			'end'   => $end_dt,
		);
	}

	private static function parse_zoho_calendar_event_datetime( $value, $timezone ) {
		if ( preg_match( '/^\d{8}T\d{6}Z$/', $value ) ) {
			return new DateTime( $value, new DateTimeZone( 'UTC' ) );
		}

		if ( preg_match( '/^\d{8}T\d{6}[+-]\d{4}$/', $value ) ) {
			$parsed = DateTime::createFromFormat( 'Ymd\THisO', $value );
			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		if ( preg_match( '/^\d{8}T\d{6}[+-]\d{2}:\d{2}$/', $value ) ) {
			$parsed = DateTime::createFromFormat( 'Ymd\THisP', $value );
			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		if ( preg_match( '/^\d{8}T\d{6}$/', $value ) ) {
			$parsed = DateTime::createFromFormat( 'Ymd\THis', $value, $timezone );
			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		if ( preg_match( '/^\d{8}$/', $value ) ) {
			$parsed = DateTime::createFromFormat( 'Ymd', $value, $timezone );
			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		return new DateTime( $value, $timezone );
	}

	/**
	 * Create a Zoho Calendar event for a confirmed reservation.
	 *
	 * When full OAuth-based event creation is not yet available, this method
	 * returns a WP_Error with code 'zoho_unavailable' so the caller can mark
	 * the reservation as 'paid_pending_calendar' and log accordingly.
	 *
	 * @param array $reservation Reservation record (object or array).
	 * @param array $settings    Plugin settings including zoho_api_token.
	 * @return array|WP_Error Array with 'event_id' on success, WP_Error otherwise.
	 */
	public static function create_zoho_calendar_event( $reservation, $settings ) {
		$api_token = ! empty( $settings['zoho_access_token'] ) ? trim( (string) $settings['zoho_access_token'] )
			: ( ! empty( $settings['zoho_api_token'] ) ? trim( (string) $settings['zoho_api_token'] ) : '' );

		if ( is_object( $reservation ) ) {
			$reservation = (array) $reservation;
		}

		// Use calendar UID (short form) for the REST API endpoint — same as the GET events endpoint.
		$res_calendar_uid = ! empty( $reservation['zoho_calendar_uid'] ) ? trim( (string) $reservation['zoho_calendar_uid'] )
		                  : ( ! empty( $settings['zoho_calendar_uid'] ) ? trim( (string) $settings['zoho_calendar_uid'] ) : '' );

		if ( '' === $api_token || '' === $res_calendar_uid ) {
			return new WP_Error(
				'zoho_unavailable',
				__( 'Zoho API token or Calendar UID is not configured. Reservation marked as paid_pending_calendar.', 'ajforms' )
			);
		}

		$start_at     = isset( $reservation['start_at'] ) ? (string) $reservation['start_at'] : '';
		$end_at       = isset( $reservation['end_at'] ) ? (string) $reservation['end_at'] : '';
		$customer_name  = isset( $reservation['customer_name'] ) ? sanitize_text_field( (string) $reservation['customer_name'] ) : '';
		$customer_email = isset( $reservation['customer_email'] ) ? sanitize_email( (string) $reservation['customer_email'] ) : '';
		$resource_name  = isset( $reservation['resource_name'] ) ? sanitize_text_field( (string) $reservation['resource_name'] ) : 'Conference Room';
		$uuid           = isset( $reservation['reservation_uuid'] ) ? sanitize_text_field( (string) $reservation['reservation_uuid'] ) : '';
		$notes          = isset( $reservation['customer_notes'] ) ? sanitize_text_field( (string) $reservation['customer_notes'] ) : '';

		$title       = sprintf( '%s - %s', $resource_name, $customer_name );
		$description = sprintf(
			"Reservation UUID: %s\nCustomer: %s <%s>\n%s",
			$uuid,
			$customer_name,
			$customer_email,
			$notes
		);

		$event_body = array(
			'dateandtime' => array(
				'start'    => self::format_zoho_datetime( $start_at ),
				'end'      => self::format_zoho_datetime( $end_at ),
				'timezone' => isset( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York',
			),
			'title'       => $title,
			'description' => $description,
			'attendees'   => array(
				array(
					'email' => $customer_email,
					'name'  => $customer_name,
				),
			),
		);

		$api_url = 'https://calendar.zoho.com/api/v1/calendars/' . rawurlencode( $res_calendar_uid ) . '/events';

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $event_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'zoho_api_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code && 201 !== (int) $code ) {
			return new WP_Error(
				'zoho_api_error',
				sprintf( __( 'Zoho Calendar API returned HTTP %d.', 'ajforms' ), $code )
			);
		}

		$event_id = '';
		if ( ! empty( $body['events'][0]['uid'] ) ) {
			$event_id = sanitize_text_field( (string) $body['events'][0]['uid'] );
		} elseif ( ! empty( $body['uid'] ) ) {
			$event_id = sanitize_text_field( (string) $body['uid'] );
		}

		return array(
			'event_id' => $event_id,
			'raw_data' => $body,
		);
	}

	/**
	 * Format a MySQL datetime string to Zoho's expected ISO 8601 format.
	 *
	 * @param string $datetime MySQL datetime 'Y-m-d H:i:s'.
	 * @return string ISO 8601 string.
	 */
	private static function format_zoho_datetime( $datetime ) {
		try {
			$dt = new DateTime( $datetime, new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d\TH:i:s\Z' );
		} catch ( Exception $e ) {
			return $datetime;
		}
	}
}
