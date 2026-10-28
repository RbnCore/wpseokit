<?php
/**
 * Database schema (redirects, 404 log, report snapshot and activity event tables). Additive and idempotent: dbDelta may run any number of times.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

defined( 'ABSPATH' ) || exit;

final class Db {

	/** Schema version; raise it whenever DDL below changes. */
	public const VERSION = 3;

	public const OPTION = 'wpseokit_db_version';

	/**
	 * Full table name for a short name: 'redirects', '404_logs', 'snapshots' or 'events'.
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
		$events  = self::table( 'events' );

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
		dbDelta(
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at datetime NOT NULL,
  type varchar(20) NOT NULL,
  event varchar(40) NOT NULL,
  detail varchar(255) NOT NULL DEFAULT '',
  link varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY at (at),
  KEY type_at (type,at)
) {$charset};"
		);
		update_option( self::OPTION, self::VERSION, true );
	}
}
