<?php
/**
 * Pure score arithmetic (no WordPress calls): formula, N/A handling, critical cap, bands, site blend,
 * recommendation ordering. Every number comes from the rule table passed in.
 *
 * Formula (docs/seo-skoru-yontemi.md §4): score = round( 100 * sum(weight * state) / sum(applicable weight) ).
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Rules;

defined( 'ABSPATH' ) || exit;

final class Calculator {

	private const LEVEL_ORDER = array(
		'critical' => 0,
		'warning'  => 1,
		'info'     => 2,
	);

	/** `one_click` first, `mixed` next, then `manual`, `external` (docs/seo-skoru-yontemi.md §9.1). */
	private const FIX_ORDER = array(
		'one_click' => 0,
		'mixed'     => 1,
		'manual'    => 2,
		'external'  => 3,
	);

	/** Half-up rounding to an integer. */
	public static function round_half_up( float $value ): int {
		return (int) floor( $value + 0.5 + 1e-9 );
	}

	/**
	 * Share of passed checks (the default partial-credit formula `passed_checks / total`).
	 *
	 * @param array<string, bool> $checks Check id => passed.
	 */
	public static function ratio( array $checks ): float {
		return $checks ? count( array_filter( $checks ) ) / count( $checks ) : 0.0;
	}

	/**
	 * Score of one rule set.
	 *
	 * @param list<array<string, mixed>> $rules  Rules of one scope.
	 * @param array<string, float|null>  $states Rule id => 0..1, null = not applicable. A missing id is not applicable.
	 * @param int                        $cap    Score ceiling while a critical rule is 0.
	 * @return array{score: int, raw: float, capped_raw: float, numerator: float, applicable_weight: float, cap_applied: bool, critical_failed: list<string>}
	 */
	public static function score( array $rules, array $states, int $cap ): array {
		$numerator = 0.0;
		$weight    = 0.0;
		$failed    = array();
		foreach ( $rules as $rule ) {
			$state = $states[ $rule['id'] ] ?? null;
			if ( null === $state ) {
				continue;
			}
			$state      = max( 0.0, min( 1.0, (float) $state ) );
			$numerator += (float) $rule['weight'] * $state;
			$weight    += (float) $rule['weight'];
			if ( 'critical' === $rule['level'] && 0.0 === $state ) {
				$failed[] = (string) $rule['id'];
			}
		}
		$raw        = $weight > 0.0 ? 100.0 * $numerator / $weight : 0.0;
		$capped_raw = ( $failed && $raw > $cap ) ? (float) $cap : $raw;
		return array(
			'score'             => self::round_half_up( $capped_raw ),
			'raw'               => $raw,
			'capped_raw'        => $capped_raw,
			'numerator'         => $numerator,
			'applicable_weight' => $weight,
			'cap_applied'       => $capped_raw < $raw && self::round_half_up( $raw ) > $cap,
			'critical_failed'   => $failed,
		);
	}

	/**
	 * Site score: blend of the configuration score and the mean of post scores, then the critical cap.
	 *
	 * @param float                              $config_raw      Unrounded configuration score (0..100).
	 * @param float|null                         $posts_mean      Mean of the (capped, unrounded) post scores; null = no post scored.
	 * @param list<string>                       $critical_failed Failed critical site rules.
	 * @param array{config: float, posts: float} $blend           Component weights.
	 * @param int                                $cap             Score ceiling.
	 * @return array{score: int, raw: float, cap_applied: bool}
	 */
	public static function site( float $config_raw, ?float $posts_mean, array $critical_failed, array $blend, int $cap ): array {
		$raw   = null === $posts_mean ? $config_raw : $blend['config'] * $config_raw + $blend['posts'] * $posts_mean;
		$score = self::round_half_up( $raw );
		$hit   = $critical_failed && $score > $cap;
		return array(
			'score'       => $hit ? $cap : $score,
			'raw'         => $raw,
			'cap_applied' => (bool) $hit,
		);
	}

	/**
	 * @param list<array<string, mixed>> $bands Band table.
	 * @param int                        $score Score.
	 * @return array<string, mixed> The band that contains the score.
	 */
	public static function band( array $bands, int $score ): array {
		foreach ( $bands as $band ) {
			if ( $score >= (int) $band['min'] && $score <= (int) $band['max'] ) {
				return $band;
			}
		}
		return $bands[0];
	}

	/**
	 * Median of a list (information only; never feeds the score).
	 *
	 * @param list<float> $values Values.
	 */
	public static function median( array $values ): ?float {
		$count = count( $values );
		if ( 0 === $count ) {
			return null;
		}
		sort( $values );
		$mid = intdiv( $count, 2 );
		return 1 === $count % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	/**
	 * Ordering of recommendations: level, weight (desc), fix type, rule id (docs/seo-skoru-yontemi.md §9.1).
	 *
	 * @param list<array<string, mixed>> $items Items with `level`, `weight`, `fix`, `rule_id`.
	 * @return list<array<string, mixed>>
	 */
	public static function sort_recommendations( array $items ): array {
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return array(
					self::LEVEL_ORDER[ $a['level'] ] ?? 9,
					-1 * (float) $a['weight'],
					self::FIX_ORDER[ $a['fix'] ] ?? 9,
					(string) $a['rule_id'],
				) <=> array(
					self::LEVEL_ORDER[ $b['level'] ] ?? 9,
					-1 * (float) $b['weight'],
					self::FIX_ORDER[ $b['fix'] ] ?? 9,
					(string) $b['rule_id'],
				);
			}
		);
		return $items;
	}
}
