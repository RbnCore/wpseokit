<?php
/**
 * 404 log: one row per missing address with a hit counter. Stores the path without its query string, the referrer
 * without its query string, a trimmed user agent and a salted SHA-256 of the visitor's network address; the address
 * itself is never stored. Old rows are removed by age and by count, checked at most once a day.
 *
 * Addresses that only scanners ask for (`/wp-login.php`, `/.env`, …) are skipped while `redirects.ignore_bots` is on.
 * The user agent is not used to decide that: it can be faked, and search engine crawlers must stay visible because
 * their 404 addresses are the ones to fix. Contract: docs/sozlesme.md §2.1 and §4.5, behaviour: docs/kapsam-ve-mimari.md §3.9.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Models\Log404;
use Wpseokit\Repositories\Log404Repository;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class Log404Service {

	/** Option that holds the secret mixed into the address hash (`wpseokit_ip_salt`, never autoloaded). */
	public const SALT_OPTION = 'wpseokit_ip_salt';

	/** Transient that limits the cleanup to once a day. */
	public const PRUNE_TRANSIENT = 'wpseokit_404_pruned';

	/** Paths that only vulnerability scanners ask for. */
	private const PROBES = '#^/(wp-login\.php|xmlrpc\.php|wp-admin|wp-content|wp-includes|\.env|\.git)|\.php/?$#i';

	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'record' ), 100 );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
	}

	/** Whether a stored path is scanner noise. */
	public static function is_probe( string $path ): bool {
		return 1 === preg_match( self::PROBES, $path );
	}

	/** Salted SHA-256 of an address; an empty string when it is not a valid address. */
	public static function ip_hash( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$salt = (string) get_option( self::SALT_OPTION, '' );
		if ( '' === $salt ) {
			$salt = wp_generate_password( 64, true, true );
			add_option( self::SALT_OPTION, $salt, '', false );
		}
		return hash_hmac( 'sha256', $ip, $salt );
	}

	/** Referrer reduced to host and path (the query string can carry personal data). */
	public static function clean_referer( string $referer ): string {
		$parts = wp_parse_url( $referer );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return mb_substr( strtolower( (string) $parts['host'] ) . (string) ( $parts['path'] ?? '' ), 0, 255 );
	}

	/** `template_redirect` (priority 100): logs the address when WordPress ended up on a 404. */
	public static function record(): void {
		if ( ! is_404() || ! SettingsRepository::get( 'redirects.log_404', true ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		$path = RedirectService::request_path();
		if ( '' === $path || ( SettingsRepository::get( 'redirects.ignore_bots', true ) && self::is_probe( $path ) ) ) {
			return;
		}
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? self::clean_referer( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to host and path.
		$agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		Log404Repository::record( $path, self::ip_hash( $ip ), $referer, $agent );
		self::maybe_prune();
	}

	/** Removes old rows by age and count, at most once a day. Returns the rows removed (0 when skipped). */
	public static function maybe_prune( bool $force = false ): int {
		if ( ! $force && false !== get_transient( self::PRUNE_TRANSIENT ) ) {
			return 0;
		}
		set_transient( self::PRUNE_TRANSIENT, time(), DAY_IN_SECONDS );
		return Log404Repository::prune(
			(int) SettingsRepository::get( 'redirects.log_retention_days', 30 ),
			(int) SettingsRepository::get( 'redirects.log_max_rows', 5000 )
		);
	}

	/* ------------------------------------------------------------------ REST */

	public static function register_routes( string $namespace ): void {
		$id = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
		register_rest_route(
			$namespace,
			'/404-logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_list' ),
				'permission_callback' => Rest::permission(),
				'args'                => array(
					'search'   => array( 'type' => 'string' ),
					'orderby'  => array( 'type' => 'string' ),
					'order'    => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/404-logs/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'rest_delete' ),
				'permission_callback' => Rest::permission(),
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			$namespace,
			'/404-logs/(?P<id>\d+)/redirect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_redirect' ),
				'permission_callback' => Rest::permission(),
				'args'                => array(
					'id'          => $id,
					'target_url'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'status_code' => array(
						'type'    => 'integer',
						'default' => 301,
					),
				),
			)
		);
	}

	public static function rest_list( \WP_REST_Request $request ): \WP_REST_Response {
		$result = Log404Repository::query( $request->get_params() );
		$per    = max( 1, min( 100, (int) ( $request['per_page'] ?? 20 ) ) );
		$resp   = new \WP_REST_Response( array_map( static fn( Log404 $l ): array => $l->to_array(), $result['items'] ) );
		$resp->header( 'X-WP-Total', (string) $result['total'] );
		$resp->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per ) ) );
		return $resp;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_delete( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return Log404Repository::delete( $id ) ? new \WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		) : new \WP_Error( 'wpseokit_not_found', __( 'Log entry not found.', 'wpseokit' ), array( 'status' => 404 ) );
	}

	/**
	 * One click: turns a logged address into a redirect. The log row goes away with it (RedirectService::create()).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_redirect( \WP_REST_Request $request ) {
		$row = Log404Repository::find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'wpseokit_not_found', __( 'Log entry not found.', 'wpseokit' ), array( 'status' => 404 ) );
		}
		$rule = RedirectService::create(
			array(
				'source_path' => $row->url_path,
				'target_url'  => (string) $request['target_url'],
				'status_code' => (int) ( $request['status_code'] ?? 301 ),
			)
		);
		return is_wp_error( $rule ) ? $rule : new \WP_REST_Response( $rule->to_array(), 201 );
	}
}
