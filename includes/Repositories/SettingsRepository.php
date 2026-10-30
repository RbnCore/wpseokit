<?php
/**
 * Settings storage: the single `wpseokit_settings` option. Every other class reads settings only through here.
 *
 * The engine (AdminKit\Core\Settings) owns the schema, the dotted paths, the cleaning and the option write; this
 * class is the plugin's thin adapter so the rest of the plugin and the tests keep their call signatures.
 *
 * Contract: docs/sozlesme.md §2, adminkit/src/docs/ayar-motoru.md §10.3.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Repositories;

use AdminKit\Core\Settings as Engine;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsRepository {

	/** @return array<string, array<string, mixed>> */
	public static function defaults(): array {
		return Settings::defaults();
	}

	/**
	 * Stored values merged over the defaults: every key exists (the engine also cleans them on read).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$all = Engine::all( Settings::SLUG );
		// A missing/foreign option row must still answer with the schema shape.
		return array() === $all ? Settings::defaults() : $all;
	}

	/** @return array<string, mixed> */
	public static function section( string $name ): array {
		$value = self::all()[ $name ] ?? array();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Reads a dotted path: "section.key", or deeper into a map value ("social.profiles.facebook").
	 * A single segment is not a path (the plugin never exposed sections this way) and returns the default.
	 *
	 * @param mixed $default Returned when the path is unknown.
	 * @return mixed
	 */
	public static function get( string $path, $default = null ) {
		if ( '' === $path || false === strpos( $path, '.' ) ) {
			return $default;
		}
		return Engine::get( Settings::SLUG, $path, $default );
	}

	/**
	 * Cleans a partial update over the current values (unknown keys are dropped) without writing.
	 *
	 * @param array<string, mixed> $partial Partial data.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( array $partial ): array {
		return Settings::sanitize( $partial, self::all() );
	}

	/**
	 * Saves a partial update and returns the full clean settings. The change log entry is written by the engine's
	 * `log` callback (`Logs::settings_changed`), so it fires exactly when something changed.
	 *
	 * @param array<string, mixed> $partial Partial data.
	 * @return array<string, array<string, mixed>>
	 */
	public static function update( array $partial ): array {
		$res = Engine::update( Settings::SLUG, $partial );
		return array() === $res['settings'] ? self::all() : (array) $res['settings'];
	}

	public static function reset_cache(): void {
		Engine::reset_cache( Settings::SLUG );
	}
}
