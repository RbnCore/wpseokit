<?php
/**
 * Logs (Dashboard › Logs): one write call for activity events, a short list of background errors, the update-check
 * results and the Yoast import steps, plus the four read-only `/logs/*` routes (docs/sozlesme.md §9).
 * No personal data is stored: only type, time and a short text; settings changes name the keys, never the values.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Admin;
use Wpseokit\Models\Event;
use Wpseokit\Repositories\EventRepository;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class EventLogService {

	public const OPT_ERRORS  = 'wpseokit_log_errors';
	public const OPT_UPDATES = 'wpseokit_log_updates';

	/** Most entries kept in the error and update-check lists. */
	private const LIST_MAX = 100;

	/** Settings flags that read as "turned on / off" events: section.key => event type. */
	private const TOGGLES = array(
		'sitemap.enabled'   => 'sitemap',
		'feed.main_enabled' => 'feed',
		'llms.enabled'      => 'llms',
	);

	public static function init(): void {
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
	}

	/* ------------------------------------------------------------------ writing */

	/**
	 * Writes one activity event and, every 20th write, trims the table to the retention limits (Settings `logs.*`).
	 *
	 * @param string                $type      One of Event::TYPES; anything else is not written.
	 * @param string                $event     Short event code, e.g. `snapshot_taken`.
	 * @param string                $detail    Short plain text (no personal data, no setting values).
	 * @param array<string, string> $link_args Admin query args of the related screen (default: the dashboard).
	 */
	public static function record( string $type, string $event, string $detail = '', array $link_args = array() ): void {
		if ( ! in_array( $type, Event::TYPES, true ) ) {
			return;
		}
		$id = EventRepository::insert( $type, substr( $event, 0, 40 ), mb_substr( $detail, 0, 255 ), mb_substr( Admin::page_url( $link_args ), 0, 255 ) );
		if ( $id > 0 && 0 === $id % 20 ) {
			EventRepository::prune( (int) SettingsRepository::get( 'logs.retention_days', 90 ), (int) SettingsRepository::get( 'logs.max_events', 500 ) );
		}
	}

	/** Records a background error `{at, where, message}`; at most 100 kept, older than the retention dropped. */
	public static function error( string $where, string $message ): void {
		$list   = self::trim( get_option( self::OPT_ERRORS, array() ) );
		$list[] = array(
			'at'      => time(),
			'where'   => mb_substr( $where, 0, 60 ),
			'message' => mb_substr( $message, 0, 255 ),
		);
		update_option( self::OPT_ERRORS, self::trim( $list ), false );
	}

	/** Records one update-check result: `up_to_date`, `update_found` or `failed`. */
	public static function update_check( string $result, string $version = '' ): void {
		$list   = self::trim( get_option( self::OPT_UPDATES, array() ) );
		$list[] = array(
			'at'      => time(),
			'result'  => $result,
			'version' => $version,
		);
		update_option( self::OPT_UPDATES, self::trim( $list ), false );
	}

	/**
	 * Settings saved: toggles become sitemap/feed/llms events, any other change one `settings` event that names the
	 * changed `section.key` paths (never the values, so no secret leaks).
	 *
	 * @param array<string, array<string, mixed>> $before Settings before the save.
	 * @param array<string, array<string, mixed>> $after  Settings after the save.
	 */
	public static function settings_changed( array $before, array $after ): void {
		$changed = array();
		foreach ( $after as $section => $keys ) {
			foreach ( (array) $keys as $key => $value ) {
				if ( ( $before[ $section ][ $key ] ?? null ) === $value ) {
					continue;
				}
				$path = $section . '.' . $key;
				if ( isset( self::TOGGLES[ $path ] ) ) {
					self::record( self::TOGGLES[ $path ], $value ? 'enabled' : 'disabled', $path, array( 'tool' => self::TOGGLES[ $path ] ) );
					continue;
				}
				$changed[] = $path;
			}
		}
		if ( $changed ) {
			$extra = count( $changed ) - 8;
			self::record( 'settings', 'settings_saved', implode( ', ', array_slice( $changed, 0, 8 ) ) . ( $extra > 0 ? ' +' . $extra : '' ), array( 'tool' => 'settings' ) );
		}
	}

	/**
	 * Keeps the list shape, drops malformed rows and rows older than the retention, keeps the newest 100.
	 *
	 * @param mixed $list Stored option value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function trim( $list ): array {
		$limit = time() - max( 1, (int) SettingsRepository::get( 'logs.retention_days', 90 ) ) * DAY_IN_SECONDS;
		$out   = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( is_array( $row ) && isset( $row['at'] ) && (int) $row['at'] >= $limit ) {
				$out[] = $row;
			}
		}
		return array_slice( $out, -self::LIST_MAX );
	}

	/* ------------------------------------------------------------------ REST */

	public static function register_routes( string $namespace ): void {
		$read = array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => Rest::permission(),
		);
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		register_rest_route(
			$namespace,
			'/logs/activity',
			array_merge(
				$read,
				array(
					'callback' => array( self::class, 'rest_activity' ),
					'args'     => array(
						'type'     => array(
							'type' => 'string',
							'enum' => Event::TYPES,
						),
						'from'     => $date,
						'to'       => $date,
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 8,
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				)
			)
		);
		foreach ( array( 'yoast', 'updates', 'errors' ) as $name ) {
			register_rest_route( $namespace, '/logs/' . $name, array_merge( $read, array( 'callback' => array( self::class, 'rest_' . $name ) ) ) );
		}
	}

	public static function rest_activity( \WP_REST_Request $request ): \WP_REST_Response {
		$result = EventRepository::query(
			array(
				'type'     => (string) $request['type'],
				'from'     => (string) $request['from'],
				'to'       => (string) $request['to'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);
		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'total'  => $result['total'],
				'items'  => array_map( static fn( Event $e ): array => $e->to_array(), $result['items'] ),
			)
		);
	}

	/** Yoast import steps `found` (with the counted source rows), `dry_run`, `run`, `report`; `at` is ISO or null. */
	public static function rest_yoast(): \WP_REST_Response {
		$state = YoastImportService::state();
		$iso   = static fn( $t ): ?string => (int) $t > 0 ? gmdate( 'Y-m-d\TH:i:s', (int) $t ) : null;
		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'state'  => (string) $state['state'],
				'steps'  => array(
					'found'   => array(
						'at'     => $iso( $state['dry_run_at'] ?? 0 ),
						'counts' => is_array( $state['baseline'] ?? null ) ? array_map( 'intval', $state['baseline'] ) : new \stdClass(),
					),
					'dry_run' => array( 'at' => $iso( $state['dry_run_at'] ?? 0 ) ),
					'run'     => array( 'at' => $iso( $state['applied_at'] ?? ( 'applied' === $state['state'] ? ( $state['time'] ?? 0 ) : 0 ) ) ),
					'report'  => array( 'at' => $iso( $state['verified_at'] ?? ( $state['time'] ?? 0 ) ) ),
				),
			)
		);
	}

	public static function rest_updates(): \WP_REST_Response {
		return self::list_response( get_option( self::OPT_UPDATES, array() ) );
	}

	public static function rest_errors(): \WP_REST_Response {
		return self::list_response( get_option( self::OPT_ERRORS, array() ) );
	}

	/**
	 * @param mixed $stored Stored list (oldest first).
	 */
	private static function list_response( $stored ): \WP_REST_Response {
		$list = self::trim( $stored );
		$out  = array();
		foreach ( array_reverse( $list ) as $row ) {
			$row['at'] = gmdate( 'Y-m-d\TH:i:s', (int) $row['at'] );
			$out[]     = $row;
		}
		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'total'  => count( $out ),
				'items'  => $out,
			)
		);
	}
}
