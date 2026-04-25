<?php
/**
 * CRUD for wp_seopilot_jobs.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seopilot_jobs';
	}

	/** Create a new job and return its ID. */
	public function create( int $post_id, string $post_type, string $model ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			[
				'post_id'      => $post_id,
				'post_type'    => $post_type,
				'status'       => 'pending',
				'model'        => $model,
				'requested_by' => get_current_user_id(),
			],
			[ '%d', '%s', '%s', '%s', '%d' ]
		);

		return (int) $wpdb->insert_id;
	}

	/** Find a single job by ID. */
	public function find( int $job_id ): ?object {
		global $wpdb;

		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $job_id ) ) ?: null; // phpcs:ignore
	}

	/** Return the most recent job for a post. */
	public function find_latest_for_post( int $post_id ): ?object {
		global $wpdb;

		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d ORDER BY created_at DESC LIMIT 1", $post_id ) ) ?: null; // phpcs:ignore
	}

	/** Return paginated list of all jobs. */
	public function find_all( int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;
		$table  = $this->table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore
	}

	/** Count all jobs. */
	public function count_all(): int {
		global $wpdb;

		$table = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore
	}

	/** Update a job's status. */
	public function update_status( int $job_id, string $status ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			[ 'status' => $status ],
			[ 'id'     => $job_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/** Delete a job and its related data. */
	public function delete( int $job_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), [ 'id' => $job_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
