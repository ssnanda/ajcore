<?php
/**
 * Marketing label helpers shared by every mailbox pipeline. Marketing is a label on an email (it stays in its own pipeline, where it can be
 * filtered to, previewed and deleted), not a pipeline of its own. This class only keeps the "marketing senders": addresses or domains whose
 * future mail is labelled marketing automatically when a pipeline reads it.
 *
 * Pipelines call AJCore_Marketing::is_marketing_sender() when they read mail and AJCore_Marketing::add_sender() when staff label with a sender rule.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Marketing {

	const SENDERS = 'ajcore_marketing_senders';

	/** The addresses / domains whose mail is labelled marketing automatically. */
	public static function senders() {
		$list = get_option( self::SENDERS, array() );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	public static function address_of( $sender ) {
		return preg_match( '/<([^>]+)>/', (string) $sender, $m ) ? trim( $m[1] ) : trim( (string) $sender );
	}

	/** Does the "Name <addr@domain>" sender match a marketing sender rule (full address, domain, or a subdomain of it)? */
	public static function is_marketing_sender( $sender ) {
		$addr = strtolower( self::address_of( $sender ) );
		if ( '' === $addr ) {
			return false;
		}
		$domain = false !== strpos( $addr, '@' ) ? substr( $addr, strrpos( $addr, '@' ) + 1 ) : $addr;
		foreach ( self::senders() as $rule ) {
			$rule = strtolower( ltrim( $rule, '@' ) );
			if ( $rule === $addr || $rule === $domain || ( false === strpos( $rule, '@' ) && '.' . $rule === substr( $domain, -strlen( $rule ) - 1 ) ) ) {
				return true;
			}
		}
		return false;
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

	public static function remove_sender( $rule ) {
		update_option( self::SENDERS, array_values( array_diff( self::senders(), array( strtolower( trim( (string) $rule ) ) ) ) ), false );
	}
}
