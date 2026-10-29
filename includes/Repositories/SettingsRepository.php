<?php
/**
 * Settings storage: the single `wpseokit_settings` option. Every other class reads settings only through here.
 *
 * Contract: docs/sozlesme.md §2.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Repositories;

use Wpseokit\Logs;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsRepository {

	/** @var array<string, array<string, mixed>>|null Request-level cache. */
	private static ?array $cache = null;

	/** @return array<string, array<string, mixed>> */
	public static function defaults(): array {
		return Settings::defaults();
	}

	/**
	 * Stored values merged over the defaults: every key exists.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( Settings::OPTION, array() );
			self::$cache = Settings::sanitize( is_array( $stored ) ? $stored : array(), Settings::defaults() );
		}
		return self::$cache;
	}

	/** @return array<string, mixed> */
	public static function section( string $name ): array {
		return self::all()[ $name ] ?? array();
	}

	/**
	 * Reads a "section.key" path.
	 *
	 * @param mixed $default Returned when the path is unknown.
	 * @return mixed
	 */
	public static function get( string $path, $default = null ) {
		$parts = explode( '.', $path, 2 );
		$all   = self::all();
		return ( 2 === count( $parts ) && isset( $all[ $parts[0] ] ) && array_key_exists( $parts[1], $all[ $parts[0] ] ) ) ? $all[ $parts[0] ][ $parts[1] ] : $default;
	}

	/**
	 * Cleans a partial update over the current values (unknown keys are dropped).
	 *
	 * @param array<string, mixed> $partial Partial data.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( array $partial ): array {
		return Settings::sanitize( $partial, self::all() );
	}

	/**
	 * Saves a partial update and returns the full clean settings.
	 *
	 * @param array<string, mixed> $partial Partial data.
	 * @return array<string, array<string, mixed>>
	 */
	public static function update( array $partial ): array {
		$before = self::all();
		update_option( Settings::OPTION, self::sanitize( $partial ), true );
		self::reset_cache();
		$after = self::all();
		Logs::settings_changed( $before, $after );
		return $after;
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}
}
