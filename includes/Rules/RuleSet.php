<?php
/**
 * Score rule table: loads `score-rules.json` (the single source of rules, weights, levels and texts).
 *
 * Design: docs/kapsam-ve-mimari.md §3.10. Method: docs/seo-skoru-yontemi.md.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Rules;

defined( 'ABSPATH' ) || exit;

final class RuleSet {

	/** @var array<string, mixed>|null */
	private static ?array $data = null;

	/**
	 * Loads and validates the JSON. Throws when the file is missing or broken, so the error is loud.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		if ( null === self::$data ) {
			$file = __DIR__ . '/score-rules.json';
			$raw  = is_readable( $file ) ? file_get_contents( $file ) : false;
			if ( false === $raw ) {
				throw new \RuntimeException( 'score-rules.json is missing.' );
			}
			$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) || empty( $data['rules']['site'] ) || empty( $data['rules']['post'] ) || empty( $data['formula'] ) ) {
				throw new \RuntimeException( 'score-rules.json has an unexpected shape.' );
			}
			self::$data = $data;
		}
		return self::$data;
	}

	/**
	 * @param string $scope `site` or `post`.
	 * @return list<array<string, mixed>>
	 */
	public static function rules( string $scope ): array {
		return array_values( self::data()['rules'][ $scope ] ?? array() );
	}

	/** @return array<string, mixed>|null */
	public static function find( string $rule_id ): ?array {
		foreach ( array( 'site', 'post' ) as $scope ) {
			foreach ( self::rules( $scope ) as $rule ) {
				if ( $rule['id'] === $rule_id ) {
					return $rule;
				}
			}
		}
		return null;
	}

	/** @return list<array<string, mixed>> */
	public static function bands(): array {
		return self::data()['bands'];
	}

	/** Maximum score while a critical rule is at 0. */
	public static function cap(): int {
		return (int) self::data()['formula']['critical_cap']['max_score'];
	}

	/** @return array{config: float, posts: float} */
	public static function blend(): array {
		$out = array(
			'config' => 0.65,
			'posts'  => 0.35,
		);
		foreach ( self::data()['scores']['site']['components'] as $part ) {
			if ( isset( $out[ $part['id'] ] ) ) {
				$out[ $part['id'] ] = (float) $part['weight'];
			}
		}
		return $out;
	}

	/** Number of newest posts that feed the site score. */
	public static function post_sample(): int {
		return (int) self::data()['scores']['site']['post_sample']['limit'];
	}

	/** Resets the in-memory copy (tests). */
	public static function reset(): void {
		self::$data = null;
	}
}
