<?php
/**
 * Reports (package R): one snapshot of the plugin's own measurements per day, a trend view over 7/30/90 days and a CSV
 * export. Nothing is measured here: every value is read from the service or repository that owns it
 * (docs/kapsam-ve-mimari.md §3.12). No personal data is stored.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Logs;
use Wpseokit\Models\Snapshot;
use Wpseokit\Plugin;
use Wpseokit\Repositories\RedirectRepository;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Repositories\SnapshotRepository;
use Wpseokit\Rest;
use Wpseokit\Rules\RuleSet;
use Wpseokit\Schema\Article;
use Wpseokit\Schema\Movie;

defined( 'ABSPATH' ) || exit;

final class ReportService {

	public const CRON_DAILY = 'wpseokit_report_daily';
	public const CRON_LATER = 'wpseokit_report_snapshot';

	/** Seconds a manual snapshot may take before it is left to the background. */
	private const BUDGET = 5.0;

	/** Seconds before a deferred snapshot runs. */
	private const DEFER = 120;

	/** Trend ranges in days. */
	private const RANGES = array( 7, 30, 90 );

	/** Schema types counted, in column order. */
	private const SCHEMA_TYPES = array( 'BlogPosting', 'Article', 'NewsArticle', 'Movie', 'TVSeries' );

	/** Column key => type, in the order of the trend view and the CSV. Sitemap groups are added after `sitemap.total`. */
	private const COLUMNS = array(
		'score.site'                => 'int',
		'score.config'              => 'int',
		'score.posts'               => 'float',
		'score.cap_applied'         => 'flag',
		'rules.total'               => 'int',
		'rules.passed'              => 'int',
		'rules.remaining'           => 'int',
		'rules.na'                  => 'int',
		'sitemap.enabled'           => 'flag',
		'sitemap.total'             => 'int',
		'redirects.active'          => 'int',
		'redirects.hits'            => 'int',
		'not_found.addresses'       => 'int',
		'not_found.hits'            => 'int',
		'not_found.new_24h'         => 'int',
		'meta.posts'                => 'int',
		'meta.title_pct'            => 'float',
		'meta.description_pct'      => 'float',
		'schema.BlogPosting'        => 'int',
		'schema.Article'            => 'int',
		'schema.NewsArticle'        => 'int',
		'schema.Movie'              => 'int',
		'schema.TVSeries'           => 'int',
		'feed.enabled'              => 'flag',
		'llms.enabled'              => 'flag',
		'yoast.active'              => 'flag',
	);

	public static function init(): void {
		add_action( self::CRON_DAILY, array( self::class, 'cron_daily' ) );
		add_action( self::CRON_LATER, array( self::class, 'cron_later' ) );
		add_action( 'init', array( self::class, 'schedule_daily' ) );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_csv' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ cron */

	/** Keeps the daily event in line with `reports.daily_enabled`. */
	public static function schedule_daily(): void {
		$on        = (bool) SettingsRepository::get( 'reports.daily_enabled', true );
		$scheduled = wp_next_scheduled( self::CRON_DAILY );
		if ( $on && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		} elseif ( ! $on && $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_DAILY );
		}
	}

	public static function cron_daily(): void {
		if ( (bool) SettingsRepository::get( 'reports.daily_enabled', true ) ) {
			self::take( true );
		}
	}

	public static function cron_later(): void {
		self::take( true );
	}

	/* ------------------------------------------------------------------ collecting */

	/**
	 * Takes today's snapshot (site time zone). A second call on the same day replaces the row.
	 *
	 * @param bool $background True in cron: no time budget.
	 * @return array{status: string, created?: bool, snapshot?: array<string, mixed>, message?: string}
	 */
	public static function take( bool $background = false ): array {
		$started = microtime( true );
		$stored  = get_option( ScoreService::OPTION_SITE );
		if ( ! is_array( $stored ) || ! isset( $stored['score'], $stored['config']['score'] ) ) {
			ScoreService::schedule_site();
			return self::later();
		}
		$metrics = self::collect( $stored );
		if ( ! $background && ( microtime( true ) - $started ) > self::BUDGET ) {
			return self::later();
		}
		$date    = wp_date( 'Y-m-d' );
		$created = SnapshotRepository::upsert( $date, $metrics, Snapshot::SCHEMA_VERSION );
		if ( null === $created ) {
			Logs::error( 'snapshot', 'The snapshot could not be saved.' );
			return array(
				'status'  => 'error',
				'message' => __( 'The snapshot could not be saved.', 'wpseokit' ),
			);
		}
		SnapshotRepository::prune( (int) SettingsRepository::get( 'reports.retention_days', 400 ) );
		Logs::activity( 'snapshot', $created ? 'snapshot_taken' : 'snapshot_replaced', $date, 'reports' );
		$row = SnapshotRepository::find_by_date( $date );
		return array(
			'status'   => 'ok',
			'created'  => $created,
			'snapshot' => $row ? $row->to_array() : array(),
		);
	}

	/** @return array{status: string, message: string} */
	private static function later(): array {
		if ( ! wp_next_scheduled( self::CRON_LATER ) ) {
			wp_schedule_single_event( time() + self::DEFER, self::CRON_LATER );
		}
		return array(
			'status'  => 'preparing',
			'message' => __( 'The snapshot is being prepared in the background. Check again in a few minutes.', 'wpseokit' ),
		);
	}

	/**
	 * Reads every metric from its owner.
	 *
	 * @param array<string, mixed> $stored Stored site score (`ScoreService::OPTION_SITE`).
	 * @return array<string, mixed>
	 */
	private static function collect( array $stored ): array {
		$rule_rows = (array) ( $stored['config']['rules'] ?? array() );
		$rules     = array(
			'total'     => 0,
			'passed'    => 0,
			'remaining' => 0,
			'na'        => 0,
		);
		foreach ( RuleSet::rules( 'site' ) as $rule ) {
			++$rules['total'];
			$state = $rule_rows[ $rule['id'] ]['state'] ?? null;
			if ( null === $state ) {
				++$rules['na'];
			} elseif ( (float) $state >= 1.0 ) {
				++$rules['passed'];
			} else {
				++$rules['remaining'];
			}
		}
		$mean = $stored['posts']['mean'] ?? null;

		$sitemap_on = SitemapService::enabled();
		$groups     = array();
		$total      = 0;
		if ( $sitemap_on ) {
			foreach ( SitemapService::files() as $file ) {
				$group            = (string) strstr( (string) $file['name'], '-sitemap', true );
				$groups[ $group ] = ( $groups[ $group ] ?? 0 ) + (int) $file['count'];
				$total           += (int) $file['count'];
			}
		}

		$not_found = SnapshotRepository::not_found_totals( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$types     = array_values( array_diff( array_keys( get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) ) );
		$coverage  = SnapshotRepository::meta_coverage( $types );
		$percent   = static fn( int $part ): float => $coverage['posts'] > 0 ? round( 100 * $part / $coverage['posts'], 1 ) : 0.0;

		return array(
			'score'     => array(
				'site'          => (int) $stored['score'],
				'config'        => (int) $stored['config']['score'],
				'posts'         => null === $mean ? null : round( (float) $mean, 1 ),
				'cap_applied'   => (bool) ( $stored['cap_applied'] ?? false ),
				'calculated_at' => (int) ( $stored['calculated_at'] ?? 0 ),
			),
			'rules'     => $rules,
			'sitemap'   => array(
				'enabled' => $sitemap_on,
				'total'   => $total,
				'groups'  => $groups,
			),
			'redirects' => array(
				'active' => RedirectRepository::count_active(),
				'hits'   => SnapshotRepository::redirect_hits(),
			),
			'not_found' => array(
				'addresses' => $not_found['addresses'],
				'hits'      => $not_found['hits'],
				'new_24h'   => $not_found['new'],
			),
			'meta'      => array(
				'posts'           => $coverage['posts'],
				'title_pct'       => $percent( $coverage['titled'] ),
				'description_pct' => $percent( $coverage['described'] ),
			),
			'schema'    => self::schema_counts(),
			'feed'      => array( 'enabled' => (bool) SettingsRepository::get( 'feed.main_enabled', true ) ),
			'llms'      => array( 'enabled' => (bool) SettingsRepository::get( 'llms.enabled', false ) ),
			'yoast'     => array( 'active' => Plugin::yoast_active() ),
		);
	}

	/**
	 * Published posts per effective schema type.
	 *
	 * @return array<string, int>
	 */
	private static function schema_counts(): array {
		$counts = array_fill_keys( self::SCHEMA_TYPES, 0 );
		$posts  = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'suppress_filters'       => true,
			)
		);
		foreach ( $posts as $post ) {
			$type = Movie::resolve_post_schema_type( $post );
			if ( 'Article' === $type ) {
				$type = Article::article_type();
			}
			if ( isset( $counts[ $type ] ) ) {
				++$counts[ $type ];
			}
		}
		return $counts;
	}

	/* ------------------------------------------------------------------ columns */

	/**
	 * Flat `dotted.key => value` form of a snapshot; flags become 1/0.
	 *
	 * @param array<string, mixed> $metrics Nested metrics.
	 * @return array<string, int|float|null>
	 */
	public static function flatten( array $metrics ): array {
		$out = array();
		foreach ( $metrics as $section => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $key => $value ) {
				if ( 'score.calculated_at' === $section . '.' . $key ) {
					continue;
				}
				if ( 'groups' === $key && 'sitemap' === $section && is_array( $value ) ) {
					foreach ( $value as $group => $count ) {
						$out[ 'sitemap.group.' . $group ] = (int) $count;
					}
					continue;
				}
				$out[ $section . '.' . $key ] = null === $value ? null : ( is_bool( $value ) ? (int) $value : ( is_float( $value ) || is_int( $value ) ? $value : (float) $value ) );
			}
		}
		return $out;
	}

	/**
	 * Columns shared by the trend view and the CSV: the fixed list, with the sitemap groups seen in the rows.
	 *
	 * @param Snapshot[] $snapshots Rows in view.
	 * @return list<array{key: string, type: string}>
	 */
	public static function columns( array $snapshots ): array {
		$groups = array();
		foreach ( $snapshots as $snapshot ) {
			foreach ( (array) ( $snapshot->metrics['sitemap']['groups'] ?? array() ) as $group => $count ) {
				$groups[ (string) $group ] = true;
			}
		}
		$groups = array_fill_keys( SitemapService::display_sort( array_map( 'strval', array_keys( $groups ) ) ), true );
		$out    = array();
		foreach ( self::COLUMNS as $key => $type ) {
			$out[] = array(
				'key'  => $key,
				'type' => $type,
			);
			if ( 'sitemap.total' === $key ) {
				foreach ( array_keys( $groups ) as $group ) {
					$out[] = array(
						'key'  => 'sitemap.group.' . $group,
						'type' => 'int',
					);
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ trend and CSV */

	/** First day of a range ending today (site time zone). */
	private static function range_start( int $days ): string {
		return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Daily points and a summary for the last `$days` days. Days without a snapshot are left out, never filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function trend( int $days ): array {
		$days  = in_array( $days, self::RANGES, true ) ? $days : 30;
		$from  = self::range_start( $days );
		$to    = wp_date( 'Y-m-d' );
		$snaps = SnapshotRepository::between( $from, $to );
		$cols  = self::columns( $snaps );
		$flat  = array();
		foreach ( $snaps as $snap ) {
			$have   = self::flatten( $snap->metrics );
			$values = array();
			foreach ( $cols as $col ) {
				$values[ $col['key'] ] = $have[ $col['key'] ] ?? null; // A metric the day did not record stays null; nothing is made up.
			}
			$flat[] = array(
				'date'   => $snap->snapshot_date,
				'values' => $values,
			);
		}
		$summary = array();
		if ( $flat ) {
			$first = $flat[0]['values'];
			$last  = $flat[ count( $flat ) - 1 ]['values'];
			foreach ( $cols as $col ) {
				if ( 'flag' === $col['type'] ) {
					continue;
				}
				$start = $first[ $col['key'] ] ?? null;
				$end   = $last[ $col['key'] ] ?? null;
				$diff  = ( null === $start || null === $end ) ? null : $end - $start;
				$summary[ $col['key'] ] = array(
					'start'  => $start,
					'end'    => $end,
					'change' => ( 'float' === $col['type'] && null !== $diff ) ? round( (float) $diff, 1 ) : $diff,
				);
			}
		}
		$latest = SnapshotRepository::latest();
		$out    = array(
			'status'         => $flat ? 'ok' : 'no_data',
			'range'          => $days,
			'from'           => $from,
			'to'             => $to,
			'retention_days' => (int) SettingsRepository::get( 'reports.retention_days', 400 ),
			'daily_enabled'  => (bool) SettingsRepository::get( 'reports.daily_enabled', true ),
			'columns'        => $cols,
			'points'         => $flat,
			'summary'        => (object) $summary,
			'latest'         => $latest ? array(
				'date'     => $latest->snapshot_date,
				'taken_at' => $latest->to_array()['taken_at'],
			) : null,
		);
		if ( ! $flat && 0 === SnapshotRepository::count() ) {
			// Nothing was ever collected: take the first snapshot in the background.
			if ( ! wp_next_scheduled( self::CRON_LATER ) ) {
				wp_schedule_single_event( time() + 10, self::CRON_LATER );
			}
			$out['snapshot_scheduled'] = true;
		}
		return $out;
	}

	/**
	 * CSV text: `date` then one column per metric; UTF-8 without a BOM, CRLF lines, spreadsheet-formula safe cells.
	 *
	 * @param int $days 7, 30 or 90; 0 = every stored day.
	 */
	public static function csv( int $days = 0 ): string {
		$snaps = $days > 0 ? SnapshotRepository::between( self::range_start( $days ), wp_date( 'Y-m-d' ) ) : SnapshotRepository::between( '1970-01-01', '9999-12-31' );
		$cols  = self::columns( $snaps );
		$out   = implode( ',', array_map( array( CsvService::class, 'cell' ), array_merge( array( 'date' ), array_column( $cols, 'key' ) ) ) ) . "\r\n";
		foreach ( $snaps as $snap ) {
			$flat = self::flatten( $snap->metrics );
			$row  = array( CsvService::cell( $snap->snapshot_date ) );
			foreach ( $cols as $col ) {
				$value = $flat[ $col['key'] ] ?? null;
				$row[] = null === $value ? '' : CsvService::cell( (string) $value );
			}
			$out .= implode( ',', $row ) . "\r\n";
		}
		return $out;
	}

	/* ------------------------------------------------------------------ REST */

	/** Registers the `/reports*` routes (docs/sozlesme.md §4.5). */
	public static function register_routes( string $namespace ): void {
		$manage = Rest::permission();
		register_rest_route(
			$namespace,
			'/reports',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => new \WP_REST_Response( self::trend( (int) $request['range'] ) ),
				'permission_callback' => $manage,
				'args'                => array(
					'range' => array(
						'type'    => 'string',
						'enum'    => array( '7', '30', '90' ),
						'default' => '30',
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/reports/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_export' ),
				'permission_callback' => $manage,
				'args'                => array(
					'range' => array(
						'type'    => 'string',
						'enum'    => array( '7', '30', '90', 'all' ),
						'default' => 'all',
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/reports/snapshot',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_snapshot' ),
				'permission_callback' => $manage,
				'args'                => array(),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_snapshot() {
		$result = self::take();
		if ( 'error' === $result['status'] ) {
			return new \WP_Error( 'wpseokit_report_failed', (string) $result['message'], array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( $result, 'preparing' === $result['status'] ? 202 : 200 );
	}

	public static function rest_export( \WP_REST_Request $request ): \WP_REST_Response {
		$range = (string) $request['range'];
		$resp  = new \WP_REST_Response( self::csv( 'all' === $range ? 0 : (int) $range ) );
		$resp->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$resp->header( 'Content-Disposition', 'attachment; filename="wpseokit-reports.csv"' );
		return $resp;
	}

	/**
	 * Prints the export as plain CSV instead of JSON.
	 *
	 * @param bool                    $served  Whether the request was already served.
	 * @param \WP_HTTP_Response|mixed $result  Response.
	 * @param \WP_REST_Request        $request Request.
	 */
	public static function serve_csv( $served, $result, $request ): bool {
		if ( $served || ! $result instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request || '/' . Rest::NAMESPACE . '/reports/export' !== $request->get_route() ) {
			return (bool) $served;
		}
		if ( 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return false;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV text; every cell is protected by CsvService::cell().
		return true;
	}
}
