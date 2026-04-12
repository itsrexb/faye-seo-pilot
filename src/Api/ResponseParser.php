<?php
/**
 * Parses and validates Claude's JSON response.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ResponseParser {

	private const REQUIRED_KEYS = [
		'language',
		'page_type',
		'issues',
		'suggested_title',
		'suggested_meta_description',
		'suggested_excerpt',
		// enhanced_content_html is required for classic posts; elementor_elements for Elementor posts.
		// ProposalGenerator decides which is present — do not validate here.
	];

	/**
	 * Parse the raw Claude text into a validated proposal array.
	 *
	 * @param string $text Raw text from Claude API.
	 * @return array|\WP_Error Parsed proposal or error.
	 */
	public function parse( string $text ): array|\WP_Error {
		$json = $this->extract_json( $text );

		if ( null === $json ) {
			return new \WP_Error(
				'seopilot_parse_error',
				__( 'Claude returned a response that could not be parsed as JSON.', 'seo-pilot-pro' )
			);
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'seopilot_parse_error',
				__( 'Claude response JSON could not be decoded.', 'seo-pilot-pro' )
			);
		}

		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				return new \WP_Error(
					'seopilot_parse_error',
					sprintf(
						/* translators: %s: missing JSON key */
						__( 'Claude response is missing required key: %s', 'seo-pilot-pro' ),
						$key
					)
				);
			}
		}

		return $data;
	}

	/**
	 * Extract the JSON object from Claude's response.
	 * Handles both bare JSON and JSON wrapped in markdown fences.
	 */
	private function extract_json( string $text ): ?string {
		$text = trim( $text );

		// Strip markdown code fences if present.
		if ( preg_match( '/```(?:json)?\s*([\s\S]+?)\s*```/', $text, $m ) ) {
			return trim( $m[1] );
		}

		// Try to extract outermost JSON object.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( $start !== false && $end !== false && $end > $start ) {
			return substr( $text, $start, $end - $start + 1 );
		}

		return null;
	}
}
