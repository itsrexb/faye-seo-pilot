<?php
/**
 * CRUD for wp_seopilot_logs.
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

namespace SeoPilotPro\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seopilot_logs';
	}

	public function add( int $job_id, string $level, string $message, array $context = [] ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			[
				'job_id'       => $job_id,
				'level'        => $level,
				'message'      => $message,
				'context_json' => ! empty( $context ) ? wp_json_encode( $context ) : null,
			],
			[ '%d', '%s', '%s', '%s' ]
		);
	}

	public function find_by_job( int $job_id ): array {
		global $wpdb;

		$table = esc_sql( $this->table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE job_id = %d ORDER BY created_at ASC", $job_id ) );
	}

	public function find_recent( int $limit = 50 ): array {
		global $wpdb;

		$table = esc_sql( $this->table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d", $limit ) );
	}

	public function delete_by_job( int $job_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), [ 'job_id' => $job_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
