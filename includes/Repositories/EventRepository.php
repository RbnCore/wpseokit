<?php
/**
 * Activity event storage: the only place that runs SQL on the events table.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Repositories;

use Wpseokit\Db;
use Wpseokit\Models\Event;

defined( 'ABSPATH' ) || exit;

final class EventRepository {

	private static function table(): string {
		return Db::table( 'events' );
	}

	public static function insert( string $type, string $event, string $detail, string $link ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'at'     => gmdate( 'Y-m-d H:i:s' ),
				'type'   => $type,
				'event'  => $event,
				'detail' => $detail,
				'link'   => $link,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Newest first.
	 *
	 * @param array<string, mixed> $args type, from (Y-m-d), to (Y-m-d), page, per_page.
	 * @return array{items: Event[], total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table    = self::table();
		$where    = array( '1=1' );
		$params   = array();
		$type     = (string) ( $args['type'] ?? '' );
		$from     = (string) ( $args['from'] ?? '' );
		$to       = (string) ( $args['to'] ?? '' );
		$per_page = max( 1, min( 50, (int) ( $args['per_page'] ?? 8 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		if ( '' !== $type ) {
			$where[]  = 'type = %s';
			$params[] = $type;
		}
		if ( '' !== $from ) {
			$where[]  = 'at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$where[]  = 'at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		$clause    = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; table from Db::table().

		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY at DESC, id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; table from Db::table().
		return array(
			'items' => array_map( array( Event::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Drops events older than `$days` days, then the oldest ones above `$max_rows`.
	 *
	 * @return int Rows removed.
	 */
	public static function prune( int $days, int $max_rows ): int {
		global $wpdb;
		$table   = self::table();
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE at < %s", gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$cutoff  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", max( 1, $max_rows ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		if ( null !== $cutoff ) {
			$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		}
		return $removed;
	}
}
