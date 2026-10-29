<?php
/**
 * Writes WPSeoKit events to the AdminKit log channels (`wpseokit.activity|errors|updates|yoast`). Storage, privacy
 * scrubbing, retention and the read route belong to the core; this class only names the events.
 * No personal data: short codes and texts; a settings change names the keys, never the values.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

use AdminKit\Core\Log;

defined( 'ABSPATH' ) || exit;

final class Logs {

	/** Event types of the activity channel (docs/sozlesme.md §9); anything else is not written. */
	public const TYPES = array( 'sitemap', 'score', 'snapshot', 'settings', 'redirects', 'feed', 'llms' );

	/** Settings flags that read as "turned on / off" events: section.key => event type. */
	private const TOGGLES = array(
		'sitemap.enabled'   => 'sitemap',
		'feed.main_enabled' => 'feed',
		'llms.enabled'      => 'llms',
	);

	/**
	 * One activity row: `event` = the type (so the channel can be filtered by it), `message` = the detail,
	 * context `code` = the event code, `tool` = the related screen.
	 *
	 * @param string $type   One of TYPES.
	 * @param string $code   Short event code, e.g. `snapshot_taken`.
	 * @param string $detail Short plain text (no personal data, no setting values).
	 * @param string $tool   Admin tool (screen) the event belongs to.
	 */
	public static function activity( string $type, string $code, string $detail = '', string $tool = '' ): void {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return;
		}
		Log::log(
			Registry::ACTIVITY,
			'info',
			$type,
			$detail,
			array(
				'code' => $code,
				'tool' => $tool,
			)
		);
	}

	/** A background error: `where` is the event, the message is the short text. */
	public static function error( string $where, string $message ): void {
		Log::log( Registry::ERRORS, 'error', $where, $message );
	}

	/** One update-check result: `up_to_date`, `update_found` or `failed` (+ the version). */
	public static function update_check( string $result, string $version = '' ): void {
		Log::log(
			Registry::UPDATE_LOG,
			'failed' === $result ? 'warning' : 'info',
			'update_check',
			$result,
			array(
				'result'  => $result,
				'version' => $version,
			)
		);
	}

	/**
	 * One Yoast import step: `found`, `dry_run`, `run` or `report`.
	 *
	 * @param array<string, int> $counts Counts of the step (source rows or the report totals).
	 */
	public static function yoast( string $step, array $counts = array() ): void {
		Log::log( Registry::YOAST, 'info', $step, $step, array( 'counts' => $counts ) );
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
					self::activity( self::TOGGLES[ $path ], $value ? 'enabled' : 'disabled', $path, self::TOGGLES[ $path ] );
					continue;
				}
				$changed[] = $path;
			}
		}
		if ( $changed ) {
			$extra = count( $changed ) - 8;
			self::activity( 'settings', 'settings_saved', implode( ', ', array_slice( $changed, 0, 8 ) ) . ( $extra > 0 ? ' +' . $extra : '' ), 'settings' );
		}
	}
}
