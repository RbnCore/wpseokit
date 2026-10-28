<?php
/**
 * Redirect storage: the only place that runs SQL on the redirects table.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Repositories;

use Wpseokit\Db;
use Wpseokit\Models\Redirect;

defined( 'ABSPATH' ) || exit;

final class RedirectRepository {

	private const ORDER_BY = array( 'id', 'source_path', 'status_code', 'hits', 'last_hit_at', 'created_at', 'updated_at' );

	private static function table(): string {
		return Db::table( 'redirects' );
	}

	public static function find( int $id ): ?Redirect {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return is_array( $row ) ? Redirect::from_row( $row ) : null;
	}

	/** @param string $source_path Stored form (see RedirectService::store_path()). */
	public static function find_by_source( string $source_path ): ?Redirect {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE source_path = %s', $source_path ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return is_array( $row ) ? Redirect::from_row( $row ) : null;
	}

	/**
	 * An active rule (other than `$except_id`) whose target is the given path of this site, with or without a query.
	 * Targets of this site are stored as paths (see RedirectService::validate()).
	 */
	public static function find_by_target( string $path, int $except_id = 0 ): ?Redirect {
		global $wpdb;
		$like = $wpdb->esc_like( $path );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE is_active = 1 AND id <> %d AND (target_url = %s OR target_url LIKE %s OR target_url LIKE %s) LIMIT 1', $except_id, $path, $like . '?%', $like . '#%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return is_array( $row ) ? Redirect::from_row( $row ) : null;
	}

	/**
	 * Filtered, ordered, paged list.
	 *
	 * @param array<string, mixed> $args search, status_code, is_active ('' = any), orderby, order, page, per_page (0 = all).
	 * @return array{items: Redirect[], total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(source_path LIKE %s OR target_url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['status_code'] ) ) {
			$where[]  = 'status_code = %d';
			$params[] = (int) $args['status_code'];
		}
		if ( isset( $args['is_active'] ) && '' !== $args['is_active'] ) {
			$where[]  = 'is_active = %d';
			$params[] = filter_var( $args['is_active'], FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
		}
		$orderby  = in_array( $args['orderby'] ?? '', self::ORDER_BY, true ) ? (string) $args['orderby'] : 'id';
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 0, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$clause   = implode( ' AND ', $where );
		$table    = self::table();

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; table from Db::table().

		$sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY {$orderby} {$order}, id DESC";
		if ( $per_page > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;
		}
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- orderby/order are whitelisted above.
		return array(
			'items' => array_map( array( Redirect::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/** Every active rule, used to build the redirect map. @return Redirect[] */
	public static function all_active(): array {
		return self::query(
			array(
				'is_active' => 1,
				'per_page'  => 0,
				'orderby'   => 'id',
				'order'     => 'asc',
			)
		)['items'];
	}

	public static function count_active(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE is_active = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
	}

	/**
	 * @param array<string, mixed> $data source_path, target_url, status_code, match_type, is_active.
	 * @return int New id, or 0 on failure.
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'source_path' => (string) $data['source_path'],
				'target_url'  => (string) $data['target_url'],
				'status_code' => (int) $data['status_code'],
				'match_type'  => (string) $data['match_type'],
				'is_active'   => ! empty( $data['is_active'] ) ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data Any of source_path, target_url, status_code, match_type, is_active. */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$set    = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$format = array( '%s' );
		foreach ( array( 'source_path', 'target_url', 'match_type' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$set[ $key ] = (string) $data[ $key ];
				$format[]    = '%s';
			}
		}
		if ( array_key_exists( 'status_code', $data ) ) {
			$set['status_code'] = (int) $data['status_code'];
			$format[]           = '%d';
		}
		if ( array_key_exists( 'is_active', $data ) ) {
			$set['is_active'] = $data['is_active'] ? 1 : 0;
			$format[]         = '%d';
		}
		return false !== $wpdb->update( self::table(), $set, array( 'id' => $id ), $format, array( '%d' ) );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ) && $wpdb->rows_affected > 0;
	}

	/** One hit: counter plus time. A single UPDATE by primary key. */
	public static function touch_hit( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1, last_hit_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s' ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
	}
}
