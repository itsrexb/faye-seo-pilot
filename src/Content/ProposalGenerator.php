<?php
/**
 * Orchestrates the audit: extract → request → parse → store.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Content;

use FayeSeoPilot\Api\AiClientInterface;
use FayeSeoPilot\Api\RequestFactory;
use FayeSeoPilot\Api\ResponseParser;
use FayeSeoPilot\Jobs\JobRepository;
use FayeSeoPilot\Storage\ProposalRepository;
use FayeSeoPilot\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProposalGenerator {

	public function __construct(
		private readonly AiClientInterface  $client,
		private readonly RequestFactory     $request_factory,
		private readonly ResponseParser     $parser,
		private readonly ContentExtractor   $extractor,
		private readonly JobRepository      $job_repo,
		private readonly ProposalRepository $proposal_repo,
		private readonly LogRepository      $log_repo,
	) {}

	/**
	 * Run a full audit for a post. Returns the new job ID on success.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return int|\WP_Error Job ID or WP_Error.
	 */
	public function run( int $post_id ): int|\WP_Error {
		$settings = get_option( 'seopilot_settings', [] );

		// Extract post data.
		$post_data = $this->extractor->extract( $post_id );
		if ( is_wp_error( $post_data ) ) {
			return $post_data;
		}

		// Create a job record.
		$job_id = $this->job_repo->create( $post_id, $post_data['post_type'], $this->client->get_model() );

		$this->log_repo->add( $job_id, 'info', "Audit started for post {$post_id}." );

		// Build prompt.
		$system_prompt = $this->request_factory->build_system_prompt( $settings );
		$user_message  = $this->request_factory->build_user_message( $post_data );

		// Call AI.
		$response = $this->client->send( $system_prompt, $user_message, 8192 );

		if ( is_wp_error( $response ) ) {
			$this->job_repo->update_status( $job_id, 'failed' );
			$this->log_repo->add( $job_id, 'error', $response->get_error_message() );
			return $response;
		}

		// Parse response.
		$proposal = $this->parser->parse( $response['text'] );

		if ( is_wp_error( $proposal ) ) {
			$this->job_repo->update_status( $job_id, 'failed' );
			$raw  = $response['text'];
			$tail = strlen( $raw ) > 300 ? '…' . substr( $raw, -300 ) : $raw;
			$this->log_repo->add( $job_id, 'error', $proposal->get_error_message(), [
				'raw_start' => substr( $raw, 0, 200 ),
				'raw_end'   => $tail,
				'raw_len'   => strlen( $raw ),
			] );
			return $proposal;
		}

		// ── Non-content fields (same for all post types) ─────────────────────
		$field_map = [
			'post_title'       => [ 'original' => $post_data['post_title'],       'suggested' => $proposal['suggested_title'] ],
			'post_excerpt'     => [ 'original' => $post_data['post_excerpt'],      'suggested' => $proposal['suggested_excerpt'] ],
			'seo_title'        => [ 'original' => $post_data['seo_title'],         'suggested' => $proposal['suggested_title'] ],
			'meta_description' => [ 'original' => $post_data['meta_description'],  'suggested' => $proposal['suggested_meta_description'] ],
			'post_categories'  => [
				'original'  => implode( ', ', (array) ( $post_data['categories'] ?? [] ) ),
				'suggested' => implode( ', ', (array) ( $proposal['suggested_categories'] ?? [] ) ),
			],
			'post_tags'        => [
				'original'  => implode( ', ', (array) ( $post_data['tags'] ?? [] ) ),
				'suggested' => implode( ', ', (array) ( $proposal['suggested_tags'] ?? [] ) ),
			],
		];

		foreach ( $field_map as $field => $values ) {
			$this->proposal_repo->insert( $job_id, $field, (string) $values['original'], (string) $values['suggested'] );
		}

		// ── Content proposals ─────────────────────────────────────────────────
		$elementor_elements  = $post_data['elementor_elements']  ?? [];
		$response_elements   = $proposal['elementor_elements']   ?? [];
		$enhanced_words      = 0;
		$links_inserted      = count( (array) ( $proposal['internal_links_inserted'] ?? [] ) );
		$ctas_detected       = count( (array) ( $proposal['detected_ctas'] ?? [] ) );

		if ( ! empty( $elementor_elements ) && ! empty( $response_elements ) ) {
			// Elementor post: create one proposal per widget element.
			$original_map = array_column( $elementor_elements, null, 'id' );

			foreach ( $response_elements as $el ) {
				$id = $el['id'] ?? '';
				if ( ! $id || ! isset( $original_map[ $id ] ) ) {
					continue;
				}
				$orig = $original_map[ $id ];

				// Store content or items as the proposal values.
				if ( isset( $el['items'] ) ) {
					$orig_val = (string) wp_json_encode( $orig['items'] ?? [] );
					$sugg_val = (string) wp_json_encode( $el['items'] );
				} else {
					$orig_val = (string) ( $orig['content'] ?? '' );
					$sugg_val = (string) ( $el['content'] ?? '' );
					$enhanced_words += str_word_count( wp_strip_all_tags( $sugg_val ) );
				}

				$this->proposal_repo->insert( $job_id, 'elementor_' . $id, $orig_val, $sugg_val );
			}
		} elseif ( ! empty( $elementor_elements ) && ! empty( $proposal['enhanced_content_html'] ) ) {
			// Elementor post but AI returned enhanced_content_html (fallback).
			$this->proposal_repo->insert(
				$job_id,
				'post_content',
				(string) ( $post_data['post_content'] ?? '' ),
				(string) $proposal['enhanced_content_html']
			);
			$enhanced_words = str_word_count( wp_strip_all_tags( (string) $proposal['enhanced_content_html'] ) );
		} else {
			// Classic / block-editor post.
			$html = (string) ( $proposal['enhanced_content_html'] ?? '' );
			$this->proposal_repo->insert(
				$job_id,
				'post_content',
				(string) ( $post_data['post_content'] ?? '' ),
				$html
			);
			$enhanced_words = str_word_count( wp_strip_all_tags( $html ) );
		}

		// ── Log issues, stats ─────────────────────────────────────────────────
		$issues = implode( ' | ', (array) ( $proposal['issues'] ?? [] ) );
		$notes  = implode( ' | ', (array) ( $proposal['review_notes'] ?? [] ) );

		$this->log_repo->add( $job_id, 'info', "Issues: {$issues}", [
			'notes'          => $notes,
			'model'          => $response['model'],
			'enhanced_words' => $enhanced_words,
			'links_inserted' => $links_inserted,
			'ctas_detected'  => $ctas_detected,
		] );

		$this->job_repo->update_status( $job_id, 'audited' );

		return $job_id;
	}
}
