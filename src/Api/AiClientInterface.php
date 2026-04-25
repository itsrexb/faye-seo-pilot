<?php
/**
 * Contract for AI API clients (Anthropic, OpenAI, …).
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AiClientInterface {

	/**
	 * Send a prompt pair and return the text response.
	 *
	 * @param string $system_prompt Instructions / persona for the model.
	 * @param string $user_content  The user-turn content (post data).
	 * @param int    $max_tokens    Token budget for the response.
	 * @return array{text: string, model: string}|\WP_Error
	 */
	public function send( string $system_prompt, string $user_content, int $max_tokens = 4096 ): array|\WP_Error;

	/**
	 * Return the model identifier that will be used.
	 */
	public function get_model(): string;
}
