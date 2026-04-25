<?php
/**
 * Main plugin orchestrator.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot;

use FayeSeoPilot\Admin\SettingsPage;
use FayeSeoPilot\Admin\ContentListPage;
use FayeSeoPilot\Admin\ReviewPage;
use FayeSeoPilot\Admin\HistoryPage;
use FayeSeoPilot\Api\ClientFactory;
use FayeSeoPilot\Api\RequestFactory;
use FayeSeoPilot\Api\ResponseParser;
use FayeSeoPilot\Content\ContentExtractor;
use FayeSeoPilot\Content\ProposalGenerator;
use FayeSeoPilot\Content\DiffBuilder;
use FayeSeoPilot\Content\RollbackManager;
use FayeSeoPilot\Integrations\NullSeoAdapter;
use FayeSeoPilot\Integrations\YoastAdapter;
use FayeSeoPilot\Jobs\JobRepository;
use FayeSeoPilot\Storage\ProposalRepository;
use FayeSeoPilot\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires together all plugin components and registers WordPress hooks.
 */
final class Plugin {

	public function init(): void {
		if ( is_admin() ) {
			$this->init_admin();
		}

		add_action( 'wp_ajax_seopilot_run_audit', [ $this, 'ajax_run_audit' ] );
		add_action( 'wp_ajax_seopilot_apply_proposal', [ $this, 'ajax_apply_proposal' ] );
		add_action( 'wp_ajax_seopilot_rollback', [ $this, 'ajax_rollback' ] );
	}

	private function init_admin(): void {
		$job_repo      = new JobRepository();
		$proposal_repo = new ProposalRepository();
		$log_repo      = new LogRepository();
		$seo_adapter   = $this->resolve_seo_adapter();
		$extractor     = new ContentExtractor( $seo_adapter );
		$client        = ClientFactory::make();
		$request_fac   = new RequestFactory();
		$parser        = new ResponseParser();
		$generator     = new ProposalGenerator( $client, $request_fac, $parser, $extractor, $job_repo, $proposal_repo, $log_repo );
		$diff          = new DiffBuilder();
		$rollback      = new RollbackManager( $seo_adapter );

		$settings_page     = new SettingsPage();
		$content_list_page = new ContentListPage( $job_repo );
		$review_page       = new ReviewPage( $proposal_repo, $job_repo, $diff, $rollback, $seo_adapter, $log_repo );
		$history_page      = new HistoryPage( $job_repo, $log_repo );

		add_action( 'admin_menu', function () use ( $settings_page, $content_list_page, $review_page, $history_page ): void {
			$settings_page->register();
			$content_list_page->register();
			$review_page->register();
			$history_page->register();
		} );

		add_action( 'admin_enqueue_scripts', function ( string $hook ) use ( $settings_page, $content_list_page, $review_page, $history_page ): void {
			$pages = [
				$settings_page->hook_suffix(),
				$content_list_page->hook_suffix(),
				$review_page->hook_suffix(),
				$history_page->hook_suffix(),
			];

			if ( ! in_array( $hook, $pages, true ) ) {
				return;
			}

			wp_enqueue_style(
				'seopilot-admin',
				SEOPILOT_PLUGIN_URL . 'assets/admin.css',
				[],
				SEOPILOT_VERSION
			);

			wp_enqueue_script(
				'seopilot-admin',
				SEOPILOT_PLUGIN_URL . 'assets/admin.js',
				[ 'jquery' ],
				SEOPILOT_VERSION,
				true
			);

			wp_localize_script( 'seopilot-admin', 'SeoPilot', [
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( 'seopilot_nonce' ),
				'default_system_prompt' => RequestFactory::get_default_system_prompt(),
				'strings'               => [
					'auditing'      => __( 'Auditing…', 'faye-seo-pilot' ),
					'audit_done'    => __( 'Audit complete. Redirecting to review…', 'faye-seo-pilot' ),
					'audit_failed'  => __( 'Audit failed: ', 'faye-seo-pilot' ),
					'applying'      => __( 'Applying approved changes…', 'faye-seo-pilot' ),
					'apply_done'    => __( 'Changes applied.', 'faye-seo-pilot' ),
					'apply_failed'  => __( 'Apply failed: ', 'faye-seo-pilot' ),
					'rolling_back'  => __( 'Rolling back…', 'faye-seo-pilot' ),
					'rollback_done' => __( 'Rollback complete.', 'faye-seo-pilot' ),
					'reauditing'    => __( 'Re-auditing…', 'faye-seo-pilot' ),
				],
			] );
		} );

		add_filter( 'plugin_action_links_' . SEOPILOT_PLUGIN_BASENAME, function ( array $links ): array {
			$url     = admin_url( 'admin.php?page=faye-seo-pilot-settings' );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'faye-seo-pilot' ) . '</a>';
			return $links;
		} );
	}

	/**
	 * AJAX: run an audit job for a post.
	 */
	public function ajax_run_audit(): void {
		check_ajax_referer( 'seopilot_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'faye-seo-pilot' ) ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'faye-seo-pilot' ) ] );
		}

		$seo_adapter = $this->resolve_seo_adapter();
		$extractor   = new ContentExtractor( $seo_adapter );
		$client      = ClientFactory::make();
		$request_fac = new RequestFactory();
		$parser      = new ResponseParser();
		$job_repo    = new JobRepository();
		$prop_repo   = new ProposalRepository();
		$log_repo    = new LogRepository();
		$generator   = new ProposalGenerator( $client, $request_fac, $parser, $extractor, $job_repo, $prop_repo, $log_repo );

		$result = $generator->run( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$review_url = admin_url( 'admin.php?page=faye-seo-pilot-review&job_id=' . $result );
		wp_send_json_success( [ 'job_id' => $result, 'review_url' => $review_url ] );
	}

	/**
	 * AJAX: apply approved proposals to a post.
	 */
	public function ajax_apply_proposal(): void {
		check_ajax_referer( 'seopilot_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'faye-seo-pilot' ) ], 403 );
		}

		$job_id    = absint( $_POST['job_id'] ?? 0 );
		$approvals = isset( $_POST['approvals'] ) ? wp_unslash( $_POST['approvals'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below

		if ( ! $job_id || ! is_array( $approvals ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'faye-seo-pilot' ) ] );
		}

		$seo_adapter = $this->resolve_seo_adapter();
		$job_repo    = new JobRepository();
		$prop_repo   = new ProposalRepository();
		$log_repo    = new LogRepository();
		$rollback    = new RollbackManager( $seo_adapter );

		$job = $job_repo->find( $job_id );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'faye-seo-pilot' ) ] );
		}

		$post_id = (int) $job->post_id;

		// Create rollback snapshot before applying.
		$rollback->create_snapshot( $post_id, $job_id );

		// Collect all Elementor element updates first — apply in a single
		// read-modify-write pass to avoid WordPress meta cache stomping.
		$elementor_updates = [];

		$post_data = [];
		foreach ( $approvals as $field => $value ) {
			$field     = sanitize_key( $field );
			$value_str = (string) $value;

			// ── Elementor per-element fields: elementor_{element_id} ──────────
			if ( str_starts_with( $field, 'elementor_' ) ) {
				$element_id = substr( $field, strlen( 'elementor_' ) );
				$prop_repo->set_approved( $job_id, $field, wp_kses_post( $value_str ) );
				$elementor_updates[ $element_id ] = $value_str;
				continue;
			}

			$value = wp_kses_post( $value_str );
			$prop_repo->set_approved( $job_id, $field, $value );

			switch ( $field ) {
				case 'post_title':
					$post_data['post_title'] = sanitize_text_field( $value );
					break;
				case 'post_excerpt':
					$post_data['post_excerpt'] = wp_strip_all_tags( $value );
					break;
				case 'post_content':
					// Classic / block-editor posts only (Elementor posts use elementor_* fields).
					$post_data['post_content'] = $value;
					break;
				case 'post_categories':
					$names = array_filter( array_map( 'trim', explode( ',', wp_strip_all_tags( $value ) ) ) );
					wp_set_object_terms( $post_id, $names, 'category' );
					break;
				case 'post_tags':
					$names = array_filter( array_map( 'trim', explode( ',', wp_strip_all_tags( $value ) ) ) );
					wp_set_object_terms( $post_id, $names, 'post_tag' );
					break;
				default:
					// SEO meta fields handled by adapter below.
					break;
			}
		}

		// Apply all Elementor elements in one read-modify-write pass.
		if ( ! empty( $elementor_updates ) ) {
			$this->apply_elementor_elements_batch( $post_id, $elementor_updates );
		}

		if ( ! empty( $post_data ) ) {
			$post_data['ID'] = $post_id;
			$updated         = wp_update_post( $post_data, true );

			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( [ 'message' => $updated->get_error_message() ] );
			}
		}

		// Apply SEO meta fields via adapter.
		if ( isset( $approvals['seo_title'] ) ) {
			$seo_adapter->set_seo_title( $post_id, sanitize_text_field( $approvals['seo_title'] ) );
		}
		if ( isset( $approvals['meta_description'] ) ) {
			$seo_adapter->set_meta_description( $post_id, sanitize_text_field( $approvals['meta_description'] ) );
		}

		$job_repo->update_status( $job_id, 'applied' );

		$log_repo->add( $job_id, 'info', sprintf(
			'Applied %d field(s) to post %d by user %d.',
			count( $approvals ),
			$post_id,
			get_current_user_id()
		) );

		wp_send_json_success( [
			'message'     => __( 'Changes applied successfully.', 'faye-seo-pilot' ),
			'history_url' => admin_url( 'admin.php?page=faye-seo-pilot-history' ),
		] );
	}

	/**
	 * AJAX: roll back a job.
	 */
	public function ajax_rollback(): void {
		check_ajax_referer( 'seopilot_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'faye-seo-pilot' ) ], 403 );
		}

		$job_id = absint( $_POST['job_id'] ?? 0 );
		if ( ! $job_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid job ID.', 'faye-seo-pilot' ) ] );
		}

		$seo_adapter = $this->resolve_seo_adapter();
		$rollback    = new RollbackManager( $seo_adapter );
		$log_repo    = new LogRepository();
		$job_repo    = new JobRepository();

		$result = $rollback->restore( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$job_repo->update_status( $job_id, 'rolled_back' );
		$log_repo->add( $job_id, 'info', 'Rolled back by user ' . get_current_user_id() );

		wp_send_json_success( [ 'message' => __( 'Rollback complete.', 'faye-seo-pilot' ) ] );
	}

	// -------------------------------------------------------------------------
	// Elementor per-element apply
	// -------------------------------------------------------------------------

	/**
	 * Apply multiple Elementor element updates in a single read-modify-write pass.
	 *
	 * Reading and writing _elementor_data once prevents WordPress meta-cache
	 * stomping: if we called update_post_meta per element, each subsequent
	 * read would get the stale cached value and overwrite the previous update.
	 *
	 * @param int                  $post_id WordPress post ID.
	 * @param array<string,string> $updates Map of element_id => new value.
	 */
	private function apply_elementor_elements_batch( int $post_id, array $updates ): void {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return;
		}

		$any_updated = false;
		foreach ( $updates as $element_id => $value ) {
			$updated = false;
			$this->update_element_by_id( $data, (string) $element_id, (string) $value, $updated );
			if ( $updated ) {
				$any_updated = true;
			}
		}

		if ( $any_updated ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			$this->flush_elementor_cache( $post_id );
		}
	}

	/**
	 * Recursively walk Elementor elements, find the one with $target_id,
	 * and update its content field based on widget type.
	 *
	 * @param array  $elements  Elementor elements array, passed by reference.
	 * @param string $target_id The element ID to find.
	 * @param string $value     New content value.
	 * @param bool   $updated   Set to true once an update is made.
	 */
	private function update_element_by_id( array &$elements, string $target_id, string $value, bool &$updated ): void {
		foreach ( $elements as &$element ) {
			if ( $updated ) {
				break;
			}

			if ( ( $element['id'] ?? '' ) === $target_id ) {
				$widget = $element['widgetType'] ?? '';

				switch ( $widget ) {
					case 'text-editor':
						$element['settings']['editor'] = wp_kses_post( $value );
						$updated = true;
						break;

					case 'heading':
						$element['settings']['title'] = sanitize_text_field( wp_strip_all_tags( $value ) );
						$updated = true;
						break;

					case 'html':
						$element['settings']['html'] = wp_kses_post( $value );
						$updated = true;
						break;

					case 'text':
						$element['settings']['text'] = sanitize_text_field( wp_strip_all_tags( $value ) );
						$updated = true;
						break;

					case 'button':
						// Preserve button URL — only update label text.
						$element['settings']['text'] = sanitize_text_field( wp_strip_all_tags( $value ) );
						$updated = true;
						break;

					case 'accordion':
					case 'toggle':
						$items = json_decode( $value, true );
						if ( is_array( $items ) ) {
							$tabs = [];
							foreach ( $items as $i => $item ) {
								$tabs[] = [
									'_id'         => 'faq' . $i,
									'tab_title'   => sanitize_text_field( $item['question'] ?? '' ),
									'tab_content' => wp_kses_post( $item['answer'] ?? '' ),
								];
							}
							$element['settings']['tabs'] = $tabs;
							$updated = true;
						}
						break;

					case 'eael-faq':
					case 'eael-accordion':
						$items = json_decode( $value, true );
						if ( is_array( $items ) ) {
							$faq_items = [];
							foreach ( $items as $i => $item ) {
								$faq_items[] = [
									'_id'              => 'faq' . $i,
									'eael_faq_title'   => sanitize_text_field( $item['question'] ?? '' ),
									'eael_faq_content' => wp_kses_post( $item['answer'] ?? '' ),
								];
							}
							$element['settings']['eael_faq_items'] = $faq_items;
							$updated = true;
						}
						break;
				}
				break; // Element found — stop searching regardless of whether we updated.
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->update_element_by_id( $element['elements'], $target_id, $value, $updated );
			}
		}
		unset( $element );
	}

	// -------------------------------------------------------------------------
	// Elementor cache helpers
	// -------------------------------------------------------------------------

	/**
	 * Clear all Elementor caches for a post so the frontend reflects changes immediately.
	 */
	private function flush_elementor_cache( int $post_id ): void {
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_page_assets' );

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css = new \Elementor\Core\Files\CSS\Post( (string) $post_id );
			$css->delete();
		} else {
			delete_post_meta( $post_id, '_elementor_css' );
			$upload = wp_upload_dir();
			$file   = $upload['basedir'] . '/elementor/css/post-' . $post_id . '.css';
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	private function resolve_seo_adapter(): \FayeSeoPilot\Integrations\SeoAdapterInterface {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return new YoastAdapter();
		}
		return new NullSeoAdapter();
	}
}
