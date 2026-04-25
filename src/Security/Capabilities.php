<?php
/**
 * Capability constants and helpers.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {

	/** Manage plugin settings (API key etc.). Admin only. */
	public const MANAGE_SETTINGS = 'manage_options';

	/** Run audits and view proposals. Editor and above. */
	public const RUN_AUDIT = 'edit_posts';

	/** Apply approved proposals to posts. */
	public const APPLY_PROPOSALS = 'edit_posts';

	/** View audit history. */
	public const VIEW_HISTORY = 'edit_posts';

	public static function can_manage_settings(): bool {
		return current_user_can( self::MANAGE_SETTINGS );
	}

	public static function can_run_audit(): bool {
		return current_user_can( self::RUN_AUDIT );
	}

	public static function can_apply_proposals(): bool {
		return current_user_can( self::APPLY_PROPOSALS );
	}

	public static function can_view_history(): bool {
		return current_user_can( self::VIEW_HISTORY );
	}
}
