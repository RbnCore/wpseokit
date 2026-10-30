<?php
/**
 * 404 log storage: the only place that runs SQL on the 404 log table.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Repositories;

use Wpseokit\Db;
use Wpseokit\Models\Log404;

defined( 'ABSPATH' ) || exit;

final class Log404Repository {

	private const ORDER_BY = array( 'id', 'url_path', 'hit_count', 'first_seen_at', 'last_seen_at' );

	private static function table(): string {
		return Db::table( '404_logs' );
	}

	public static function find( int $id ): ?Log404 {
		global $wpdb;
		$row = \AdminKit\Helpers\Db::get_row( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', array( $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return is_array( $row ) ? Log404::from_row( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $args search, orderby, order, page, per_page.
	 * @return array{items: Log404[], total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table  = self::table();
		$where  = '1=1';
		$params = array();
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$where    = 'url_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$orderby  = in_array( $args['orderby'] ?? '', self::ORDER_BY, true ) ? (string) $args['orderby'] : 'last_seen_at';
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? \AdminKit\Helpers\Db::get_var( $count_sql, $params ) : \AdminKit\Helpers\Db::get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; table from Db::table().

		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;
		$rows     = \AdminKit\Helpers\Db::get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", $params, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- orderby/order are whitelisted above.
		return array(
			'items' => array_map( array( Log404::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/** Counts one more hit of an address: insert, or bump the counter. One atomic upsert on the unique key. */
	public static function record( string $url_path, string $ip_hash, string $referer, string $user_agent ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		\AdminKit\Helpers\Db::query( 'INSERT INTO ' . self::table() . ' (url_path, ip_hash, referer, user_agent, hit_count, first_seen_at, last_seen_at) VALUES (%s, %s, %s, %s, 1, %s, %s)
				ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, ip_hash = VALUES(ip_hash), referer = VALUES(referer), user_agent = VALUES(user_agent), last_seen_at = VALUES(last_seen_at)', array( $url_path, $ip_hash, $referer, $user_agent, $now, $now ) );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== \AdminKit\Helpers\Db::delete( self::table(), array( 'id' => $id ), array( '%d' ) ) && $wpdb->rows_affected > 0;
	}

	public static function delete_by_path( string $url_path ): void {
		global $wpdb;
		\AdminKit\Helpers\Db::delete( self::table(), array( 'url_path' => $url_path ), array( '%s' ) );
	}

	public static function count(): int {
		global $wpdb;
		return (int) \AdminKit\Helpers\Db::get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
	}

	/**
	 * Removes rows not seen for `$days` days, then trims to the newest `$max_rows`.
	 *
	 * @return int Rows removed.
	 */
	public static function prune( int $days, int $max_rows ): int {
		global $wpdb;
		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$removed = (int) \AdminKit\Helpers\Db::query( "DELETE FROM {$table} WHERE last_seen_at < %s", array( $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$edge    = \AdminKit\Helpers\Db::get_row( "SELECT id, last_seen_at FROM {$table} ORDER BY last_seen_at DESC, id DESC LIMIT 1 OFFSET %d", array( max( 1, $max_rows ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		if ( is_array( $edge ) ) {
			$removed += (int) \AdminKit\Helpers\Db::query( "DELETE FROM {$table} WHERE last_seen_at < %s OR (last_seen_at = %s AND id <= %d)", array( $edge['last_seen_at'], $edge['last_seen_at'], (int) $edge['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		}
		return $removed;
	}
}
