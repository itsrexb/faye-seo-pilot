<?php
/**
 * Orchestrates the audit: extract → request → parse → store.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Content;

use SeoPilotPro\Api\AiClientInterface;
use SeoPilotPro\Api\RequestFactory;
use SeoPilotPro\Api\ResponseParser;
use SeoPilotPro\Jobs\JobRepository;
use SeoPilotPro\Storage\ProposalRepository;
use SeoPilotPro\Storage\LogRepository;

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

		// Call Claude.
		$response = $this->client->send( $system_prompt, $user_message, 4096 );

		if ( is_wp_error( $response ) ) {
			$this->job_repo->update_status( $job_id, 'failed' );
			$this->log_repo->add( $job_id, 'error', $response->get_error_message() );
			return $response;
		}

		// Parse response.
		$proposal = $this->parser->parse( $response['text'] );

		if ( is_wp_error( $proposal ) ) {
			$this->job_repo->update_status( $job_id, 'failed' );
			$this->log_repo->add( $job_id, 'error', $proposal->get_error_message(), [ 'raw' => substr( $response['text'], 0, 500 ) ] );
			return $proposal;
		}

		// Store proposals per field.
		$field_map = [
			'post_title'       => [ 'original' => $post_data['post_title'],    'suggested' => $proposal['suggested_title'] ],
			'post_excerpt'     => [ 'original' => $post_data['post_excerpt'],   'suggested' => $proposal['suggested_excerpt'] ],
			'post_content'     => [ 'original' => $post_data['post_content'],   'suggested' => $proposal['enhanced_content_html'] ],
			'seo_title'        => [ 'original' => $post_data['seo_title'],      'suggested' => $proposal['suggested_title'] ],
			'meta_description' => [ 'original' => $post_data['meta_description'], 'suggested' => $proposal['suggested_meta_description'] ],
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

		// Store issues and notes as a log entry.
		$issues         = implode( ' | ', (array) ( $proposal['issues'] ?? [] ) );
		$notes          = implode( ' | ', (array) ( $proposal['review_notes'] ?? [] ) );
		$enhanced_words = str_word_count( wp_strip_all_tags( (string) ( $proposal['enhanced_content_html'] ?? '' ) ) );
		$links_inserted = count( (array) ( $proposal['internal_links_inserted'] ?? [] ) );
		$ctas_detected  = count( (array) ( $proposal['detected_ctas'] ?? [] ) );
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
