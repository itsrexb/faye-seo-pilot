<?php
/**
 * Main plugin orchestrator.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro;

use SeoPilotPro\Admin\SettingsPage;
use SeoPilotPro\Admin\ContentListPage;
use SeoPilotPro\Admin\ReviewPage;
use SeoPilotPro\Admin\HistoryPage;
use SeoPilotPro\Api\ClientFactory;
use SeoPilotPro\Api\RequestFactory;
use SeoPilotPro\Api\ResponseParser;
use SeoPilotPro\Content\ContentExtractor;
use SeoPilotPro\Content\ProposalGenerator;
use SeoPilotPro\Content\DiffBuilder;
use SeoPilotPro\Content\RollbackManager;
use SeoPilotPro\Integrations\NullSeoAdapter;
use SeoPilotPro\Integrations\YoastAdapter;
use SeoPilotPro\Jobs\JobRepository;
use SeoPilotPro\Storage\ProposalRepository;
use SeoPilotPro\Storage\LogRepository;

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
				'ajax_url'               => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( 'seopilot_nonce' ),
				'default_system_prompt'  => RequestFactory::get_default_system_prompt(),
				'strings'  => [
					'auditing'      => __( 'Auditing…', 'seo-pilot-pro' ),
					'audit_done'    => __( 'Audit complete. Redirecting to review…', 'seo-pilot-pro' ),
					'audit_failed'  => __( 'Audit failed: ', 'seo-pilot-pro' ),
					'applying'      => __( 'Applying approved changes…', 'seo-pilot-pro' ),
					'apply_done'    => __( 'Changes applied.', 'seo-pilot-pro' ),
					'apply_failed'  => __( 'Apply failed: ', 'seo-pilot-pro' ),
					'rolling_back'  => __( 'Rolling back…', 'seo-pilot-pro' ),
					'rollback_done' => __( 'Rollback complete.', 'seo-pilot-pro' ),
					'reauditing'    => __( 'Re-auditing…', 'seo-pilot-pro' ),
				],
			] );
		} );

		add_filter( 'plugin_action_links_' . SEOPILOT_PLUGIN_BASENAME, function ( array $links ): array {
			$url     = admin_url( 'admin.php?page=seo-pilot-pro-settings' );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'seo-pilot-pro' ) . '</a>';
			return $links;
		} );
	}

	/**
	 * AJAX: run an audit job for a post.
	 */
	public function ajax_run_audit(): void {
		check_ajax_referer( 'seopilot_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'seo-pilot-pro' ) ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'seo-pilot-pro' ) ] );
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

		$review_url = admin_url( 'admin.php?page=seo-pilot-pro-review&job_id=' . $result );
		wp_send_json_success( [ 'job_id' => $result, 'review_url' => $review_url ] );
	}

	/**
	 * AJAX: apply approved proposals to a post.
	 */
	public function ajax_apply_proposal(): void {
		check_ajax_referer( 'seopilot_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'seo-pilot-pro' ) ], 403 );
		}

		$job_id    = absint( $_POST['job_id'] ?? 0 );
		$approvals = isset( $_POST['approvals'] ) ? wp_unslash( $_POST['approvals'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field in the switch below

		if ( ! $job_id || ! is_array( $approvals ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'seo-pilot-pro' ) ] );
		}

		$seo_adapter = $this->resolve_seo_adapter();
		$job_repo    = new JobRepository();
		$prop_repo   = new ProposalRepository();
		$log_repo    = new LogRepository();
		$rollback    = new RollbackManager( $seo_adapter );

		$job = $job_repo->find( $job_id );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'seo-pilot-pro' ) ] );
		}

		$post_id = (int) $job->post_id;

		// Create rollback snapshot before applying.
		$rollback->create_snapshot( $post_id, $job_id );

		$post_data = [];
		foreach ( $approvals as $field => $value ) {
			$field = sanitize_key( $field );
			$value = wp_kses_post( (string) $value );

			$prop_repo->set_approved( $job_id, $field, $value );

			switch ( $field ) {
				case 'post_title':
					$post_data['post_title'] = sanitize_text_field( $value );
					break;
				case 'post_excerpt':
					$post_data['post_excerpt'] = wp_strip_all_tags( $value );
					break;
				case 'post_content':
					if ( $this->is_elementor_post( $post_id ) ) {
						$this->apply_elementor_content( $post_id, $value );
					}
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
			'message'     => __( 'Changes applied successfully.', 'seo-pilot-pro' ),
			'history_url' => admin_url( 'admin.php?page=seo-pilot-pro-history' ),
		] );
	}

	/**
	 * AJAX: roll back a job.
	 */
	public function ajax_rollback(): void {
		check_ajax_referer( 'seopilot_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'seo-pilot-pro' ) ], 403 );
		}

		$job_id = absint( $_POST['job_id'] ?? 0 );
		if ( ! $job_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid job ID.', 'seo-pilot-pro' ) ] );
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

		wp_send_json_success( [ 'message' => __( 'Rollback complete.', 'seo-pilot-pro' ) ] );
	}

	/**
	 * Check whether a post is built with Elementor.
	 */
	private function is_elementor_post( int $post_id ): bool {
		$data = get_post_meta( $post_id, '_elementor_data', true );
		return ! empty( $data ) && '[]' !== $data;
	}

	/**
	 * Write new HTML into the first text-editor widget of an Elementor page.
	 * Also clears all Elementor caches for the post so the frontend reflects the change.
	 */
	private function apply_elementor_content( int $post_id, string $html ): void {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return;
		}

		$replaced = false;
		$this->replace_elementor_text_widget( $data, $html, $replaced );

		if ( $replaced ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			$this->flush_elementor_cache( $post_id );
		}
	}

	/**
	 * Clear all Elementor caches for a post so the frontend immediately reflects changes.
	 *
	 * Priority order:
	 *  1. Elementor's own CSS\Post file object (deletes the .css file and the meta).
	 *  2. Direct file deletion as a fallback when the class is not loaded.
	 *  3. Elementor's global data-regeneration flag so it rebuilds on next request.
	 */
	private function flush_elementor_cache( int $post_id ): void {
		// 1. Clear the element cache — Elementor Pro caches rendered widget HTML here.
		//    Without this, the frontend serves stale HTML even after _elementor_data changes.
		delete_post_meta( $post_id, '_elementor_element_cache' );

		// 2. Clear page-level asset cache.
		delete_post_meta( $post_id, '_elementor_page_assets' );

		// 3. Use Elementor's own CSS file API when available — deletes the .css file and its meta.
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css = new \Elementor\Core\Files\CSS\Post( (string) $post_id );
			$css->delete();
		} else {
			// Fallback: remove the meta flag and the physical file on disk.
			delete_post_meta( $post_id, '_elementor_css' );

			$upload = wp_upload_dir();
			$file   = $upload['basedir'] . '/elementor/css/post-' . $post_id . '.css';
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Recursively walk Elementor elements and replace the first text-editor or html
	 * widget's content field with the supplied HTML.
	 *
	 * @param array  $elements Elements array passed by reference.
	 * @param string $html     New HTML content.
	 * @param bool   $replaced Flag set to true once a replacement is made.
	 */
	private function replace_elementor_text_widget( array &$elements, string $html, bool &$replaced ): void {
		foreach ( $elements as &$element ) {
			if ( $replaced ) {
				break;
			}

			if ( isset( $element['widgetType'] ) ) {
				if ( 'text-editor' === $element['widgetType'] ) {
					$element['settings']['editor'] = $html;
					$replaced = true;
					break;
				}
				if ( 'html' === $element['widgetType'] ) {
					$element['settings']['html'] = $html;
					$replaced = true;
					break;
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->replace_elementor_text_widget( $element['elements'], $html, $replaced );
			}
		}
		unset( $element );
	}

	private function resolve_seo_adapter(): \SeoPilotPro\Integrations\SeoAdapterInterface {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return new YoastAdapter();
		}
		return new NullSeoAdapter();
	}
}
