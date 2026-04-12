<?php
/**
 * CRUD for wp_seopilot_proposals.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProposalRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seopilot_proposals';
	}

	/** Insert a proposal row and return its ID. */
	public function insert( int $job_id, string $field_name, string $original, string $suggested ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			[
				'job_id'          => $job_id,
				'field_name'      => $field_name,
				'original_value'  => $original,
				'suggested_value' => $suggested,
				'approval_status' => 'pending',
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/** Return all proposals for a job. */
	public function find_by_job( int $job_id ): array {
		global $wpdb;

		$table = esc_sql( $this->table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE job_id = %d ORDER BY id ASC", $job_id ) );
	}

	/** Mark a field as approved with its final value. */
	public function set_approved( int $job_id, string $field_name, string $approved_value ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			[
				'approval_status' => 'approved',
				'approved_value'  => $approved_value,
				'approved_by'     => get_current_user_id(),
				'approved_at'     => current_time( 'mysql' ),
			],
			[ 'job_id' => $job_id, 'field_name' => $field_name ],
			[ '%s', '%s', '%d', '%s' ],
			[ '%d', '%s' ]
		);
	}

	/** Mark a field as rejected. */
	public function set_rejected( int $job_id, string $field_name ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			[ 'approval_status' => 'rejected' ],
			[ 'job_id' => $job_id, 'field_name' => $field_name ],
			[ '%s' ],
			[ '%d', '%s' ]
		);
	}

	/** Delete all proposals for a job. */
	public function delete_by_job( int $job_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), [ 'job_id' => $job_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
