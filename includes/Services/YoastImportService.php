<?php
/**
 * Yoast SEO import: dry run, run, verify. Yoast data is only READ, never changed or deleted.
 *
 * - dry_run(): writes nothing, returns the count report.
 * - run(): writes. A target that is empty (or still the default) is written; a target that already holds the same value
 *   is skipped; a target with another value is a conflict and is skipped unless `$overwrite` is true. Running it again
 *   therefore writes nothing (idempotent) and never overwrites something edited by hand.
 * - verify(): re-plans without writing and checks that nothing is left to import and that Yoast data is unchanged.
 *
 * The primary category (`_wpseokit_primary_category`) is the critical part: the permalink is `/%category%/%postname%/`,
 * so a post without it moves to another URL when Yoast goes away.
 *
 * Contract: docs/kapsam-ve-mimari.md §4, docs/sozlesme.md §4 (`/import/yoast`, `/import/state`).
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use AdminKit\Core\Security\Guard;

use Wpseokit\Logs;
use Wpseokit\Models\ImportReport;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class YoastImportService {

	public const STATE_OPTION = 'wpseokit_import_state';

	/** Yoast `separator` option value => the character. */
	public const SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	private const POST_OG_IMAGE = '_wpseokit_og_image';

	/** Yoast tables (without prefix) that exist only to be read. */
	private const YOAST_TABLES = array( 'yoast_indexable', 'yoast_indexable_hierarchy', 'yoast_seo_links', 'yoast_migrations', 'yoast_expiring_store', 'yoast_primary_term' );

	/** Yoast options that carry data (never written by this class). */
	private const YOAST_OPTIONS = array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_taxonomy_meta' );

	/** Post meta rows read for the "not migrated" list. */
	private const UNMIGRATED_META = array(
		'_yoast_wpseo_content_score'                  => 'Content score (analysis result, no feature here)',
		'_yoast_wpseo_estimated-reading-time-minutes' => 'Reading time (WordPress counts it again)',
		'_yoast_wpseo_focuskw'                        => 'Focus keyword (no feature here)',
	);

	public static function init(): void {
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		if ( defined( 'WP_CLI' ) && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'wpseokit import-yoast', array( self::class, 'cli' ) );
		}
	}

	/* ------------------------------------------------------------------ public API */

	/** Counts what an import would do. Writes nothing. */
	public static function dry_run( bool $overwrite = false ): ImportReport {
		return self::process( ImportReport::MODE_DRY_RUN, $overwrite );
	}

	/** Imports. Safe to repeat: the second run writes nothing. */
	public static function run( bool $overwrite = false ): ImportReport {
		return self::process( ImportReport::MODE_APPLY, $overwrite );
	}

	/**
	 * Re-plans without writing. `extra.ok` = 1 when nothing is left to import, the counters add up and the Yoast data
	 * still matches the snapshot taken before the first import (`$baseline`, see state()).
	 *
	 * @param array<string, int> $baseline Source counts recorded before the import (empty: compare nothing).
	 */
	public static function verify( array $baseline = array() ): ImportReport {
		$report    = self::process( ImportReport::MODE_VERIFY, false );
		$unchanged = array() === $baseline || self::same_counts( $baseline, $report->source_counts() );
		$report->set_extra( 'source_unchanged', $unchanged ? 1 : 0 );
		$report->set_extra( 'ok', ( 0 === $report->total( 'will_import' ) && array() === $report->unbalanced() && $unchanged ) ? 1 : 0 );
		return $report;
	}

	/* ------------------------------------------------------------------ state, REST, CLI */

	/** @return array<string, mixed> `{ state: none|dry-run|applied, time, report, baseline }`. */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || ! isset( $state['state'] ) ) {
			return array( 'state' => 'none' );
		}
		if ( is_array( $state['report'] ?? null ) ) {
			$state['report'] = ImportReport::from_array( $state['report'] )->to_array();
		}
		return $state;
	}

	/**
	 * The one entry for REST and CLI: enforces "apply only after a dry run" and records the state.
	 *
	 * @return ImportReport|\WP_Error
	 */
	public static function execute( string $mode, bool $overwrite = false ) {
		$state = self::state();
		if ( ImportReport::MODE_DRY_RUN === $mode ) {
			$report = self::dry_run( $overwrite );
			self::save_state( 'applied' === $state['state'] ? 'applied' : 'dry-run', $report, $state, true );
			Logs::yoast( 'found', $report->to_array()['totals'] );
			Logs::yoast( 'dry_run', $report->to_array()['totals'] );
			return $report;
		}
		if ( ImportReport::MODE_APPLY === $mode ) {
			if ( empty( $state['dry_run_at'] ) ) {
				return new \WP_Error( 'wpseokit_dry_run_first', __( 'Run the check first: nothing is written before you have seen the report.', 'wpseokit' ), array( 'status' => 409 ) );
			}
			$report = self::run( $overwrite );
			self::save_state( 'applied', $report, $state, false );
			Logs::yoast( 'run', $report->to_array()['totals'] );
			return $report;
		}
		if ( ImportReport::MODE_VERIFY === $mode ) {
			$report = self::verify( is_array( $state['baseline'] ?? null ) ? array_map( 'intval', $state['baseline'] ) : array() );
			$state  = array_merge( $state, array( 'verified_at' => time(), 'verified_ok' => (bool) ( $report->to_array()['extra']['ok'] ?? false ) ) );
			update_option( self::STATE_OPTION, $state, false );
			Logs::yoast( 'report', array_merge( $report->to_array()['totals'], array( 'ok' => (int) $state['verified_ok'] ) ) );
			return $report;
		}
		return new \WP_Error( 'wpseokit_bad_mode', __( 'Unknown import mode.', 'wpseokit' ), array( 'status' => 400 ) );
	}

	/**
	 * @param array<string, mixed> $old Previous state.
	 */
	private static function save_state( string $name, ImportReport $report, array $old, bool $is_dry_run ): void {
		$state = array(
			'state'      => $name,
			'time'       => $report->time(),
			'report'     => $report->to_array( false ), // Stored untranslated; state() translates it for the viewer.
			'baseline'   => is_array( $old['baseline'] ?? null ) ? $old['baseline'] : $report->source_counts(),
			'dry_run_at' => $is_dry_run ? $report->time() : (int) ( $old['dry_run_at'] ?? 0 ),
		);
		if ( ! $is_dry_run ) {
			$state['applied_at'] = $report->time();
		}
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/import/yoast',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_import' ),
				'permission_callback' => Guard::rest(),
				'args'                => array(
					'mode'      => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( ImportReport::MODE_DRY_RUN, ImportReport::MODE_APPLY, ImportReport::MODE_VERIFY ),
					),
					'overwrite' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/import/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_state' ),
				'permission_callback' => Guard::rest(),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_import( \WP_REST_Request $request ) {
		$report = self::execute( (string) $request['mode'], (bool) $request['overwrite'] );
		return $report instanceof ImportReport ? new \WP_REST_Response( $report->to_array() ) : $report;
	}

	public static function rest_state(): \WP_REST_Response {
		return new \WP_REST_Response( self::state() );
	}

	/**
	 * `wp wpseokit import-yoast --dry-run|--apply|--verify [--overwrite]`
	 *
	 * @param string[]              $args  Positional arguments (none).
	 * @param array<string, mixed>  $assoc Flags.
	 */
	public static function cli( array $args, array $assoc ): void {
		unset( $args );
		$mode = ImportReport::MODE_DRY_RUN;
		foreach ( array( ImportReport::MODE_APPLY, ImportReport::MODE_VERIFY ) as $candidate ) {
			if ( ! empty( $assoc[ $candidate ] ) ) {
				$mode = $candidate;
			}
		}
		$overwrite = ! empty( $assoc['overwrite'] );
		if ( ImportReport::MODE_APPLY === $mode && empty( self::state()['dry_run_at'] ) ) {
			\WP_CLI::log( 'No check yet: running the dry run first.' );
			self::print_report( self::execute( ImportReport::MODE_DRY_RUN, $overwrite ) );
		}
		self::print_report( self::execute( $mode, $overwrite ) );
	}

	/** @param ImportReport|\WP_Error $report Result of execute(). */
	private static function print_report( $report ): void {
		if ( $report instanceof \WP_Error ) {
			\WP_CLI::error( $report->get_error_message() );
			return;
		}
		$data = $report->to_array();
		\WP_CLI::log( sprintf( 'Mode: %s%s', $data['mode'], $data['overwrite'] ? ' (overwrite)' : '' ) );
		foreach ( $data['rows'] as $row ) {
			\WP_CLI::log( sprintf( '%-28s found %4d  import %4d  same %4d  empty %3d  invalid %3d  conflict %3d', $row['label'], $row['found'], $row['will_import'], $row['same'], $row['empty'], $row['invalid'], $row['conflicts'] ) );
		}
		foreach ( $data['not_migrated'] as $item ) {
			\WP_CLI::log( sprintf( 'Not migrated: %s = %d (%s)', $item['label'], $item['count'], $item['reason'] ) );
		}
		foreach ( $data['notes'] as $note ) {
			\WP_CLI::warning( $note );
		}
		\WP_CLI::success( sprintf( 'found %d, import %d, same %d, conflicts %d, balanced: %s', $data['totals']['found'], $data['totals']['will_import'], $data['totals']['same'], $data['totals']['conflicts'], $data['balanced'] ? 'yes' : 'NO' ) );
	}

	/* ------------------------------------------------------------------ planner */

	private static function process( string $mode, bool $overwrite ): ImportReport {
		$write  = ImportReport::MODE_APPLY === $mode;
		$report = new ImportReport( $mode, $overwrite );
		self::import_post_meta( $report, $write, $overwrite );
		self::import_primary_category( $report, $write, $overwrite );
		self::import_term_meta( $report, $write, $overwrite );
		self::import_settings( $report, $write, $overwrite );
		self::list_not_migrated( $report );
		$report->set_source_counts( self::source_counts() );
		return $report;
	}

	/**
	 * Decides one item: `write`, `same`, `empty` or `conflict`.
	 *
	 * @param mixed $current   What the target holds now.
	 * @param mixed $desired   What Yoast says (already converted).
	 * @param mixed $default   The value the target has when nobody set it (counts as empty).
	 */
	private static function decide( $current, $desired, $default, bool $overwrite ): string {
		if ( '' === $desired || 0 === $desired || array() === $desired || null === $desired ) {
			return 'empty';
		}
		if ( $current === $desired ) {
			return 'same';
		}
		if ( '' === $current || 0 === $current || array() === $current || null === $current || $current === $default ) {
			return 'write';
		}
		return $overwrite ? 'write' : 'conflict';
	}

	/** Puts a decision into the report row. */
	private static function tally( ImportReport $report, string $row, string $decision ): void {
		$report->count( $row, 'write' === $decision ? 'will_import' : ( 'conflict' === $decision ? 'conflicts' : $decision ) );
	}

	/** Yoast `%%var%%` -> `%var%`; warns about names WPSeoKit does not know. */
	private static function convert( ImportReport $report, string $row, string $where, string $value ): string {
		$out = Variables::from_yoast( $value );
		if ( $out !== $value ) {
			$report->converted( $row );
		}
		$unknown = Variables::unknown( $out );
		if ( array() !== $unknown ) {
			$report->note( sprintf( /* translators: 1: where, 2: variable list. */ __( '%1$s: unknown variable(s) %2$s (they render blank).', 'wpseokit' ), $where, '%' . implode( '%, %', $unknown ) . '%' ) );
		}
		if ( false !== strpos( $out, '%%' ) ) {
			$report->note( sprintf( /* translators: %s: where. */ __( '%s: a Yoast variable could not be converted.', 'wpseokit' ), $where ) );
		}
		return $out;
	}

	/* ---- post meta ---- */

	private static function import_post_meta( ImportReport $report, bool $write, bool $overwrite ): void {
		$report->row( 'metadesc', 'Meta descriptions' );
		$report->row( 'title', 'SEO titles' );
		$report->row( 'primary', 'Primary categories' );
		$report->row( 'robots', 'Post robots' );
		$report->row( 'canonical', 'Post canonical URLs' );
		$report->row( 'social', 'Post social fields' );

		$source_keys = array(
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_title',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
			'_yoast_wpseo_twitter-image',
		);
		$source      = self::post_meta_map( $source_keys, true );
		$targets     = self::post_meta_map(
			array( MetaService::META_TITLE, MetaService::META_DESCRIPTION, RobotsService::META_KEY, CanonicalService::META_KEY, SocialService::META_OG_TITLE, SocialService::META_OG_DESCRIPTION, self::POST_OG_IMAGE ),
			false
		);

		/* Plain one-to-one fields: row => [source key, target key, convert variables?]. */
		$plain = array(
			'metadesc'  => array( '_yoast_wpseo_metadesc', MetaService::META_DESCRIPTION, true ),
			'title'     => array( '_yoast_wpseo_title', MetaService::META_TITLE, true ),
			'canonical' => array( '_yoast_wpseo_canonical', CanonicalService::META_KEY, false ),
		);
		foreach ( $plain as $row => list( $from, $to, $vars ) ) {
			foreach ( $source as $post_id => $meta ) {
				if ( ! array_key_exists( $from, $meta ) ) {
					continue;
				}
				$value = trim( $meta[ $from ] ) === '' ? '' : $meta[ $from ];
				if ( $vars && '' !== $value ) {
					$value = self::convert( $report, $row, 'Post ' . $post_id, $value );
				}
				self::put_post_meta( $report, $row, $post_id, $to, $value, $targets[ $post_id ][ $to ] ?? '', $write, $overwrite );
			}
		}

		/* Robots: Yoast 1 = noindex, 2 = index; nofollow 1 = nofollow. */
		foreach ( $source as $post_id => $meta ) {
			if ( ! isset( $meta['_yoast_wpseo_meta-robots-noindex'] ) && ! isset( $meta['_yoast_wpseo_meta-robots-nofollow'] ) ) {
				continue;
			}
			$tokens = array();
			$noidx  = $meta['_yoast_wpseo_meta-robots-noindex'] ?? '';
			if ( '1' === $noidx ) {
				$tokens[] = 'noindex';
			} elseif ( '2' === $noidx ) {
				$tokens[] = 'index';
			}
			if ( '1' === ( $meta['_yoast_wpseo_meta-robots-nofollow'] ?? '' ) ) {
				$tokens[] = 'nofollow';
			}
			self::put_post_meta( $report, 'robots', $post_id, RobotsService::META_KEY, implode( ',', $tokens ), $targets[ $post_id ][ RobotsService::META_KEY ] ?? '', $write, $overwrite );
		}

		/* Social: the Twitter field fills in only when the Open Graph field is empty. */
		$social = array(
			SocialService::META_OG_TITLE       => array( '_yoast_wpseo_opengraph-title', '_yoast_wpseo_twitter-title' ),
			SocialService::META_OG_DESCRIPTION => array( '_yoast_wpseo_opengraph-description', '_yoast_wpseo_twitter-description' ),
		);
		foreach ( $source as $post_id => $meta ) {
			foreach ( $social as $to => list( $og, $tw ) ) {
				$value = trim( $meta[ $og ] ?? '' ) !== '' ? $meta[ $og ] : ( $meta[ $tw ] ?? '' );
				if ( ! array_key_exists( $og, $meta ) && ! array_key_exists( $tw, $meta ) ) {
					continue;
				}
				$value = trim( $value ) === '' ? '' : self::convert( $report, 'social', 'Post ' . $post_id, $value );
				self::put_post_meta( $report, 'social', $post_id, $to, $value, $targets[ $post_id ][ $to ] ?? '', $write, $overwrite );
			}
			if ( array_key_exists( '_yoast_wpseo_opengraph-image', $meta ) || array_key_exists( '_yoast_wpseo_opengraph-image-id', $meta ) || array_key_exists( '_yoast_wpseo_twitter-image', $meta ) ) {
				$image_id = (int) ( $meta['_yoast_wpseo_opengraph-image-id'] ?? 0 );
				$value    = $image_id > 0 ? (string) $image_id : trim( ( $meta['_yoast_wpseo_opengraph-image'] ?? '' ) !== '' ? $meta['_yoast_wpseo_opengraph-image'] : ( $meta['_yoast_wpseo_twitter-image'] ?? '' ) );
				self::put_post_meta( $report, 'social', $post_id, self::POST_OG_IMAGE, $value, $targets[ $post_id ][ self::POST_OG_IMAGE ] ?? '', $write, $overwrite );
			}
		}
		$report->note_row( 'title', 'Variables are converted (%%sitename%% becomes %sitename%).' );
		$report->note_row( 'social', 'Twitter fields fill in only where the Open Graph field is empty.' );
	}

	/** Decides and (when `$write`) stores one post meta value. */
	private static function put_post_meta( ImportReport $report, string $row, int $post_id, string $key, string $value, string $current, bool $write, bool $overwrite ): void {
		$decision = self::decide( $current, $value, '', $overwrite );
		self::tally( $report, $row, $decision );
		if ( $write && 'write' === $decision ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * Post meta read in one query. Posts that no longer exist (and revisions) are left out of the source side.
	 *
	 * @param string[] $keys Meta keys.
	 * @return array<int, array<string, string>> post_id => meta_key => value (last row wins).
	 */
	private static function post_meta_map( array $keys, bool $existing_posts_only ): array {
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$join = $existing_posts_only ? "INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type <> 'revision'" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the placeholders are generated above.
		$rows = \AdminKit\Helpers\Db::get_results( "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm {$join} WHERE pm.meta_key IN ({$in}) ORDER BY pm.post_id, pm.meta_id", $keys );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->post_id ][ (string) $row->meta_key ] = (string) $row->meta_value;
		}
		return $map;
	}

	/* ---- primary category ---- */

	private static function import_primary_category( ImportReport $report, bool $write, bool $overwrite ): void {
		global $wpdb;
		// Source 1: post meta. Source 2: Yoast's own table. When both exist and disagree the table wins (Yoast reads it).
		$from_meta = array();
		$empty     = 0;
		foreach ( self::post_meta_map( array( '_yoast_wpseo_primary_category' ), true ) as $post_id => $meta ) {
			$term_id = (int) ( $meta['_yoast_wpseo_primary_category'] ?? 0 );
			if ( $term_id > 0 ) {
				$from_meta[ $post_id ] = $term_id;
			} else {
				++$empty;
			}
		}
		$from_table = array();
		$table      = $wpdb->prefix . 'yoast_primary_term';
		if ( self::table_exists( $table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the core prefix.
			foreach ( (array) \AdminKit\Helpers\Db::get_results( "SELECT post_id, term_id FROM {$table} WHERE taxonomy = 'category' AND term_id > 0" ) as $row ) {
				$from_table[ (int) $row->post_id ] = (int) $row->term_id;
			}
		}
		$wanted = $from_table + $from_meta;
		ksort( $wanted );
		$disagree = 0;
		foreach ( $from_table as $post_id => $term_id ) {
			if ( isset( $from_meta[ $post_id ] ) && $from_meta[ $post_id ] !== $term_id ) {
				++$disagree;
			}
		}
		if ( $disagree > 0 ) {
			$report->note( sprintf( /* translators: %d: number of posts. */ __( 'Primary category: %d post(s) differ between the post meta and the Yoast table; the table value is used.', 'wpseokit' ), $disagree ) );
		}

		$assigned = array();
		if ( array() !== $wanted ) {
			$ids = implode( ',', array_map( 'intval', array_keys( $wanted ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			foreach ( (array) \AdminKit\Helpers\Db::get_results( "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'category' AND tr.object_id IN ({$ids})" ) as $row ) {
				$assigned[ (int) $row->object_id ][ (int) $row->term_id ] = true;
			}
		}
		$current = self::post_meta_map( array( PrimaryTermService::META_KEY ), false );
		foreach ( $wanted as $post_id => $term_id ) {
			if ( empty( $assigned[ $post_id ][ $term_id ] ) ) {
				$report->count( 'primary', 'invalid' );
				$report->note( sprintf( /* translators: 1: post ID, 2: term ID. */ __( 'Primary category of post %1$d (term %2$d) is not assigned to the post; skipped.', 'wpseokit' ), $post_id, $term_id ) );
				continue;
			}
			$decision = self::decide( (int) ( $current[ $post_id ][ PrimaryTermService::META_KEY ] ?? 0 ), $term_id, 0, $overwrite );
			self::tally( $report, 'primary', $decision );
			if ( $write && 'write' === $decision ) {
				PrimaryTermService::set( $post_id, $term_id );
			}
		}
		$report->note_row( 'primary', sprintf( /* translators: %d: number of empty rows. */ __( 'Posts keep their URLs. %d empty Yoast rows are ignored (WordPress default applies).', 'wpseokit' ), $empty ) );
	}

	/* ---- term meta ---- */

	private static function import_term_meta( ImportReport $report, bool $write, bool $overwrite ): void {
		$report->row( 'terms', 'Category descriptions' );
		$report->row( 'terms_extra', 'Term titles, robots and images' );
		$option = get_option( 'wpseo_taxonomy_meta', array() );
		$not    = array( 'wpseo_linkdex' => 0, 'wpseo_content_score' => 0 );

		foreach ( is_array( $option ) ? $option : array() as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) || 'wpseo_' === substr( (string) $taxonomy, 0, 6 ) ) {
				continue;
			}
			foreach ( $terms as $term_id => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( array_keys( $not ) as $k ) {
					if ( isset( $data[ $k ] ) && '' !== (string) $data[ $k ] ) {
						++$not[ $k ];
					}
				}
				$term_id = (int) $term_id;
				$exists  = $term_id > 0 && get_term( $term_id, (string) $taxonomy ) instanceof \WP_Term;
				$items   = array(
					'terms'       => array( MetaService::TERM_DESCRIPTION => $data['wpseo_desc'] ?? null ),
					'terms_extra' => array(
						MetaService::TERM_TITLE    => $data['wpseo_title'] ?? null,
						RobotsService::TERM_KEY    => in_array( $data['wpseo_noindex'] ?? '', array( 'noindex', 'index' ), true ) ? $data['wpseo_noindex'] : null,
						SocialService::TERM_OG_IMAGE => $data['wpseo_opengraph-image'] ?? null,
					),
				);
				foreach ( $items as $row => $pairs ) {
					foreach ( $pairs as $meta_key => $raw ) {
						if ( null === $raw || ( 'terms_extra' === $row && '' === trim( (string) $raw ) ) ) {
							continue;
						}
						$value = trim( (string) $raw ) === '' ? '' : (string) $raw;
						if ( $exists && '' !== $value && in_array( $meta_key, array( MetaService::TERM_TITLE, MetaService::TERM_DESCRIPTION ), true ) ) {
							$value = self::convert( $report, $row, 'Term ' . $term_id, $value );
						}
						if ( ! $exists ) {
							$report->count( $row, 'invalid' );
							continue;
						}
						$decision = self::decide( (string) get_term_meta( $term_id, $meta_key, true ), $value, '', $overwrite );
						self::tally( $report, $row, $decision );
						if ( $write && 'write' === $decision ) {
							update_term_meta( $term_id, $meta_key, wp_slash( $value ) );
						}
					}
				}
			}
		}
		$report->note_row( 'terms', 'Stored in term meta, not in one serialized option.' );
		foreach ( $not as $k => $count ) {
			$report->not_migrated( $k, $count, 'Analysis result, no feature here.' );
		}
	}

	/* ---- settings ---- */

	/**
	 * Yoast option key => target path, per report row. `type`: tpl (variable text), text, url, bool, int, enum,
	 * sep (separator), list:<path> (membership in a list setting).
	 *
	 * @return array<string, array<int, array{0: string, 1: string, 2: string, 3: string}>> row => [ option, key, path, type ].
	 */
	private static function settings_map(): array {
		$map = array(
			'cfg_templates' => array(
				array( 'wpseo_titles', 'separator', 'templates.sep', 'sep' ),
				array( 'wpseo_titles', 'title-home-wpseo', 'templates.home_title', 'tpl' ),
				array( 'wpseo_titles', 'metadesc-home-wpseo', 'templates.home_description', 'tpl' ),
				array( 'wpseo_titles', 'title-search-wpseo', 'templates.search_title', 'tpl' ),
				array( 'wpseo_titles', 'title-404-wpseo', 'templates.notfound_title', 'tpl' ),
				array( 'wpseo_titles', 'title-archive-wpseo', 'templates.date_title', 'tpl' ),
				array( 'wpseo_titles', 'title-author-wpseo', 'templates.author_title', 'tpl' ),
			),
			'cfg_robots'    => array(
				array( 'wpseo_titles', 'noindex-archive-wpseo', 'robots.noindex_date', 'bool' ),
				array( 'wpseo_titles', 'noindex-author-noposts-wpseo', 'robots.noindex_author_noposts', 'bool' ),
			),
			'cfg_general'   => array(
				array( 'wpseo_titles', 'disable-author', 'general.disable_author_archive', 'bool' ),
				array( 'wpseo_titles', 'disable-attachment', 'general.disable_attachment_pages', 'bool' ),
				array( 'wpseo', 'googleverify', 'general.verify_google', 'text' ),
				array( 'wpseo', 'msverify', 'general.verify_bing', 'text' ),
				array( 'wpseo', 'yandexverify', 'general.verify_yandex', 'text' ),
				array( 'wpseo', 'baiduverify', 'general.verify_baidu', 'text' ),
				array( 'wpseo', 'enable_llms_txt', 'llms.enabled', 'bool' ),
				array( 'wpseo', 'enable_xml_sitemap', 'sitemap.enabled', 'bool' ),
			),
			'cfg_schema'    => array(
				array( 'wpseo_titles', 'company_or_person', 'schema.site_represents', 'enum' ),
				array( 'wpseo_titles', 'company_name', 'schema.organization_name', 'text' ),
				array( 'wpseo_titles', 'company_logo', 'schema.organization_logo_url', 'url' ),
				array( 'wpseo_titles', 'company_logo_id', 'schema.organization_logo_id', 'int' ),
				array( 'wpseo_titles', 'website_name', 'schema.website_name', 'text' ),
				array( 'wpseo_titles', 'alternate_website_name', 'schema.alternate_website_name', 'text' ),
				array( 'wpseo_titles', 'person_name', 'schema.person_name', 'text' ),
			),
			'cfg_social'    => array(
				array( 'wpseo_social', 'opengraph', 'social.opengraph', 'bool' ),
				array( 'wpseo_social', 'twitter', 'social.twitter', 'bool' ),
				array( 'wpseo_social', 'twitter_card_type', 'social.twitter_card_type', 'enum' ),
				array( 'wpseo_social', 'facebook_site', 'social.facebook_site', 'url' ),
				array( 'wpseo_social', 'twitter_site', 'social.twitter_site', 'text' ),
				array( 'wpseo_social', 'og_default_image', 'social.default_image_url', 'url' ),
				array( 'wpseo_social', 'og_default_image_id', 'social.default_image_id', 'int' ),
				array( 'wpseo_social', 'instagram_url', 'social.profiles.instagram', 'url' ),
				array( 'wpseo_social', 'linkedin_url', 'social.profiles.linkedin', 'url' ),
				array( 'wpseo_social', 'youtube_url', 'social.profiles.youtube', 'url' ),
				array( 'wpseo_social', 'pinterest_url', 'social.profiles.pinterest', 'url' ),
				array( 'wpseo_social', 'wikipedia_url', 'social.profiles.wikipedia', 'url' ),
			),
		);
		/* One template pair per public post type and per taxonomy that Yoast has a key for. */
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			$map['cfg_templates'][] = array( 'wpseo_titles', 'title-' . $post_type, 'templates.post_types.' . $post_type . '.title', 'tpl' );
			$map['cfg_templates'][] = array( 'wpseo_titles', 'metadesc-' . $post_type, 'templates.post_types.' . $post_type . '.description', 'tpl' );
			$map['cfg_robots'][]    = array( 'wpseo_titles', 'noindex-' . $post_type, 'robots.noindex_post_types', 'list:' . $post_type );
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			$map['cfg_templates'][] = array( 'wpseo_titles', 'title-tax-' . $taxonomy, 'templates.taxonomies.' . $taxonomy . '.title', 'tpl' );
			$map['cfg_templates'][] = array( 'wpseo_titles', 'metadesc-tax-' . $taxonomy, 'templates.taxonomies.' . $taxonomy . '.description', 'tpl' );
			$map['cfg_robots'][]    = array( 'wpseo_titles', 'noindex-tax-' . $taxonomy, 'robots.noindex_taxonomies', 'list:' . $taxonomy );
		}
		return $map;
	}

	private static function import_settings( ImportReport $report, bool $write, bool $overwrite ): void {
		$labels  = array(
			'cfg_templates' => 'Title and description templates',
			'cfg_robots'    => 'Robots rules',
			'cfg_general'   => 'General and verification',
			'cfg_schema'    => 'Schema (organization)',
			'cfg_social'    => 'Social settings',
		);
		$options = array();
		foreach ( self::YOAST_OPTIONS as $name ) {
			$value             = get_option( $name, array() );
			$options[ $name ] = is_array( $value ) ? $value : array();
		}
		$current = SettingsRepository::all();
		$default = Settings::defaults();
		$partial = array();

		foreach ( self::settings_map() as $row => $items ) {
			$report->row( $row, $labels[ $row ] );
			foreach ( $items as list( $option, $key, $path, $type ) ) {
				if ( ! array_key_exists( $key, $options[ $option ] ) ) {
					continue; // Yoast has no such key: nothing was read.
				}
				$raw   = $options[ $option ][ $key ];
				$where = $option . '.' . $key;
				if ( 0 === strpos( $type, 'list:' ) ) {
					$slug    = substr( $type, 5 );
					$list    = (array) self::path_get( $current, $path, array() );
					$in_list = in_array( $slug, $list, true );
					$want_in = (bool) $raw;
					$state   = $want_in === $in_list ? 'same' : ( $want_in ? 'write' : ( $overwrite ? 'write' : 'conflict' ) );
					self::tally( $report, $row, $state );
					if ( 'write' === $state ) {
						$base = (array) self::path_get( $partial, $path, $list );
						$base = $want_in ? array_merge( $base, array( $slug ) ) : array_values( array_diff( $base, array( $slug ) ) );
						self::path_set( $partial, $path, array_values( array_unique( $base ) ) );
					}
					continue;
				}
				$desired = self::typed( $report, $row, $where, $type, $raw );
				if ( null === $desired ) {
					$report->count( $row, 'invalid' );
					continue;
				}
				// Compare in the form the settings store keeps (length limits, URL cleaning).
				$probe    = array();
				self::path_set( $probe, $path, $desired );
				$clean    = self::path_get( SettingsRepository::sanitize( self::with_maps( $probe, $current, $path ) ), $path, $desired );
				$existing = self::path_get( $current, $path, null );
				$state    = self::decide( $existing, $clean, self::path_get( $default, $path, '' ), $overwrite );
				self::tally( $report, $row, $state );
				if ( 'write' === $state ) {
					self::path_set( $partial, $path, $clean );
				}
			}
		}
		if ( $write && array() !== $partial ) {
			SettingsRepository::update( self::with_maps( $partial, $current, '' ) );
		}
		$report->note_row( 'cfg_templates', 'Variables are converted; the separator code (sc-dash) becomes the character.' );
	}

	/**
	 * Converts a Yoast option value to the typed value the settings store expects. Null = cannot be imported.
	 *
	 * @param mixed $raw Yoast value.
	 * @return mixed
	 */
	private static function typed( ImportReport $report, string $row, string $where, string $type, $raw ) {
		switch ( $type ) {
			case 'bool':
				return (bool) filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
			case 'int':
				return max( 0, (int) $raw );
			case 'sep':
				if ( '' === (string) $raw ) {
					return '';
				}
				if ( ! isset( self::SEPARATORS[ (string) $raw ] ) ) {
					$report->note( sprintf( /* translators: 1: where, 2: separator code. */ __( '%1$s: separator "%2$s" is unknown; skipped.', 'wpseokit' ), $where, (string) $raw ) );
					return null;
				}
				return self::SEPARATORS[ (string) $raw ];
			case 'tpl':
				return is_scalar( $raw ) && '' !== trim( (string) $raw ) ? self::convert( $report, $row, $where, (string) $raw ) : '';
			case 'enum':
				if ( 'company_or_person' === substr( $where, -17 ) ) {
					return array( 'company' => 'organization', 'person' => 'person' )[ (string) $raw ] ?? '';
				}
				return is_scalar( $raw ) ? (string) $raw : '';
			default: // text, url
				return is_scalar( $raw ) ? trim( (string) $raw ) : '';
		}
	}

	/**
	 * Map settings (post type / taxonomy templates, profiles) and lists are replaced as a whole by the store, so the
	 * current map is carried into the partial.
	 *
	 * @param array<string, mixed> $partial Partial settings.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>
	 */
	private static function with_maps( array $partial, array $current, string $path ): array {
		foreach ( array( 'templates.post_types', 'templates.taxonomies', 'social.profiles' ) as $map_path ) {
			if ( null !== self::path_get( $partial, $map_path, null ) ) {
				self::path_set( $partial, $map_path, self::array_merge_recursive_distinct( (array) self::path_get( $current, $map_path, array() ), (array) self::path_get( $partial, $map_path, array() ) ) );
			}
		}
		unset( $path );
		return $partial;
	}

	/**
	 * @param array<string, mixed> $base Base.
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private static function array_merge_recursive_distinct( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			$base[ $key ] = ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) ? self::array_merge_recursive_distinct( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	/**
	 * @param array<string, mixed> $data    Nested array.
	 * @param mixed                $default Returned when the path is missing.
	 * @return mixed
	 */
	private static function path_get( array $data, string $path, $default ) {
		return \AdminKit\Core\Settings::value_at( $data, $path, $default );
	}

	/**
	 * @param array<string, mixed> $data  Nested array (changed).
	 * @param mixed                $value Value.
	 */
	private static function path_set( array &$data, string $path, $value ): void {
		$ref = &$data;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! isset( $ref[ $part ] ) || ! is_array( $ref[ $part ] ) ) {
				$ref[ $part ] = array();
			}
			$ref = &$ref[ $part ];
		}
		$ref = $value;
	}

	/* ---- what is not migrated, and the source snapshot ---- */

	private static function list_not_migrated( ImportReport $report ): void {
		global $wpdb;
		$reasons = array(
			'yoast_indexable'           => 'Yoast cache; its content is imported from the sources above.',
			'yoast_indexable_hierarchy' => 'Yoast cache.',
			'yoast_seo_links'           => 'Internal link counter, no feature here.',
			'yoast_migrations'          => 'Yoast schema history.',
			'yoast_expiring_store'      => 'Temporary store.',
			'yoast_primary_term'        => 'Read as the second source of the primary category.',
		);
		foreach ( self::YOAST_TABLES as $name ) {
			$table = $wpdb->prefix . $name;
			if ( self::table_exists( $table ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the core prefix.
				$report->not_migrated( $name, (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$table}" ), $reasons[ $name ] );
			}
		}
		foreach ( self::UNMIGRATED_META as $key => $reason ) {
			$report->not_migrated( $key, (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", array( $key ) ), $reason );
		}
		$wpseo = get_option( 'wpseo', array() );
		foreach ( array( 'category_base_url', 'tag_base_url' ) as $key ) {
			if ( is_array( $wpseo ) && ! empty( $wpseo[ $key ] ) ) {
				$report->not_migrated( 'wpseo.' . $key, 1, 'WordPress handles this in Settings > Permalinks.' );
			}
		}
		$titles = get_option( 'wpseo_titles', array() );
		if ( is_array( $titles ) && ! empty( $titles['disable-date'] ) ) {
			$report->not_migrated( 'wpseo_titles.disable-date', 1, 'No matching setting here.' );
		}
		$report->not_migrated( 'wpseo (secrets and tokens)', 0, 'Never copied: tokens and keys stay out.' );
	}

	/**
	 * Row counts of every Yoast source. Used as proof that the import did not touch Yoast data.
	 *
	 * @return array<string, int>
	 */
	public static function source_counts(): array {
		global $wpdb;
		$counts = array();
		foreach ( self::YOAST_TABLES as $name ) {
			$table = $wpdb->prefix . $name;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the core prefix.
			$counts[ $name ] = self::table_exists( $table ) ? (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$table}" ) : -1;
		}
		$counts['postmeta_yoast_rows'] = (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%'" );
		$counts['termmeta_yoast_rows'] = (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key LIKE 'wpseo\\_%'" );
		foreach ( array( '_yoast_wpseo_metadesc', '_yoast_wpseo_title', '_yoast_wpseo_primary_category' ) as $key ) {
			$counts[ 'postmeta' . $key ] = (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", array( $key ) );
		}
		// A checksum of each Yoast option shows that not one value changed.
		foreach ( self::YOAST_OPTIONS as $name ) {
			$counts[ 'option_' . $name ] = (int) crc32( maybe_serialize( get_option( $name, array() ) ) );
		}
		return $counts;
	}

	/**
	 * @param array<string, int> $a Counts.
	 * @param array<string, int> $b Counts.
	 */
	private static function same_counts( array $a, array $b ): bool {
		ksort( $a );
		ksort( $b );
		return $a === $b;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === \AdminKit\Helpers\Db::get_var( 'SHOW TABLES LIKE %s', array( $wpdb->esc_like( $table ) ) );
	}
}
