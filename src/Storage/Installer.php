<?php
/**
 * Creates and upgrades custom database tables.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	private const DB_VERSION_OPTION = 'seopilot_db_version';
	private const DB_VERSION        = '1.0';

	public function install(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$sql = "
CREATE TABLE {$prefix}seopilot_jobs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id     BIGINT UNSIGNED NOT NULL,
  post_type   VARCHAR(20)     NOT NULL DEFAULT 'post',
  status      VARCHAR(30)     NOT NULL DEFAULT 'pending',
  model       VARCHAR(100)    NOT NULL DEFAULT '',
  requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  snapshot_json LONGTEXT,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY post_id (post_id),
  KEY status (status)
) $charset;

CREATE TABLE {$prefix}seopilot_proposals (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id           BIGINT UNSIGNED NOT NULL,
  field_name       VARCHAR(60)     NOT NULL,
  original_value   LONGTEXT,
  suggested_value  LONGTEXT,
  approved_value   LONGTEXT,
  approval_status  VARCHAR(20)     NOT NULL DEFAULT 'pending',
  approved_by      BIGINT UNSIGNED,
  approved_at      DATETIME,
  PRIMARY KEY (id),
  KEY job_id (job_id),
  KEY approval_status (approval_status)
) $charset;

CREATE TABLE {$prefix}seopilot_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id       BIGINT UNSIGNED NOT NULL,
  level        VARCHAR(10)     NOT NULL DEFAULT 'info',
  message      TEXT            NOT NULL,
  context_json TEXT,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY job_id (job_id)
) $charset;
";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public function uninstall(): void {
		global $wpdb;

		$prefix = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}seopilot_logs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}seopilot_proposals" );
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}seopilot_jobs" );
		// phpcs:enable

		delete_option( self::DB_VERSION_OPTION );
		delete_option( 'seopilot_settings' );
	}
}
