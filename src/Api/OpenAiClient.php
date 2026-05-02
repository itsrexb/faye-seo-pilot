<?php
/**
 * HTTP client for the OpenAI Chat Completions API.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Api;

use FayeSeoPilot\Security\ApiKeyEncryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenAiClient implements AiClientInterface {

	private const API_URL = 'https://api.openai.com/v1/chat/completions';
	private const TIMEOUT = 120;

	private string $api_key;
	private string $model;

	public function __construct() {
		$settings      = get_option( 'seopilot_settings', [] );
		$this->api_key = ApiKeyEncryption::decrypt( trim( $settings['openai_api_key'] ?? '' ) );
		$this->model   = trim( $settings['openai_model'] ?? 'gpt-4o' );
	}

	/**
	 * Send a chat completion request to the OpenAI API.
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
				__( 'OpenAI API key is not configured. Please set it in Faye SEO Pilot → Settings.', 'faye-seo-pilot' )
			);
		}

		$body = wp_json_encode( [
			'model'      => $this->model,
			'max_tokens' => $max_tokens,
			'messages'   => [
				[
					'role'    => 'system',
					'content' => $system_prompt,
				],
				[
					'role'    => 'user',
					'content' => $user_content,
				],
			],
		] );

		$response = wp_remote_post( self::API_URL, [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'seopilot_request_failed',
				sprintf(
					/* translators: %s error message */
					__( 'API request failed: %s', 'faye-seo-pilot' ),
					$response->get_error_message()
				)
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status === 401 ) {
			return new \WP_Error( 'seopilot_auth_error', __( 'Invalid OpenAI API key.', 'faye-seo-pilot' ) );
		}

		if ( $status === 429 ) {
			return new \WP_Error( 'seopilot_rate_limit', __( 'OpenAI API rate limit reached. Please wait and try again.', 'faye-seo-pilot' ) );
		}

		if ( $status !== 200 ) {
			/* translators: %d: HTTP status code */
			$error_msg = $data['error']['message'] ?? sprintf( __( 'API returned HTTP %d.', 'faye-seo-pilot' ), $status );
			return new \WP_Error( 'seopilot_api_error', $error_msg );
		}

		$text = $data['choices'][0]['message']['content'] ?? '';
		if ( empty( $text ) ) {
			return new \WP_Error( 'seopilot_empty_response', __( 'OpenAI returned an empty response.', 'faye-seo-pilot' ) );
		}

		return [ 'text' => $text, 'model' => $data['model'] ?? $this->model ];
	}

	public function get_model(): string {
		return $this->model;
	}
}
