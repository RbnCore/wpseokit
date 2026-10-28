<?php
/**
 * SEO score engine: site score, post score, recommendations, dismissals, REST routes.
 *
 * Rules, weights, levels and texts live in `Rules/score-rules.json` (single source); arithmetic is in
 * `Rules\Calculator`; measurements are in `Rules\SiteChecks`, `Rules\PostChecks`.
 * Design: docs/kapsam-ve-mimari.md §3.10. Contract: docs/sozlesme.md §2, §4.5.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;
use Wpseokit\Settings;
use Wpseokit\Rules\Calculator;
use Wpseokit\Rules\Probe;
use Wpseokit\Rules\PostChecks;
use Wpseokit\Rules\RuleSet;
use Wpseokit\Rules\SiteChecks;

defined( 'ABSPATH' ) || exit;

final class ScoreService {

	public const OPTION_SITE      = 'wpseokit_score_site';
	public const OPTION_DISMISSED = 'wpseokit_score_dismissed';
	public const META_POST        = '_wpseokit_score';
	public const CRON_POST        = 'wpseokit_score_post';
	public const CRON_SITE        = 'wpseokit_score_site_recalc';
	public const CRON_DAILY       = 'wpseokit_score_daily';

	private const CACHE_GROUP  = 'wpseokit';
	private const CACHE_SITE   = 'score_site';
	private const LOCK         = 'wpseokit_score_lock';
	private const DISMISS_DAYS = 30;

	/** Registers all hooks. */
	public static function init(): void {
		add_action( self::CRON_POST, array( self::class, 'cron_post' ) );
		add_action( self::CRON_SITE, array( self::class, 'cron_site' ) );
		add_action( self::CRON_DAILY, array( self::class, 'cron_daily' ) );
		add_action( 'save_post_post', array( self::class, 'on_save_post' ), 20, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'schedule_site' ) );
		add_action( 'init', array( self::class, 'schedule_daily' ) );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
	}

	/* ------------------------------------------------------------------ scheduling */

	/** A published post was saved: recompute its score shortly, out of the save request. */
	public static function on_save_post( $post_id, $post ): void {
		$post_id = (int) $post_id;
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_POST, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_POST, array( $post_id ) );
		}
	}

	/** Settings were saved: refresh the site score soon. */
	public static function schedule_site(): void {
		if ( ! wp_next_scheduled( self::CRON_SITE ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_SITE );
		}
	}

	/** Keeps the daily event in line with `score.daily_recalc`. */
	public static function schedule_daily(): void {
		$on        = (bool) self::settings()['daily_recalc'];
		$scheduled = wp_next_scheduled( self::CRON_DAILY );
		if ( $on && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		} elseif ( ! $on && $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_DAILY );
		}
	}

	public static function cron_post( $post_id = 0 ): void {
		if ( self::compute_post( (int) $post_id ) ) {
			self::refresh_posts_part();
		}
	}

	public static function cron_site(): void {
		self::log_failure( self::compute_site() );
	}

	public static function cron_daily(): void {
		self::log_failure( self::compute_site() );
	}

	/** A background run that returns an error is written to the Logs error list. */
	private static function log_failure( $result ): void {
		if ( is_wp_error( $result ) ) {
			EventLogService::error( 'score', $result->get_error_message() );
		}
	}

	/* ------------------------------------------------------------------ helpers */

	/** @return array<string, mixed> */
	private static function settings(): array {
		return SettingsRepository::section( 'score' );
	}

	private static function sitemap_url(): string {
		return (string) apply_filters( 'wpseokit_score_sitemap_url', SitemapService::index_url() );
	}

	/** Translates a text of the rule file (source language English). */
	private static function t( string $en ): string {
		return __( $en, 'wpseokit' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	/** Band id and label of a score. */
	private static function band_info( int $score ): array {
		$band = Calculator::band( RuleSet::bands(), $score );
		return array(
			'id'    => (string) $band['id'],
			'label' => self::t( (string) $band['label_en'] ),
		);
	}

	/** Maximum age of a stored result before it is reported as stale. */
	private static function stale( int $calculated_at ): bool {
		return ( time() - $calculated_at ) > (int) self::settings()['cache_hours'] * HOUR_IN_SECONDS;
	}

	/**
	 * Stored rule results (`id => {state, measured, checks}`) joined with the rule table.
	 *
	 * @param string                       $scope  site|post.
	 * @param array<string, array<string, mixed>> $stored Stored results.
	 * @return list<array<string, mixed>>
	 */
	private static function decorate( string $scope, array $stored ): array {
		$out = array();
		foreach ( RuleSet::rules( $scope ) as $rule ) {
			$row   = $stored[ $rule['id'] ] ?? array();
			$state = array_key_exists( 'state', $row ) && null !== $row['state'] ? (float) $row['state'] : null;
			$out[] = array(
				'id'       => (string) $rule['id'],
				'level'    => (string) $rule['level'],
				'weight'   => (float) $rule['weight'],
				'state'    => $state,
				'points'   => null === $state ? null : round( (float) $rule['weight'] * $state, 2 ),
				'measured' => (string) ( $row['measured'] ?? '' ),
				'checks'   => (object) ( $row['checks'] ?? array() ),
				'title'    => self::t( (string) $rule['title']['en'] ),
				'note'     => null === $state ? self::t( 'Not applicable on this site, so it is left out of the calculation.' ) : '',
			);
		}
		return $out;
	}

	/**
	 * Stores only what is needed to rebuild the view.
	 *
	 * @param array<string, array<string, mixed>> $measured Check results.
	 * @return array<string, array<string, mixed>>
	 */
	private static function slim( array $measured ): array {
		$out = array();
		foreach ( $measured as $id => $row ) {
			$out[ $id ] = array(
				'state'    => $row['state'],
				'measured' => $row['measured'],
				'checks'   => $row['checks'],
			);
		}
		return $out;
	}

	/** @return array<string, float|null> */
	private static function states( array $measured ): array {
		$out = array();
		foreach ( $measured as $id => $row ) {
			$out[ $id ] = $row['state'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ post score */

	/**
	 * Measures one post and stores the result in post meta.
	 *
	 * @return array<string, mixed>|null Stored result, null when the post cannot be measured.
	 */
	public static function compute_post( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$run = PostChecks::run( $post, self::sitemap_url() );
		if ( null === $run ) {
			return null;
		}
		$calc   = Calculator::score( RuleSet::rules( 'post' ), self::states( $run['rules'] ), RuleSet::cap() );
		$stored = array(
			'score'         => $calc['score'],
			'raw'           => round( $calc['capped_raw'], 4 ),
			'cap_applied'   => $calc['cap_applied'],
			'calculated_at' => time(),
			'rules'         => self::slim( $run['rules'] ),
		);
		update_post_meta( $post_id, self::META_POST, wp_slash( (string) wp_json_encode( $stored ) ) );
		return $stored;
	}

	/** @return array<string, mixed>|null */
	private static function stored_post( int $post_id ): ?array {
		$json = get_post_meta( $post_id, self::META_POST, true );
		$data = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
		return is_array( $data ) && isset( $data['score'], $data['rules'] ) ? $data : null;
	}

	/**
	 * Post score for the REST/UI view.
	 *
	 * @param bool $live True = measure now (HTTP); false = use the stored result when there is one.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function post_score( int $post_id, bool $live = false ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return new \WP_Error( 'wpseokit_score_not_found', __( 'Post not found.', 'wpseokit' ), array( 'status' => 404 ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return new \WP_Error( 'wpseokit_score_not_published', __( 'Only published posts have an SEO score.', 'wpseokit' ), array( 'status' => 422 ) );
		}
		$stored = $live ? null : self::stored_post( $post_id );
		if ( null === $stored ) {
			Probe::reset();
			$stored = self::compute_post( $post_id );
			if ( null === $stored ) {
				return new \WP_Error( 'wpseokit_score_unmeasurable', __( 'The page did not answer 200, so it could not be measured.', 'wpseokit' ), array( 'status' => 502 ) );
			}
		}
		return array(
			'post_id'       => $post_id,
			'score'         => (int) $stored['score'],
			'raw'           => (float) $stored['raw'],
			'band'          => self::band_info( (int) $stored['score'] ),
			'cap_applied'   => (bool) $stored['cap_applied'],
			'cap_note'      => ! empty( $stored['cap_applied'] ) ? self::t( 'A critical rule is failing, so the score is capped at 79 until it is fixed.' ) : '',
			'rules'         => self::decorate( 'post', $stored['rules'] ),
			'calculated_at' => (int) $stored['calculated_at'],
			'stale'         => self::stale( (int) $stored['calculated_at'] ),
		);
	}

	/* ------------------------------------------------------------------ site score */

	/**
	 * Ids of the newest published posts that feed the site score.
	 *
	 * @return list<int>
	 */
	private static function sample_ids( bool $all ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'              => 'post',
					'post_status'            => 'publish',
					'posts_per_page'         => $all ? -1 : RuleSet::post_sample(),
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'suppress_filters'       => true,
				)
			)
		);
	}

	/**
	 * Post component of the site score from stored post scores; measures the missing or outdated ones
	 * while the time budget lasts.
	 *
	 * @param bool $measure  Measure missing/outdated posts (HTTP).
	 * @param bool $all      All posts instead of the newest sample.
	 * @return array{mean: float|null, median: float|null, sampled: int, scored: int, pending: int}
	 */
	private static function posts_part( bool $measure, bool $all ): array {
		$ids      = self::sample_ids( $all );
		$deadline = microtime( true ) + (int) self::settings()['batch_seconds'];
		$ttl      = (int) self::settings()['cache_hours'] * HOUR_IN_SECONDS;
		$values   = array();
		$pending  = 0;
		foreach ( $ids as $id ) {
			$stored = self::stored_post( $id );
			$fresh  = null !== $stored
				&& ( time() - (int) $stored['calculated_at'] ) <= $ttl
				&& (int) $stored['calculated_at'] >= (int) get_post_modified_time( 'U', true, $id );
			if ( ! $fresh && $measure && microtime( true ) < $deadline ) {
				$measured = self::compute_post( $id );
				if ( null !== $measured ) {
					$stored = $measured;
					$fresh  = true;
				}
			}
			if ( null === $stored ) {
				++$pending;
				continue;
			}
			$values[] = (float) $stored['raw'];
			if ( ! $fresh ) {
				++$pending;
			}
		}
		return array(
			'mean'    => $values ? array_sum( $values ) / count( $values ) : null,
			'median'  => Calculator::median( $values ),
			'sampled' => count( $ids ),
			'scored'  => count( $values ),
			'pending' => $pending,
		);
	}

	/**
	 * Measures the whole site (site rules over HTTP, then the post sample) and stores the result.
	 *
	 * @param bool $all All posts instead of the newest 200.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function compute_site( bool $all = false ) {
		if ( get_transient( self::LOCK ) ) {
			return new \WP_Error( 'wpseokit_score_busy', __( 'A score calculation is already running.', 'wpseokit' ), array( 'status' => 409 ) );
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		try {
			Probe::reset();
			$measured = SiteChecks::run( self::sitemap_url() );
			$config   = Calculator::score( RuleSet::rules( 'site' ), self::states( $measured ), RuleSet::cap() );
			$posts    = self::posts_part( true, $all );
			$stored   = self::assemble( $config, self::slim( $measured ), $posts );
			update_option( self::OPTION_SITE, $stored, false );
			wp_cache_set( self::CACHE_SITE, $stored, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			if ( $posts['pending'] > 0 && ! wp_next_scheduled( self::CRON_SITE ) ) {
				wp_schedule_single_event( time() + 60, self::CRON_SITE );
			}
			EventLogService::record( 'score', 'score_refreshed', (string) ( $stored['score'] ?? '' ), array( 'tool' => 'score' ) );
			return self::present_site( $stored );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * @param array<string, mixed>                $config Calculator result of the site rules.
	 * @param array<string, array<string, mixed>> $rules  Slim rule results.
	 * @param array<string, mixed>                $posts  Post component.
	 * @return array<string, mixed>
	 */
	private static function assemble( array $config, array $rules, array $posts ): array {
		$site = Calculator::site( $config['raw'], $posts['mean'], $config['critical_failed'], RuleSet::blend(), RuleSet::cap() );
		return array(
			'score'         => $site['score'],
			'raw'           => round( $site['raw'], 4 ),
			'cap_applied'   => $site['cap_applied'],
			'config'        => array(
				'score'             => $config['score'],
				'raw'               => round( $config['raw'], 4 ),
				'numerator'         => round( $config['numerator'], 4 ),
				'applicable_weight' => $config['applicable_weight'],
				'critical_failed'   => $config['critical_failed'],
				'rules'             => $rules,
			),
			'posts'         => $posts,
			'calculated_at' => time(),
		);
	}

	/** After a post score changed: rebuild the site total from the stored site rules and the stored post scores (no HTTP). */
	private static function refresh_posts_part(): void {
		$stored = get_option( self::OPTION_SITE );
		if ( ! is_array( $stored ) || empty( $stored['config']['rules'] ) ) {
			return;
		}
		$config = Calculator::score( RuleSet::rules( 'site' ), self::states( $stored['config']['rules'] ), RuleSet::cap() );
		$next   = self::assemble( $config, $stored['config']['rules'], self::posts_part( false, false ) );
		$next['calculated_at'] = (int) $stored['calculated_at'];
		update_option( self::OPTION_SITE, $next, false );
		wp_cache_set( self::CACHE_SITE, $next, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Site score for the REST/UI view: object cache, then the stored option, then a first measurement.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function site_score() {
		$stored = wp_cache_get( self::CACHE_SITE, self::CACHE_GROUP );
		if ( ! is_array( $stored ) ) {
			$stored = get_option( self::OPTION_SITE );
			if ( is_array( $stored ) && isset( $stored['score'], $stored['config'] ) ) {
				wp_cache_set( self::CACHE_SITE, $stored, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			} else {
				return self::compute_site();
			}
		}
		return self::present_site( $stored );
	}

	/** @param array<string, mixed> $stored Stored site result. */
	private static function present_site( array $stored ): array {
		$hidden = count( self::recommendations( 'site', 0, false, $stored )['dismissed'] );
		return array(
			'score'            => (int) $stored['score'],
			'raw'              => (float) $stored['raw'],
			'band'             => self::band_info( (int) $stored['score'] ),
			'cap_applied'      => (bool) $stored['cap_applied'],
			'cap_note'         => ! empty( $stored['cap_applied'] ) ? self::t( 'A critical rule is failing, so the score is capped at 79 until it is fixed.' ) : '',
			'config'           => array(
				'score'             => (int) $stored['config']['score'],
				'raw'               => (float) $stored['config']['raw'],
				'numerator'         => (float) $stored['config']['numerator'],
				'applicable_weight' => (float) $stored['config']['applicable_weight'],
				'rules'             => self::decorate( 'site', $stored['config']['rules'] ),
			),
			'posts'            => $stored['posts'],
			'calculated_at'    => (int) $stored['calculated_at'],
			'stale'            => self::stale( (int) $stored['calculated_at'] ),
			'hidden_count'     => $hidden,
			'environment_note' => self::environment_note(),
			'not_a_guarantee'  => self::t( 'This score is not a ranking guarantee.' ),
		);
	}

	/** A local development host cannot satisfy the public-reachability rules; say so. */
	private static function environment_note(): string {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( 'localhost' === $host || 1 === preg_match( '/\.(test|local|localhost|invalid)$/', $host ) || 1 === preg_match( '/^(127\.|10\.|192\.168\.)/', $host ) ) {
			return __( 'This is a local site: the HTTPS, certificate and public-access rules may fail here and pass on the live site.', 'wpseokit' );
		}
		return '';
	}

	/* ------------------------------------------------------------------ recommendations and dismissals */

	/** @return array<string, array<string, mixed>> */
	private static function dismissed(): array {
		$data = get_option( self::OPTION_DISMISSED, array() );
		return is_array( $data ) ? $data : array();
	}

	private static function dismiss_key( string $rule_id, int $post_id ): string {
		return $post_id > 0 ? $rule_id . ':' . $post_id : $rule_id;
	}

	/** A dismissal holds while the state is unchanged and fewer than 30 days passed. */
	private static function dismissal_active( array $record, ?float $state ): bool {
		if ( (float) ( $record['state_at_dismiss'] ?? -1 ) !== (float) $state ) {
			return false;
		}
		return ( time() - (int) ( $record['date'] ?? 0 ) ) < self::DISMISS_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Recommendations of the site rules, or of one post's rules, in the documented order.
	 *
	 * @param string                    $scope           site|post.
	 * @param int                       $post_id         Post (scope=post).
	 * @param bool                      $include_dismissed Keep dismissed items in `items`.
	 * @param array<string, mixed>|null $site            Stored site result (avoids a re-read).
	 * @return array{items: list<array<string, mixed>>, dismissed: list<array<string, mixed>>}
	 */
	public static function recommendations( string $scope, int $post_id = 0, bool $include_dismissed = false, ?array $site = null ): array {
		if ( 'post' === $scope ) {
			$stored = self::stored_post( $post_id );
			$rules  = $stored['rules'] ?? array();
			$total  = 0.0;
		} else {
			$site   = $site ?? get_option( self::OPTION_SITE );
			$rules  = is_array( $site ) ? ( $site['config']['rules'] ?? array() ) : array();
			$total  = 0.0;
		}
		foreach ( RuleSet::rules( 'post' === $scope ? 'post' : 'site' ) as $rule ) {
			if ( null !== ( $rules[ $rule['id'] ]['state'] ?? null ) ) {
				$total += (float) $rule['weight'];
			}
		}
		$records   = self::dismissed();
		$items     = array();
		$dismissed = array();
		foreach ( RuleSet::rules( 'post' === $scope ? 'post' : 'site' ) as $rule ) {
			$row   = $rules[ $rule['id'] ] ?? array();
			$state = $row['state'] ?? null;
			if ( null === $state || (float) $state >= 1.0 ) {
				continue;
			}
			$key    = self::dismiss_key( (string) $rule['id'], 'post' === $scope ? $post_id : 0 );
			$hidden = ! empty( $rule['dismissible'] ) && isset( $records[ $key ] ) && self::dismissal_active( $records[ $key ], (float) $state );
			$item   = array(
				'rule_id'     => (string) $rule['id'],
				'scope'       => $scope,
				'level'       => (string) $rule['level'],
				'weight'      => (float) $rule['weight'],
				'state'       => (float) $state,
				'gain'        => $total > 0 ? round( 100 * (float) $rule['weight'] * ( 1 - (float) $state ) / $total, 1 ) : 0.0,
				'measured'    => (string) ( $row['measured'] ?? '' ),
				'title'       => self::t( (string) $rule['title']['en'] ),
				'text'        => self::t( (string) $rule['recommendation']['en'] ),
				'fix'         => (string) $rule['recommendation']['fix'],
				'target'      => (string) $rule['recommendation']['target'],
				'dismissible' => ! empty( $rule['dismissible'] ),
				'dismissed'   => $hidden,
			);
			if ( $hidden ) {
				$dismissed[] = $item;
				if ( ! $include_dismissed ) {
					continue;
				}
			}
			$items[] = $item;
		}
		return array(
			'items'     => Calculator::sort_recommendations( $items ),
			'dismissed' => Calculator::sort_recommendations( $dismissed ),
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function dismiss( string $rule_id, int $post_id = 0 ) {
		$rule = RuleSet::find( $rule_id );
		if ( null === $rule ) {
			return new \WP_Error( 'wpseokit_score_unknown_rule', __( 'Unknown rule.', 'wpseokit' ), array( 'status' => 404 ) );
		}
		if ( empty( $rule['dismissible'] ) ) {
			return new \WP_Error( 'wpseokit_score_not_dismissible', __( 'A critical rule cannot be dismissed.', 'wpseokit' ), array( 'status' => 422 ) );
		}
		$post_id = 'post' === $rule['scope'] ? $post_id : 0;
		if ( 'post' === $rule['scope'] && $post_id < 1 ) {
			return new \WP_Error( 'wpseokit_score_post_required', __( 'A post rule needs a post_id.', 'wpseokit' ), array( 'status' => 400 ) );
		}
		$stored = $post_id > 0 ? self::stored_post( $post_id ) : get_option( self::OPTION_SITE );
		$rules  = $post_id > 0 ? ( $stored['rules'] ?? array() ) : ( is_array( $stored ) ? ( $stored['config']['rules'] ?? array() ) : array() );
		$state  = $rules[ $rule_id ]['state'] ?? 0;
		$all    = self::dismissed();
		$all[ self::dismiss_key( $rule_id, $post_id ) ] = array(
			'user_id'          => get_current_user_id(),
			'date'             => time(),
			'state_at_dismiss' => (float) $state,
		);
		update_option( self::OPTION_DISMISSED, $all, false );
		return array(
			'dismissed' => true,
			'rule_id'   => $rule_id,
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function restore( string $rule_id, int $post_id = 0 ) {
		if ( null === RuleSet::find( $rule_id ) ) {
			return new \WP_Error( 'wpseokit_score_unknown_rule', __( 'Unknown rule.', 'wpseokit' ), array( 'status' => 404 ) );
		}
		$all = self::dismissed();
		unset( $all[ self::dismiss_key( $rule_id, $post_id ) ] );
		update_option( self::OPTION_DISMISSED, $all, false );
		return array(
			'dismissed' => false,
			'rule_id'   => $rule_id,
		);
	}

	/* ------------------------------------------------------------------ rules view */

	/** Data for the "How is this calculated?" page, taken from the rule file. */
	public static function rules_view(): array {
		$data  = RuleSet::data();
		$scope = static function ( string $name ): array {
			$out = array();
			foreach ( RuleSet::rules( $name ) as $rule ) {
				$out[] = array(
					'id'          => (string) $rule['id'],
					'level'       => (string) $rule['level'],
					'weight'      => (float) $rule['weight'],
					'title'       => self::t( (string) $rule['title']['en'] ),
					'measures'    => (string) $rule['measures'],
					'pass'        => (string) $rule['pass_condition'],
					'dismissible' => ! empty( $rule['dismissible'] ),
					'source'      => (string) ( $rule['source']['doc'] ?? '' ),
				);
			}
			return $out;
		};
		$bands = array();
		foreach ( RuleSet::bands() as $band ) {
			$bands[] = array(
				'id'    => (string) $band['id'],
				'min'   => (int) $band['min'],
				'max'   => (int) $band['max'],
				'label' => self::t( (string) $band['label_en'] ),
			);
		}
		return array(
			'formula'      => $data['formula'],
			'site_blend'   => RuleSet::blend(),
			'critical_cap' => RuleSet::cap(),
			'bands'        => $bands,
			'rules'        => array(
				'site' => $scope( 'site' ),
				'post' => $scope( 'post' ),
			),
			'excluded'     => $data['excluded_rules'] ?? array(),
		);
	}

	/* ------------------------------------------------------------------ REST */

	/** Registers the `/score/*` routes (docs/sozlesme.md §4.5). */
	public static function register_routes( string $namespace ): void {
		$manage = Rest::permission();
		$edit   = array( Rest::class, 'can_edit_post' );
		$id_arg = array(
			'post_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			$namespace,
			'/score/site',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => self::respond( self::site_score() ),
				'permission_callback' => $manage,
				'args'                => array(),
			)
		);
		register_rest_route(
			$namespace,
			'/score/site/recalculate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => self::respond( self::compute_site( 'all' === $request->get_param( 'posts' ) ) ),
				'permission_callback' => $manage,
				'args'                => array(
					'posts' => array(
						'type' => 'string',
						'enum' => array( 'sample', 'all' ),
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/score/post/(?P<post_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => self::respond( self::post_score( (int) $request['post_id'], false ) ),
				'permission_callback' => $edit,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			$namespace,
			'/score/post/(?P<post_id>\d+)/recalculate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => self::respond( self::post_score( (int) $request['post_id'], true ) ),
				'permission_callback' => $edit,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			$namespace,
			'/score/recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_recommendations' ),
				'permission_callback' => $manage,
				'args'                => array(
					'scope'             => array(
						'type'    => 'string',
						'enum'    => array( 'site', 'post' ),
						'default' => 'site',
					),
					'post_id'           => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'include_dismissed' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/score/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => self::respond( self::dismiss( (string) $request['rule_id'], (int) $request['post_id'] ) ),
				'permission_callback' => $manage,
				'args'                => array(
					'rule_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'post_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/score/dismiss/(?P<rule_id>[a-z0-9_]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => self::respond( self::restore( (string) $request['rule_id'], (int) $request['post_id'] ) ),
				'permission_callback' => $manage,
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/score/rules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => new \WP_REST_Response( self::rules_view() ),
				'permission_callback' => $manage,
				'args'                => array(),
			)
		);
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result Service result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function respond( $result ) {
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	public static function rest_recommendations( \WP_REST_Request $request ) {
		$scope   = (string) $request['scope'];
		$post_id = (int) $request['post_id'];
		if ( 'post' === $scope && ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) ) {
			return new \WP_Error( 'wpseokit_score_post_required', __( 'A post scope needs a post_id you can edit.', 'wpseokit' ), array( 'status' => 400 ) );
		}
		$list = self::recommendations( $scope, $post_id, (bool) $request['include_dismissed'] );
		return new \WP_REST_Response(
			array(
				'items'        => $list['items'],
				'dismissed'    => $list['dismissed'],
				'hidden_count' => count( $list['dismissed'] ),
				'note'         => self::t( 'This score is not a ranking guarantee.' ),
			)
		);
	}
}
