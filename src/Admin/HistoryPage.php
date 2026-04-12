<?php
/**
 * History page — audit log and rollback links.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Admin;

use SeoPilotPro\Jobs\JobRepository;
use SeoPilotPro\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HistoryPage {

	private string $hook = '';

	public function __construct(
		private readonly JobRepository $job_repo,
		private readonly LogRepository $log_repo,
	) {}

	public function register(): void {
		$this->hook = add_submenu_page(
			'seo-pilot-pro-settings',
			__( 'Audit History', 'seo-pilot-pro' ),
			__( 'History', 'seo-pilot-pro' ),
			'edit_posts',
			'seo-pilot-pro-history',
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

		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change
		$per_page = 20;
		$jobs     = $this->job_repo->find_all( $per_page, $paged );
		$total    = $this->job_repo->count_all();
		$pages    = (int) ceil( $total / $per_page );

		$status_labels = [
			'pending'        => __( 'Pending', 'seo-pilot-pro' ),
			'audited'        => __( 'Audited', 'seo-pilot-pro' ),
			'in_review'      => __( 'In Review', 'seo-pilot-pro' ),
			'applied'        => __( 'Applied', 'seo-pilot-pro' ),
			'failed'         => __( 'Failed', 'seo-pilot-pro' ),
			'rolled_back'    => __( 'Rolled Back', 'seo-pilot-pro' ),
		];

		?>
		<div class="wrap seopilot-wrap">
			<h1><?php esc_html_e( 'Audit History', 'seo-pilot-pro' ); ?></h1>

			<div id="seopilot-history-notice" style="display:none;" class="seopilot-apply-notice"></div>

			<table class="wp-list-table widefat fixed striped seopilot-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Post', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Model', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Requested By', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Date', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'seo-pilot-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $jobs ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No audit history yet. Go to the Content Queue to run your first audit.', 'seo-pilot-pro' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $jobs as $job ) : ?>
							<?php
							$post      = get_post( (int) $job->post_id );
							$user      = get_user_by( 'id', (int) $job->requested_by );
							/* translators: %d: post ID */
							$post_title = $post ? $post->post_title : sprintf( __( 'Post #%d (deleted)', 'seo-pilot-pro' ), $job->post_id );
							$username   = $user ? $user->display_name : __( 'Unknown', 'seo-pilot-pro' );
							$status_label = $status_labels[ $job->status ] ?? $job->status;
							?>
							<tr>
								<td>#<?php echo esc_html( (string) $job->id ); ?></td>
								<td>
									<?php if ( $post ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>">
											<?php echo esc_html( $post_title ?: __( '(no title)', 'seo-pilot-pro' ) ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $post_title ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $job->post_type ); ?></td>
								<td><code><?php echo esc_html( $job->model ); ?></code></td>
								<td>
									<span class="seopilot-status seopilot-status--<?php echo esc_attr( $job->status ); ?>">
										<?php echo esc_html( $status_label ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $username ); ?></td>
								<td><?php echo esc_html( date_i18n( 'Y-m-d H:i', strtotime( $job->created_at ) ) ); ?></td>
								<td>
									<?php if ( in_array( $job->status, [ 'audited', 'in_review', 'partially_approved', 'applied' ], true ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-pro-review&job_id=' . $job->id ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Review', 'seo-pilot-pro' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( $job->status === 'applied' && ! empty( $job->snapshot_json ) ) : ?>
										<button
											type="button"
											class="button button-small seopilot-rollback-btn"
											data-job-id="<?php echo esc_attr( (string) $job->id ); ?>"
										><?php esc_html_e( 'Roll Back', 'seo-pilot-pro' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total > $per_page ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post( paginate_links( [
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						] ) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
