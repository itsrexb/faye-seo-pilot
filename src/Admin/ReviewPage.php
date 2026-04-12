<?php
/**
 * Review page — side-by-side comparison with field-level approval.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Admin;

use SeoPilotPro\Jobs\JobRepository;
use SeoPilotPro\Storage\ProposalRepository;
use SeoPilotPro\Storage\LogRepository;
use SeoPilotPro\Content\DiffBuilder;
use SeoPilotPro\Content\RollbackManager;
use SeoPilotPro\Integrations\SeoAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReviewPage {

	private string $hook = '';

	public function __construct(
		private readonly ProposalRepository $proposal_repo,
		private readonly JobRepository      $job_repo,
		private readonly DiffBuilder        $diff,
		private readonly RollbackManager    $rollback,
		private readonly SeoAdapterInterface $seo_adapter,
		private readonly LogRepository      $log_repo,
	) {}

	public function register(): void {
		$this->hook = add_submenu_page(
			'seo-pilot-pro-settings',
			__( 'Review Proposal', 'seo-pilot-pro' ),
			__( 'Review', 'seo-pilot-pro' ),
			'edit_posts',
			'seo-pilot-pro-review',
			[ $this, 'render' ]
		);
	}

	public function hook_suffix(): string {
		return $this->hook;
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'seo-pilot-pro' ) );
		}

		$job_id = absint( $_GET['job_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		if ( ! $job_id ) {
			$this->render_no_job();
			return;
		}

		$job = $this->job_repo->find( $job_id );
		if ( ! $job ) {
			$this->render_no_job();
			return;
		}

		$proposals = $this->proposal_repo->find_by_job( $job_id );
		$post      = get_post( (int) $job->post_id );
		$logs      = $this->log_repo->find_by_job( $job_id );

		$field_labels = [
			'post_title'       => __( 'Post Title', 'seo-pilot-pro' ),
			'seo_title'        => __( 'SEO Title (meta title)', 'seo-pilot-pro' ),
			'meta_description' => __( 'Meta Description', 'seo-pilot-pro' ),
			'post_excerpt'     => __( 'Excerpt', 'seo-pilot-pro' ),
			'post_content'     => __( 'Body Content', 'seo-pilot-pro' ),
			'post_categories'  => __( 'Categories', 'seo-pilot-pro' ),
			'post_tags'        => __( 'Tags', 'seo-pilot-pro' ),
		];

		// Extract issues, word count, link count, and CTA count from log.
		$issues         = [];
		$enhanced_words = 0;
		$links_inserted = 0;
		$ctas_detected  = 0;
		foreach ( $logs as $log ) {
			if ( str_starts_with( $log->message, 'Issues:' ) ) {
				$raw    = substr( $log->message, strlen( 'Issues: ' ) );
				$issues = array_filter( array_map( 'trim', explode( '|', $raw ) ) );
				$ctx    = json_decode( (string) $log->context_json, true );
				if ( is_array( $ctx ) ) {
					$enhanced_words = (int) ( $ctx['enhanced_words'] ?? 0 );
					$links_inserted = (int) ( $ctx['links_inserted'] ?? 0 );
					$ctas_detected  = (int) ( $ctx['ctas_detected'] ?? 0 );
				}
				break;
			}
		}

		?>
		<div class="wrap seopilot-wrap">
			<h1>
				<?php esc_html_e( 'Review Proposal', 'seo-pilot-pro' ); ?>
				<span class="seopilot-badge seopilot-badge--<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $job->status ); ?></span>
			</h1>

			<?php if ( $post ) : ?>
				<p class="seopilot-subtitle">
					<?php
					printf(
						/* translators: 1: post title, 2: post edit link */
						esc_html__( 'Post: %1$s — %2$s', 'seo-pilot-pro' ),
						'<strong>' . esc_html( $post->post_title ) . '</strong>',
						'<a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'Edit post', 'seo-pilot-pro' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $enhanced_words > 0 || $links_inserted > 0 || $ctas_detected > 0 ) : ?>
				<p class="seopilot-audit-stats">
					<?php if ( $enhanced_words > 0 ) : ?>
						<span class="seopilot-badge <?php echo $enhanced_words >= 800 ? 'seopilot-badge--ok' : 'seopilot-badge--warn'; ?>">
							<?php echo esc_html( number_format_i18n( $enhanced_words ) ); ?> <?php esc_html_e( 'words', 'seo-pilot-pro' ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $links_inserted > 0 ) : ?>
						<span class="seopilot-badge seopilot-badge--audited">
							<?php echo esc_html( $links_inserted ); ?> <?php esc_html_e( 'internal links', 'seo-pilot-pro' ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $ctas_detected > 0 ) : ?>
						<span class="seopilot-badge seopilot-badge--ok">
							<?php echo esc_html( $ctas_detected ); ?> <?php esc_html_e( 'CTA(s) preserved', 'seo-pilot-pro' ); ?>
						</span>
					<?php elseif ( $enhanced_words > 0 ) : ?>
						<span class="seopilot-badge seopilot-badge--warn">
							<?php esc_html_e( 'No CTAs detected', 'seo-pilot-pro' ); ?>
						</span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $issues ) ) : ?>
				<div class="seopilot-issues-box">
					<strong><?php esc_html_e( 'SEO Issues Found:', 'seo-pilot-pro' ); ?></strong>
					<ul>
						<?php foreach ( $issues as $issue ) : ?>
							<li><?php echo esc_html( $issue ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div id="seopilot-apply-notice" class="seopilot-apply-notice" style="display:none;"></div>

			<form id="seopilot-review-form">
				<input type="hidden" name="job_id" value="<?php echo esc_attr( (string) $job_id ); ?>" />

				<?php foreach ( $proposals as $proposal ) : ?>
					<?php
					$field   = $proposal->field_name;
					$label   = $field_labels[ $field ] ?? $field;
					$orig    = (string) $proposal->original_value;
					$sugg    = (string) $proposal->suggested_value;
					$status  = $proposal->approval_status;
					$is_html = $field === 'post_content';
					?>
					<div class="seopilot-field-card seopilot-field-card--<?php echo esc_attr( $status ); ?>" data-field="<?php echo esc_attr( $field ); ?>">
						<div class="seopilot-field-header">
							<h3><?php echo esc_html( $label ); ?></h3>
							<div class="seopilot-field-actions">
								<button type="button" class="button button-primary seopilot-accept-btn" data-field="<?php echo esc_attr( $field ); ?>">
									&#10003; <?php esc_html_e( 'Accept', 'seo-pilot-pro' ); ?>
								</button>
								<button type="button" class="button seopilot-reject-btn" data-field="<?php echo esc_attr( $field ); ?>">
									&#10007; <?php esc_html_e( 'Reject', 'seo-pilot-pro' ); ?>
								</button>
							</div>
						</div>

						<div class="seopilot-field-body">
							<div class="seopilot-col seopilot-col--current">
								<h4><?php esc_html_e( 'Current', 'seo-pilot-pro' ); ?></h4>
								<div class="seopilot-content-box"><?php if ( $is_html ) : ?><?php echo wp_kses_post( $orig ); ?><?php else : ?><?php echo esc_html( $orig ?: __( '(empty)', 'seo-pilot-pro' ) ); ?><?php endif; ?></div>
							</div>

							<div class="seopilot-col seopilot-col--suggested">
								<h4><?php esc_html_e( 'Suggested', 'seo-pilot-pro' ); ?></h4>
								<div class="seopilot-content-box seopilot-content-box--edit" contenteditable="true" data-field="<?php echo esc_attr( $field ); ?>"><?php if ( $is_html ) : ?><?php echo wp_kses_post( $sugg ); ?><?php else : ?><?php echo esc_html( $sugg ); ?><?php endif; ?></div>
								<input type="hidden" class="seopilot-field-value" name="approvals[<?php echo esc_attr( $field ); ?>]" value="" />
							</div>
						</div>
					</div>
				<?php endforeach; ?>

				<div class="seopilot-submit-bar">
					<button type="button" id="seopilot-accept-all-btn" class="button button-large">
						&#10003;<?php esc_html_e( 'Accept All', 'seo-pilot-pro' ); ?>
					</button>
					<button type="button" id="seopilot-apply-btn" class="button button-primary button-large">
						<?php esc_html_e( 'Apply Approved Fields', 'seo-pilot-pro' ); ?>
					</button>
					<button type="button" id="seopilot-reaudit-btn" class="button button-large" data-post-id="<?php echo esc_attr( (string) $job->post_id ); ?>">
						&#8635; <?php esc_html_e( 'Re-audit', 'seo-pilot-pro' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-pro-queue' ) ); ?>" class="button">
						<?php esc_html_e( '&larr; Back to Queue', 'seo-pilot-pro' ); ?>
					</a>
					<?php if ( $job->status === 'applied' ) : ?>
						<button type="button" id="seopilot-rollback-btn" class="button seopilot-rollback-btn" data-job-id="<?php echo esc_attr( (string) $job_id ); ?>">
							<?php esc_html_e( 'Roll Back Changes', 'seo-pilot-pro' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}

	private function render_no_job(): void {
		?>
		<div class="wrap seopilot-wrap">
			<h1><?php esc_html_e( 'Review Proposal', 'seo-pilot-pro' ); ?></h1>
			<p><?php esc_html_e( 'No proposal selected. Go to the Content Queue and run an audit first.', 'seo-pilot-pro' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-pro-queue' ) ); ?>" class="button">
				<?php esc_html_e( '&larr; Content Queue', 'seo-pilot-pro' ); ?>
			</a>
		</div>
		<?php
	}
}
