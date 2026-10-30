<?php
/**
 * Snapshot storage: the only place that runs SQL on the snapshot table. It also holds the few read-only totals that
 * no other repository exposes (redirect hits, 404 totals, own-meta coverage), so the report reads them in one place.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Repositories;

use Wpseokit\Db;
use Wpseokit\Models\Snapshot;

defined( 'ABSPATH' ) || exit;

final class SnapshotRepository {

	private static function table(): string {
		return Db::table( 'snapshots' );
	}

	public static function find_by_date( string $date ): ?Snapshot {
		global $wpdb;
		$row = \AdminKit\Helpers\Db::get_row( 'SELECT * FROM ' . self::table() . ' WHERE snapshot_date = %s', array( $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return is_array( $row ) ? Snapshot::from_row( $row ) : null;
	}

	/**
	 * Rows with `$from <= snapshot_date <= $to`, oldest first. Days without a row are simply absent.
	 *
	 * @return Snapshot[]
	 */
	public static function between( string $from, string $to ): array {
		global $wpdb;
		$rows = \AdminKit\Helpers\Db::get_results( 'SELECT * FROM ' . self::table() . ' WHERE snapshot_date >= %s AND snapshot_date <= %s ORDER BY snapshot_date ASC', array( $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return array_map( array( Snapshot::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public static function latest(): ?Snapshot {
		global $wpdb;
		$row = \AdminKit\Helpers\Db::get_row( 'SELECT * FROM ' . self::table() . ' ORDER BY snapshot_date DESC LIMIT 1', array(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return is_array( $row ) ? Snapshot::from_row( $row ) : null;
	}

	public static function count(): int {
		global $wpdb;
		return (int) \AdminKit\Helpers\Db::get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
	}

	/**
	 * Writes the day's snapshot; a second call for the same day replaces it (one atomic upsert on the unique date).
	 *
	 * @param array<string, mixed> $metrics Nested metrics.
	 * @return bool|null True = new row, false = existing row replaced, null = the write failed.
	 */
	public static function upsert( string $date, array $metrics, int $schema_version ): ?bool {
		global $wpdb;
		$json = wp_json_encode( $metrics );
		if ( ! is_string( $json ) ) {
			return null;
		}
		$result = \AdminKit\Helpers\Db::query( 'INSERT INTO ' . self::table() . ' (snapshot_date, metrics, schema_version, taken_at) VALUES (%s, %s, %d, %s)
				ON DUPLICATE KEY UPDATE metrics = VALUES(metrics), schema_version = VALUES(schema_version), taken_at = VALUES(taken_at)', array( $date, $json, $schema_version, gmdate( 'Y-m-d H:i:s' ) ) );
		if ( false === $result ) {
			return null;
		}
		// MySQL reports 1 for an insert and 2 for an update that changed the row.
		return 1 === (int) $result;
	}

	/**
	 * Removes snapshots older than `$days` days (by snapshot date).
	 *
	 * @return int Rows removed.
	 */
	public static function prune( int $days ): int {
		global $wpdb;
		$cutoff = wp_date( 'Y-m-d', time() - max( 1, $days ) * DAY_IN_SECONDS );
		return (int) \AdminKit\Helpers\Db::query( 'DELETE FROM ' . self::table() . ' WHERE snapshot_date < %s', array( $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
	}

	/** Total hits of all redirect rules (read-only). */
	public static function redirect_hits(): int {
		global $wpdb;
		return (int) \AdminKit\Helpers\Db::get_var( 'SELECT COALESCE(SUM(hits), 0) FROM ' . Db::table( 'redirects' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
	}

	/**
	 * 404 log totals (read-only): logged addresses, all hits, and addresses first seen since `$since_utc`.
	 *
	 * @return array{addresses: int, hits: int, new: int}
	 */
	public static function not_found_totals( string $since_utc ): array {
		global $wpdb;
		$row = \AdminKit\Helpers\Db::get_row( 'SELECT COUNT(*) AS addresses, COALESCE(SUM(hit_count), 0) AS hits, COALESCE(SUM(first_seen_at >= %s), 0) AS fresh FROM ' . Db::table( '404_logs' ), array( $since_utc ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return array(
			'addresses' => (int) ( $row['addresses'] ?? 0 ),
			'hits'      => (int) ( $row['hits'] ?? 0 ),
			'new'       => (int) ( $row['fresh'] ?? 0 ),
		);
	}

	/**
	 * Published items of the given post types and how many carry their own SEO title / description (read-only).
	 *
	 * @param string[] $types Post type names.
	 * @return array{posts: int, titled: int, described: int}
	 */
	public static function meta_coverage( array $types ): array {
		global $wpdb;
		if ( ! $types ) {
			return array(
				'posts'     => 0,
				'titled'    => 0,
				'described' => 0,
			);
		}
		$marks = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only counts; placeholders are generated above.
		$posts = (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($marks)", $types );
		$count = static function ( string $meta_key ) use ( $wpdb, $marks, $types ): int {
			return (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value <> '' WHERE p.post_status = 'publish' AND p.post_type IN ($marks)", array_merge( array( $meta_key ), $types ) );
		};
		// phpcs:enable
		return array(
			'posts'     => $posts,
			'titled'    => $count( '_wpseokit_title' ),
			'described' => $count( '_wpseokit_description' ),
		);
	}
}
