<?php
/**
 * Creates and restores content snapshots before applying changes.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Content;

use SeoPilotPro\Integrations\SeoAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RollbackManager {

	public function __construct( private readonly SeoAdapterInterface $seo_adapter ) {}

	/**
	 * Save a snapshot of the post's current content to the job record.
	 */
	public function create_snapshot( int $post_id, int $job_id ): void {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$categories = wp_get_post_categories( $post_id, [ 'fields' => 'names' ] );
		$tags       = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );

		$elementor_data = wp_unslash( get_post_meta( $post_id, '_elementor_data', true ) );

		$snapshot = [
			'post_title'       => $post->post_title,
			'post_content'     => $post->post_content,
			'post_excerpt'     => $post->post_excerpt,
			'seo_title'        => $this->seo_adapter->get_seo_title( $post_id ),
			'meta_description' => $this->seo_adapter->get_meta_description( $post_id ),
			'post_categories'  => is_array( $categories ) ? $categories : [],
			'post_tags'        => is_array( $tags ) ? $tags : [],
			'elementor_data'   => is_string( $elementor_data ) ? $elementor_data : '',
			'snapshot_time'    => current_time( 'mysql' ),
		];

		$table = esc_sql( $wpdb->prefix . 'seopilot_jobs' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			[ 'snapshot_json' => wp_json_encode( $snapshot ) ],
			[ 'id' => $job_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Restore a post from the snapshot stored in a job record.
	 *
	 * @param int $job_id Job ID.
	 * @return true|\WP_Error
	 */
	public function restore( int $job_id ): true|\WP_Error {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'seopilot_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $job_id ) );

		if ( ! $job ) {
			return new \WP_Error( 'seopilot_no_job', __( 'Job not found.', 'seo-pilot-pro' ) );
		}

		if ( empty( $job->snapshot_json ) ) {
			return new \WP_Error( 'seopilot_no_snapshot', __( 'No rollback snapshot exists for this job. The job may not have been applied yet.', 'seo-pilot-pro' ) );
		}

		$snapshot = json_decode( $job->snapshot_json, true );
		if ( ! is_array( $snapshot ) ) {
			return new \WP_Error( 'seopilot_bad_snapshot', __( 'Snapshot data is corrupted.', 'seo-pilot-pro' ) );
		}

		$post_id = (int) $job->post_id;

		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_title'   => $snapshot['post_title'] ?? '',
			'post_content' => $snapshot['post_content'] ?? '',
			'post_excerpt' => $snapshot['post_excerpt'] ?? '',
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $snapshot['seo_title'] ) ) {
			$this->seo_adapter->set_seo_title( $post_id, $snapshot['seo_title'] );
		}

		if ( isset( $snapshot['meta_description'] ) ) {
			$this->seo_adapter->set_meta_description( $post_id, $snapshot['meta_description'] );
		}

		if ( isset( $snapshot['post_categories'] ) ) {
			wp_set_object_terms( $post_id, (array) $snapshot['post_categories'], 'category' );
		}

		if ( isset( $snapshot['post_tags'] ) ) {
			wp_set_object_terms( $post_id, (array) $snapshot['post_tags'], 'post_tag' );
		}

		if ( isset( $snapshot['elementor_data'] ) && '' !== $snapshot['elementor_data'] ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $snapshot['elementor_data'] ) );

			// Clear all Elementor caches so the restored content takes effect immediately.
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

		return true;
	}
}
