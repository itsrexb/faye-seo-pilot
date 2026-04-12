<?php
/**
 * Content queue page — lists posts/pages with audit actions.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Admin;

use SeoPilotPro\Jobs\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ContentListPage {

	private string $hook = '';

	public function __construct( private readonly JobRepository $job_repo ) {}

	public function register(): void {
		$this->hook = add_submenu_page(
			'seo-pilot-pro-settings',
			__( 'Content Queue', 'seo-pilot-pro' ),
			__( 'Content Queue', 'seo-pilot-pro' ),
			'edit_posts',
			'seo-pilot-pro-queue',
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display filters, no state change
		$post_type = sanitize_key( $_GET['post_type'] ?? 'any' );
		$search    = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$per_page  = 20;

		$query_args = [
			'post_type'      => $post_type === 'any' ? [ 'post', 'page' ] : $post_type,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		if ( $search ) {
			$query_args['s'] = $search;
		}

		$query = new \WP_Query( $query_args );
		$posts = $query->posts;
		$total = $query->found_posts;
		$pages = ceil( $total / $per_page );

		// Gather last audit dates for displayed posts.
		$post_ids  = array_map( fn( $p ) => $p->ID, $posts );
		$last_jobs = [];
		foreach ( $post_ids as $pid ) {
			$last_jobs[ $pid ] = $this->job_repo->find_latest_for_post( $pid );
		}

		?>
		<div class="wrap seopilot-wrap">
			<h1><?php esc_html_e( 'Content Queue', 'seo-pilot-pro' ); ?></h1>
			<p class="seopilot-subtitle"><?php esc_html_e( 'Select posts or pages to audit with Claude. Changes require your approval before being saved.', 'seo-pilot-pro' ); ?></p>

			<form method="get" class="seopilot-filter-form">
				<input type="hidden" name="page" value="seo-pilot-pro-queue" />
				<select name="post_type">
					<option value="any" <?php selected( $post_type, 'any' ); ?>><?php esc_html_e( 'All types', 'seo-pilot-pro' ); ?></option>
					<option value="post" <?php selected( $post_type, 'post' ); ?>><?php esc_html_e( 'Posts', 'seo-pilot-pro' ); ?></option>
					<option value="page" <?php selected( $post_type, 'page' ); ?>><?php esc_html_e( 'Pages', 'seo-pilot-pro' ); ?></option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'seo-pilot-pro' ); ?>" />
				<?php submit_button( __( 'Filter', 'seo-pilot-pro' ), 'secondary', '', false ); ?>
			</form>

			<div id="seopilot-audit-notice" class="seopilot-audit-notice" style="display:none;"></div>

			<table class="wp-list-table widefat fixed striped seopilot-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="seopilot-select-all" /></th>
						<th><?php esc_html_e( 'Title', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Words', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Last Audit', 'seo-pilot-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'seo-pilot-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $posts ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No posts found.', 'seo-pilot-pro' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $posts as $post ) : ?>
							<?php
							$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
							$last_job   = $last_jobs[ $post->ID ] ?? null;
							$edit_url   = get_edit_post_link( $post->ID );
							?>
							<tr data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
								<td class="check-column">
									<input type="checkbox" class="seopilot-post-check" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
								</td>
								<td>
									<strong>
										<a href="<?php echo esc_url( (string) $edit_url ); ?>"><?php echo esc_html( $post->post_title ?: __( '(no title)', 'seo-pilot-pro' ) ); ?></a>
									</strong>
								</td>
								<td><?php echo esc_html( $post->post_type ); ?></td>
								<td><?php echo esc_html( $post->post_status ); ?></td>
								<td><?php echo esc_html( (string) $word_count ); ?></td>
								<td><?php echo esc_html( get_the_modified_date( 'Y-m-d', $post ) ); ?></td>
								<td>
									<?php if ( $last_job ) : ?>
										<span class="seopilot-status seopilot-status--<?php echo esc_attr( $last_job->status ); ?>">
											<?php echo esc_html( $last_job->status ); ?>
										</span>
										<br /><small><?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $last_job->created_at ) ) ); ?></small>
									<?php else : ?>
										<span class="seopilot-status seopilot-status--none"><?php esc_html_e( 'Never', 'seo-pilot-pro' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<button
										class="button button-primary seopilot-audit-btn"
										data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
									><?php esc_html_e( 'Audit', 'seo-pilot-pro' ); ?></button>
									<?php if ( $last_job && in_array( $last_job->status, [ 'audited', 'in_review', 'partially_approved' ], true ) ) : ?>
										<a
											href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-pro-review&job_id=' . $last_job->id ) ); ?>"
											class="button"
										><?php esc_html_e( 'Review', 'seo-pilot-pro' ); ?></a>
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

			<div class="seopilot-bulk-bar">
				<button id="seopilot-bulk-audit" class="button button-primary" disabled>
					<?php esc_html_e( 'Audit Selected', 'seo-pilot-pro' ); ?>
				</button>
				<span id="seopilot-selected-count" class="seopilot-selected-count"></span>
			</div>
		</div>
		<?php
	}
}
