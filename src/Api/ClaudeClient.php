<?php
/**
 * HTTP client for the Anthropic Messages API.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Api;

use FayeSeoPilot\Security\ApiKeyEncryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClaudeClient implements AiClientInterface {

	private const API_URL     = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION = '2023-06-01';
	private const TIMEOUT     = 120;

	private string $api_key;
	private string $model;

	public function __construct() {
		$settings      = get_option( 'seopilot_settings', [] );
		$this->api_key = ApiKeyEncryption::decrypt( trim( $settings['api_key'] ?? '' ) );
		$this->model   = trim( $settings['model'] ?? 'claude-sonnet-4-6' );
	}

	/**
	 * Send a messages request to the Claude API.
	 *
	 * @param string $system_prompt The system prompt.
	 * @param string $user_content  The user message content.
	 * @param int    $max_tokens    Maximum tokens in the response.
	 * @return array|\WP_Error Parsed response body or WP_Error.
	 */
	public function send( string $system_prompt, string $user_content, int $max_tokens = 4096 ): array|\WP_Error {
		if ( empty( $this->api_key ) ) {
			return new \WP_Error(
				'seopilot_no_api_key',
				__( 'Claude API key is not configured. Please set it in Faye SEO Pilot → Settings.', 'seo-pilot-pro-to-faye-seo-pilot' )
			);
		}

		$body = wp_json_encode( [
			'model'      => $this->model,
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => $user_content,
				],
			],
		] );

		$response = wp_remote_post( self::API_URL, [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => self::API_VERSION,
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'seopilot_request_failed',
				sprintf(
					/* translators: %s error message */
					__( 'API request failed: %s', 'seo-pilot-pro-to-faye-seo-pilot' ),
					$response->get_error_message()
				)
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status === 401 ) {
			return new \WP_Error( 'seopilot_auth_error', __( 'Invalid Claude API key.', 'seo-pilot-pro-to-faye-seo-pilot' ) );
		}

		if ( $status === 429 ) {
			return new \WP_Error( 'seopilot_rate_limit', __( 'Claude API rate limit reached. Please wait and try again.', 'seo-pilot-pro-to-faye-seo-pilot' ) );
		}

		if ( $status !== 200 ) {
			/* translators: %d: HTTP status code */
			$error_msg = $data['error']['message'] ?? sprintf( __( 'API returned HTTP %d.', 'seo-pilot-pro-to-faye-seo-pilot' ), $status );
			return new \WP_Error( 'seopilot_api_error', $error_msg );
		}

		$text = $data['content'][0]['text'] ?? '';
		if ( empty( $text ) ) {
			return new \WP_Error( 'seopilot_empty_response', __( 'Claude returned an empty response.', 'seo-pilot-pro-to-faye-seo-pilot' ) );
		}

		return [ 'text' => $text, 'model' => $data['model'] ?? $this->model ];
	}

	public function get_model(): string {
		return $this->model;
	}
}
