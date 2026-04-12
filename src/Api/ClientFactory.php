<?php
/**
 * Instantiates the correct AI client based on the saved provider setting.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClientFactory {

	/**
	 * Return an AI client for the currently configured provider.
	 */
	public static function make(): AiClientInterface {
		$settings = get_option( 'seopilot_settings', [] );
		$provider = $settings['provider'] ?? 'anthropic';

		if ( $provider === 'openai' ) {
			return new OpenAiClient();
		}

		return new ClaudeClient();
	}
}
