<?php
/**
 * Database schema (redirects, 404 log and report snapshot tables). Additive and idempotent: dbDelta may run any number of times.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

defined( 'ABSPATH' ) || exit;

final class Db {

	/** Schema version; raise it whenever DDL below changes. 4: the activity event table moved to the AdminKit log. */
	public const VERSION = 4;

	public const OPTION = 'wpseokit_db_version';

	/**
	 * Full table name for a short name: 'redirects', '404_logs' or 'snapshots'.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'seokit_' . $name;
	}

	/** Activation hook. */
	public static function activate(): void {
		self::install();
	}

	/** Runs install() when the stored schema version is older than VERSION. Cheap: the option is autoloaded. */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::VERSION ) {
			self::install();
		}
	}

	/** Creates or updates the tables with dbDelta and records the schema version. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$redir   = self::table( 'redirects' );
		$logs    = self::table( '404_logs' );
		$snaps   = self::table( 'snapshots' );

		dbDelta(
			"CREATE TABLE {$redir} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_path varchar(191) NOT NULL,
  target_url text NOT NULL,
  status_code smallint(5) unsigned NOT NULL DEFAULT 301,
  match_type varchar(10) NOT NULL DEFAULT 'exact',
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit_at datetime DEFAULT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_path (source_path),
  KEY is_active (is_active)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_path varchar(191) NOT NULL,
  ip_hash char(64) NOT NULL DEFAULT '',
  referer varchar(255) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  hit_count int(10) unsigned NOT NULL DEFAULT 1,
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_path (url_path),
  KEY last_seen_at (last_seen_at)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$snaps} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  snapshot_date date NOT NULL,
  metrics longtext NOT NULL,
  schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
  taken_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY snapshot_date (snapshot_date)
) {$charset};"
		);
		self::drop_legacy_events();
		update_option( self::OPTION, self::VERSION, true );
	}

	/** Schema 4: the own activity table is gone (logs live in the AdminKit core); one idempotent clean-up. */
	private static function drop_legacy_events(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'seokit_events' ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed table name.
		delete_option( 'wpseokit_log_errors' );
		delete_option( 'wpseokit_log_updates' );
	}
}
