<?php
/**
 * Nonce helpers.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nonce {

	public const ACTION = 'seopilot_nonce';

	public static function field(): void {
		wp_nonce_field( self::ACTION, 'seopilot_nonce_field' );
	}

	public static function verify( string $nonce ): bool {
		return (bool) wp_verify_nonce( $nonce, self::ACTION );
	}

	public static function check_or_die(): void {
		check_admin_referer( self::ACTION, 'seopilot_nonce_field' );
	}
}
