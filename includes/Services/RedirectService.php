<?php
/**
 * Redirects: 301, 302, 307 and 410 by exact path. Answers on `template_redirect` from a small in-memory map, so a
 * normal page view runs no SQL for this feature. Rules are checked when they are saved: a rule may not loop, may not
 * start a chain (one hop only), may not send an HTTPS site to an HTTP address and may not use a reserved path.
 * No `.htaccess` is written. Contract: docs/sozlesme.md §2.1 and §4.5, behaviour: docs/kapsam-ve-mimari.md §3.3 and §3.9.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Models\Redirect;
use Wpseokit\Repositories\Log404Repository;
use Wpseokit\Repositories\RedirectRepository;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class RedirectService {

	/** Option that holds the map of active rules (`wpseokit_redirect_map`). */
	public const MAP_OPTION = 'wpseokit_redirect_map';

	/** With more active rules than this the map is not kept in an autoloaded option; each request asks the table by key. */
	private const MAP_LIMIT = 300;

	/** Longest source path in characters (the column is `varchar(191)`). */
	private const SOURCE_MAX = 191;

	/** Longest target in bytes. */
	private const TARGET_MAX = 2000;

	/** Paths that belong to WordPress itself; a rule on them could lock the owner out. */
	private const RESERVED = array( '/wp-admin', '/wp-json', '/wp-login.php' );

	/** @var array{n: int, rules: array<string, array{id: int, target: string, code: int}>|null}|null */
	private static ?array $map = null;

	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
	}

	/* ------------------------------------------------------------------ paths */

	/** Path of the site address (`/blog` for `https://example.com/blog`), or an empty string. */
	private static function home_path(): string {
		return rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	}

	private static function home_host(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	private static function home_is_https(): bool {
		return 0 === strpos( home_url(), 'https://' );
	}

	/** Cuts the site folder off a path (`/blog/a/` → `/a/`). */
	private static function strip_home( string $path ): string {
		$home = self::home_path();
		if ( '' === $home || 0 !== strncasecmp( $path, $home, strlen( $home ) ) ) {
			return $path;
		}
		$rest = substr( $path, strlen( $home ) );
		return '' === $rest ? '/' : ( '/' === $rest[0] ? $rest : $path );
	}

	/**
	 * The stored form of a source path: decoded, one leading slash, no query or fragment, no double slashes, a closing
	 * slash unless the last part is a file name. A full address of this site is accepted and cut to its path.
	 * Returns an empty string for anything that is not a plain path of this site.
	 */
	public static function store_path( string $input ): string {
		$input = trim( $input );
		if ( '' === $input || strlen( $input ) > self::TARGET_MAX ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $input ) ) {
			$parts = wp_parse_url( $input );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( (string) $parts['host'] ) !== self::home_host() ) {
				return '';
			}
			$input = self::strip_home( (string) ( $parts['path'] ?? '/' ) );
		} else {
			$input = (string) preg_replace( '/[?#].*$/s', '', $input );
		}
		if ( '' === $input || '/' !== $input[0] ) {
			return '';
		}
		$path = rawurldecode( $input );
		if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $path ) || ! mb_check_encoding( $path, 'UTF-8' ) ) {
			return '';
		}
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return '';
			}
		}
		$last = (string) substr( $path, (int) strrpos( $path, '/' ) + 1 );
		if ( '' !== $last && false === strpos( $last, '.' ) ) {
			$path .= '/';
		}
		return mb_strlen( $path ) > self::SOURCE_MAX ? '' : $path;
	}

	/** Lookup key of a stored path: paths match without regard to case. */
	public static function key( string $path ): string {
		return md5( mb_strtolower( $path ) );
	}

	/** Stored path of the address being served (`REQUEST_URI` without query), or an empty string. */
	public static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to a plain path by store_path().
		$path = (string) strtok( $uri, '?#' );
		return self::store_path( self::strip_home( $path ) );
	}

	/** Stored path of any address of this site (used by the sitemap to drop redirected posts). */
	public static function path_of_url( string $url ): string {
		return self::store_path( $url );
	}

	/* ------------------------------------------------------------------ map */

	/** @return array{n: int, rules: array<string, array{id: int, target: string, code: int}>|null} */
	private static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}
		$map = get_option( self::MAP_OPTION );
		if ( ! is_array( $map ) || ! isset( $map['n'] ) ) {
			self::rebuild();
			$map = self::$map;
		}
		self::$map = $map;
		return self::$map;
	}

	/** Rebuilds the map of active rules and clears what depends on it (sitemap lists). Call after any change. */
	public static function rebuild(): void {
		$rules = RedirectRepository::all_active();
		$n     = count( $rules );
		$rows  = null;
		if ( $n <= self::MAP_LIMIT ) {
			$rows = array();
			foreach ( $rules as $rule ) {
				$rows[ self::key( $rule->source_path ) ] = array(
					'id'     => $rule->id,
					'target' => $rule->target_url,
					'code'   => $rule->status_code,
				);
			}
		}
		self::$map = array(
			'n'     => $n,
			'rules' => $rows,
		);
		update_option( self::MAP_OPTION, self::$map, null !== $rows );
		SitemapService::flush();
	}

	/**
	 * The active rule for a stored path.
	 *
	 * @return array{id: int, target: string, code: int}|null
	 */
	public static function lookup( string $path ): ?array {
		$map = self::map();
		if ( 0 === (int) $map['n'] || '' === $path ) {
			return null;
		}
		if ( is_array( $map['rules'] ) ) {
			return $map['rules'][ self::key( $path ) ] ?? null;
		}
		$rule = RedirectRepository::find_by_source( $path );
		return ( $rule && $rule->is_active ) ? array(
			'id'     => $rule->id,
			'target' => $rule->target_url,
			'code'   => $rule->status_code,
		) : null;
	}

	/** Whether an address of this site is answered by an active redirect (the sitemap must not list it). */
	public static function is_redirected_url( string $url ): bool {
		$path = self::path_of_url( $url );
		return '' !== $path && null !== self::lookup( $path );
	}

	/* ------------------------------------------------------------------ front end */

	/** `template_redirect` (priority 1): answers a matching address with its redirect and stops. */
	public static function handle(): void {
		if ( ! SettingsRepository::get( 'redirects.enabled', true ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		$path = self::request_path();
		$hit  = '' === $path ? null : self::lookup( $path );
		if ( null === $hit ) {
			return;
		}
		RedirectRepository::touch_hit( $hit['id'] );
		if ( 410 === $hit['code'] ) {
			wp_die( esc_html__( 'This page has been removed for good.', 'wpseokit' ), esc_html__( 'Gone', 'wpseokit' ), array( 'response' => 410 ) );
		}
		$url = self::absolute( $hit['target'] );
		// Never answer with a redirect to the address that is being served.
		if ( '/' === $hit['target'][0] && self::store_path( (string) strtok( $hit['target'], '?#' ) ) === $path ) {
			return;
		}
		wp_redirect( $url, $hit['code'], 'WPSeoKit' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- targets are checked when a rule is saved; external addresses are allowed on purpose.
		exit;
	}

	/** Full address of a stored target (`/new/` becomes `https://example.com/new/`). */
	private static function absolute( string $target ): string {
		return '/' === $target[0] ? home_url( self::strip_home( $target ) ) : $target;
	}

	/* ------------------------------------------------------------------ rules */

	/**
	 * Checks and cleans one rule. Needs `source_path`; `target_url`, `status_code` and `is_active` have defaults.
	 *
	 * @param array<string, mixed> $in        Rule fields.
	 * @param int                  $ignore_id Rule being edited (0 for a new one); it does not conflict with itself.
	 * @return array{source_path: string, target_url: string, status_code: int, match_type: string, is_active: bool}|\WP_Error
	 */
	public static function validate( array $in, int $ignore_id = 0 ) {
		$code = (int) ( $in['status_code'] ?? 301 );
		if ( ! in_array( $code, Redirect::CODES, true ) ) {
			return self::error( 'bad_status', __( 'Use 301, 302, 307 or 410.', 'wpseokit' ), 400 );
		}
		if ( Redirect::MATCH_EXACT !== (string) ( $in['match_type'] ?? Redirect::MATCH_EXACT ) ) {
			return self::error( 'bad_match', __( 'Only exact paths are supported.', 'wpseokit' ), 400 );
		}
		$source = self::store_path( (string) ( $in['source_path'] ?? '' ) );
		if ( '' === $source || '/' === $source ) {
			return self::error( 'bad_source', __( 'The old address must be a path on this site, such as /old-page/.', 'wpseokit' ), 400 );
		}
		foreach ( self::RESERVED as $reserved ) {
			if ( 0 === strncasecmp( $source, $reserved, strlen( $reserved ) ) ) {
				return self::error( 'reserved_source', __( 'WordPress needs this path; it cannot be redirected.', 'wpseokit' ), 400 );
			}
		}
		$active = ! array_key_exists( 'is_active', $in ) || filter_var( $in['is_active'], FILTER_VALIDATE_BOOLEAN );
		$target = '';
		if ( 410 !== $code ) {
			$target = self::clean_target( (string) ( $in['target_url'] ?? '' ) );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
		}
		$existing = RedirectRepository::find_by_source( $source );
		if ( $existing && $existing->id !== $ignore_id ) {
			/* translators: %s: path */
			return self::error( 'conflict', sprintf( __( 'A redirect for %s already exists.', 'wpseokit' ), $source ), 409 );
		}
		if ( $active && '' !== $target ) {
			$problem = self::check_chain( $source, $target, $ignore_id );
			if ( $problem ) {
				return $problem;
			}
		}
		return array(
			'source_path' => $source,
			'target_url'  => $target,
			'status_code' => $code,
			'match_type'  => Redirect::MATCH_EXACT,
			'is_active'   => $active,
		);
	}

	/**
	 * Target in stored form: a path of this site (`/new/`, query allowed) or a full http(s) address. The own host is
	 * cut to a path. An HTTPS site never redirects to an HTTP address.
	 *
	 * @return string|\WP_Error
	 */
	private static function clean_target( string $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return self::error( 'bad_target', __( 'Enter the new address, or choose 410 if the page is gone.', 'wpseokit' ), 400 );
		}
		if ( strlen( $raw ) > self::TARGET_MAX || preg_match( '/[\x00-\x20\x7f\\\\]/', $raw ) ) {
			return self::error( 'bad_target', __( 'The new address is not valid. Encode spaces and special characters.', 'wpseokit' ), 400 );
		}
		if ( '/' === $raw[0] && ( 1 === strlen( $raw ) || '/' !== $raw[1] ) ) {
			return $raw;
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return self::error( 'bad_target', __( 'The new address must be a path on this site or a full http(s) address.', 'wpseokit' ), 400 );
		}
		if ( 'http' === strtolower( (string) $parts['scheme'] ) && self::home_is_https() && strtolower( (string) $parts['host'] ) !== self::home_host() ) {
			return self::error( 'https_downgrade', __( 'This site uses HTTPS. Do not redirect to an HTTP address.', 'wpseokit' ), 422 );
		}
		if ( strtolower( (string) $parts['host'] ) === self::home_host() ) {
			$path = self::strip_home( (string) ( $parts['path'] ?? '/' ) );
			return $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		}
		return $raw;
	}

	/**
	 * One hop only: the new address may not itself be redirected (loop or chain), and no rule may already end on the old
	 * address. Existing rules obey the same limit, so one lookup each way is enough.
	 */
	private static function check_chain( string $source, string $target, int $ignore_id ): ?\WP_Error {
		if ( '/' === $target[0] ) {
			$tpath = self::store_path( (string) strtok( $target, '?#' ) );
			if ( '' !== $tpath ) {
				if ( 0 === strcasecmp( $tpath, $source ) ) {
					return self::error( 'loop', __( 'The old and the new address are the same page. That is a loop.', 'wpseokit' ), 422 );
				}
				$next = RedirectRepository::find_by_source( $tpath );
				if ( $next && $next->is_active && $next->id !== $ignore_id ) {
					$back = self::store_path( (string) strtok( $next->target_url, '?#' ) );
					if ( '' !== $back && 0 === strcasecmp( $back, $source ) ) {
						/* translators: 1: new address, 2: old address */
						return self::error( 'loop', sprintf( __( '%1$s already redirects back to %2$s. That is a loop.', 'wpseokit' ), $tpath, $source ), 422 );
					}
					/* translators: %s: path */
					return self::error( 'chain', sprintf( __( '%s is itself redirected. Point to its final address so visitors make one hop only.', 'wpseokit' ), $tpath ), 422 );
				}
			}
		}
		$before = RedirectRepository::find_by_target( $source, $ignore_id );
		if ( $before ) {
			/* translators: 1: path, 2: path */
			return self::error( 'chain', sprintf( __( '%1$s already redirects to %2$s. Change that rule to the final address first, so visitors make one hop only.', 'wpseokit' ), $before->source_path, $source ), 422 );
		}
		return null;
	}

	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( 'wpseokit_' . $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Creates a rule (and drops the 404 log row of the same address).
	 *
	 * @param array<string, mixed> $in Rule fields (see validate()).
	 * @return Redirect|\WP_Error
	 */
	public static function create( array $in, bool $rebuild = true ) {
		$clean = self::validate( $in );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$id = RedirectRepository::insert( $clean );
		if ( 0 === $id ) {
			return self::error( 'db', __( 'The redirect could not be saved.', 'wpseokit' ), 500 );
		}
		Log404Repository::delete_by_path( $clean['source_path'] );
		EventLogService::record( 'redirects', 'redirect_added', $clean['source_path'], array( 'tool' => 'redirects' ) );
		if ( $rebuild ) {
			self::rebuild();
		}
		return RedirectRepository::find( $id ); // @phpstan-ignore-line -- the row exists.
	}

	/**
	 * Changes some fields of a rule.
	 *
	 * @param array<string, mixed> $in Fields to change.
	 * @return Redirect|\WP_Error
	 */
	public static function update( int $id, array $in ) {
		$old = RedirectRepository::find( $id );
		if ( ! $old ) {
			return self::error( 'not_found', __( 'Redirect not found.', 'wpseokit' ), 404 );
		}
		$clean = self::validate( array_merge( $old->to_array(), $in ), $id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		RedirectRepository::update( $id, $clean );
		EventLogService::record( 'redirects', 'redirect_updated', $clean['source_path'], array( 'tool' => 'redirects' ) );
		self::rebuild();
		return RedirectRepository::find( $id );
	}

	public static function delete( int $id ): bool {
		$old = RedirectRepository::find( $id );
		$ok  = RedirectRepository::delete( $id );
		if ( $ok ) {
			EventLogService::record( 'redirects', 'redirect_deleted', $old ? $old->source_path : '', array( 'tool' => 'redirects' ) );
			self::rebuild();
		}
		return $ok;
	}

	/* ------------------------------------------------------------------ REST */

	public static function register_routes( string $namespace ): void {
		$id = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
		$fields = array(
			'source_path' => array( 'type' => 'string' ),
			'target_url'  => array( 'type' => 'string' ),
			'status_code' => array( 'type' => 'integer' ),
			'match_type'  => array(
				'type' => 'string',
				'enum' => array( Redirect::MATCH_EXACT ),
			),
			'is_active'   => array( 'type' => 'boolean' ),
		);
		register_rest_route(
			$namespace,
			'/redirects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_list' ),
					'permission_callback' => Rest::permission(),
					'args'                => array(
						'search'      => array( 'type' => 'string' ),
						'status_code' => array( 'type' => 'integer' ),
						'is_active'   => array( 'type' => 'string' ),
						'orderby'     => array( 'type' => 'string' ),
						'order'       => array( 'type' => 'string' ),
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_create' ),
					'permission_callback' => Rest::permission(),
					'args'                => array_merge( $fields, array( 'source_path' => array( 'type' => 'string', 'required' => true ) ) ),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_get' ),
					'permission_callback' => Rest::permission(),
					'args'                => array( 'id' => $id ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'rest_update' ),
					'permission_callback' => Rest::permission(),
					'args'                => array_merge( $fields, array( 'id' => $id ) ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'rest_delete' ),
					'permission_callback' => Rest::permission(),
					'args'                => array( 'id' => $id ),
				),
			)
		);
	}

	public static function rest_list( \WP_REST_Request $request ): \WP_REST_Response {
		$result = RedirectRepository::query( $request->get_params() );
		$per    = max( 1, min( 100, (int) ( $request['per_page'] ?? 20 ) ) );
		$data   = array_map( static fn( Redirect $r ): array => $r->to_array(), $result['items'] );
		$resp   = new \WP_REST_Response( $data );
		$resp->header( 'X-WP-Total', (string) $result['total'] );
		$resp->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per ) ) );
		return $resp;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_get( \WP_REST_Request $request ) {
		$rule = RedirectRepository::find( (int) $request['id'] );
		return $rule ? new \WP_REST_Response( $rule->to_array() ) : self::error( 'not_found', __( 'Redirect not found.', 'wpseokit' ), 404 );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_create( \WP_REST_Request $request ) {
		$rule = self::create( self::body( $request ) );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$resp = new \WP_REST_Response( $rule->to_array(), 201 );
		return $resp;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_update( \WP_REST_Request $request ) {
		$rule = self::update( (int) $request['id'], self::body( $request ) );
		return is_wp_error( $rule ) ? $rule : new \WP_REST_Response( $rule->to_array() );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_delete( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return self::delete( $id ) ? new \WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		) : self::error( 'not_found', __( 'Redirect not found.', 'wpseokit' ), 404 );
	}

	/**
	 * Rule fields from the request body (JSON or form), nothing else.
	 *
	 * @return array<string, mixed>
	 */
	private static function body( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		$all  = is_array( $json ) ? $json : $request->get_body_params();
		return array_intersect_key( (array) $all, array_flip( array( 'source_path', 'target_url', 'status_code', 'match_type', 'is_active' ) ) );
	}
}
