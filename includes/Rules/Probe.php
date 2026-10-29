<?php
/**
 * Network measurement layer of the score: HTTP requests with a timeout, cached HEAD status, sitemap map,
 * robots.txt matching. Uses the WordPress HTTP API only (so `pre_http_request` can stand in for the network).
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Rules;

use AdminKit\Core\Cache;
use Wpseokit\Registry;
use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class Probe {

	private const UA = 'WPSeoKit-Score/1.0';

	/** Files read from a sitemap index at most (a safety bound, far above normal sites). */
	private const MAX_SITEMAP_FILES = 60;

	/** @var array<string, array<string, mixed>> Per-request memory of full fetches. */
	private static array $memo = array();

	/**
	 * One HTTP request. Redirects are NOT followed, so a 301 is seen as a 301.
	 *
	 * @param string $url    Absolute URL.
	 * @param string $method GET or HEAD.
	 * @return array{status: int, headers: array<string, string>, body: string, location: string, error: string}
	 */
	public static function fetch( string $url, string $method = 'GET' ): array {
		$key = $method . ' ' . $url;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$response = wp_remote_request(
			$url,
			array(
				'method'     => $method,
				'timeout'    => self::timeout(),
				'redirection' => 0,
				'user-agent' => self::UA,
				'headers'    => array( 'Accept' => '*/*' ),
			)
		);
		$out = array(
			'status'   => 0,
			'headers'  => array(),
			'body'     => '',
			'location' => '',
			'error'    => '',
		);
		if ( is_wp_error( $response ) ) {
			$out['error'] = $response->get_error_message();
		} else {
			$out['status'] = (int) wp_remote_retrieve_response_code( $response );
			foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
				$out['headers'][ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
			$out['body']     = 'HEAD' === $method ? '' : (string) wp_remote_retrieve_body( $response );
			$out['location'] = $out['headers']['location'] ?? '';
		}
		self::$memo[ $key ] = $out;
		return $out;
	}

	/** Drops the per-request memory (a new measurement run). */
	public static function reset(): void {
		self::$memo = array();
	}

	/** Request timeout in seconds. */
	public static function timeout(): int {
		return (int) ( SettingsRepository::section( 'score' )['http_timeout'] ?? 5 );
	}

	/** Cache lifetime of HEAD results and the sitemap map. */
	public static function ttl(): int {
		return (int) ( SettingsRepository::section( 'score' )['cache_hours'] ?? 6 ) * HOUR_IN_SECONDS;
	}

	/**
	 * Status code of a HEAD request (GET when the server rejects HEAD), cached in the `wpseokit.probe` group.
	 * A redirect or an error counts as it is: 3xx/4xx/5xx are not 200.
	 */
	public static function head_status( string $url, bool $use_cache = true ): int {
		$key = 'h_' . md5( $url );
		if ( $use_cache ) {
			$hit = Cache::get( Registry::PROBE, $key, $found );
			if ( $found ) {
				return (int) $hit;
			}
		}
		$res = self::fetch( $url, 'HEAD' );
		if ( in_array( $res['status'], array( 403, 405, 501 ), true ) ) {
			$res = self::fetch( $url, 'GET' );
		}
		Cache::set( Registry::PROBE, $key, $res['status'], self::ttl() );
		return $res['status'];
	}

	/**
	 * Reads a sitemap document.
	 *
	 * @param string $xml Body.
	 * @return array{type: string, entries: list<array{loc: string, lastmod: string}>}|null Null when it is not a sitemap XML.
	 */
	public static function parse_sitemap( string $xml ): ?array {
		if ( '' === trim( $xml ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $doc ) {
			return null;
		}
		$root = strtolower( $doc->getName() );
		if ( ! in_array( $root, array( 'urlset', 'sitemapindex' ), true ) ) {
			return null;
		}
		$entries = array();
		$ns      = (string) ( $doc->getNamespaces()[''] ?? '' );
		foreach ( $doc->children( $ns ) as $node ) {
			$fields    = $node->children( $ns );
			$entries[] = array(
				'loc'     => trim( (string) $fields->loc ),
				'lastmod' => trim( (string) $fields->lastmod ),
			);
		}
		return array(
			'type'    => 'urlset' === $root ? 'urlset' : 'index',
			'entries' => $entries,
		);
	}

	/**
	 * Every URL of the sitemap with its lastmod, read over HTTP and cached for the cache lifetime.
	 *
	 * @param string $index_url Sitemap (index) address.
	 * @return array<string, string> Normalised URL => lastmod ('' when none).
	 */
	public static function sitemap_map( string $index_url ): array {
		$key = 'map_' . md5( $index_url );
		$hit = Cache::get( Registry::PROBE, $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$map = array();
		foreach ( self::sitemap_entries( $index_url, PHP_INT_MAX ) as $row ) {
			$map[ HeadParser::norm_url( $row['loc'] ) ] = $row['lastmod'];
		}
		if ( $map ) {
			Cache::set( Registry::PROBE, $key, $map, self::ttl() );
		}
		return $map;
	}

	/**
	 * URL entries in sitemap order, until `$limit` are collected.
	 *
	 * @param string $index_url Sitemap (index) address.
	 * @param int    $limit     Entries wanted.
	 * @return list<array{loc: string, lastmod: string}>
	 */
	public static function sitemap_entries( string $index_url, int $limit ): array {
		$res = self::fetch( $index_url );
		if ( 200 !== $res['status'] ) {
			return array();
		}
		$doc = self::parse_sitemap( $res['body'] );
		if ( null === $doc ) {
			return array();
		}
		if ( 'urlset' === $doc['type'] ) {
			return array_slice( $doc['entries'], 0, $limit );
		}
		$out   = array();
		$files = 0;
		foreach ( $doc['entries'] as $child ) {
			if ( $files++ >= self::MAX_SITEMAP_FILES || count( $out ) >= $limit ) {
				break;
			}
			if ( ! HeadParser::is_absolute_url( $child['loc'] ) ) {
				continue;
			}
			$sub = self::fetch( $child['loc'] );
			if ( 200 !== $sub['status'] ) {
				continue;
			}
			$sub_doc = self::parse_sitemap( $sub['body'] );
			if ( null === $sub_doc || 'urlset' !== $sub_doc['type'] ) {
				continue;
			}
			foreach ( $sub_doc['entries'] as $row ) {
				$out[] = $row;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * `Sitemap:` lines of a robots.txt body.
	 *
	 * @param string $robots Body.
	 * @return list<string>
	 */
	public static function robots_sitemaps( string $robots ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $robots ) ?: array() as $line ) {
			if ( preg_match( '/^\s*sitemap\s*:\s*(\S+)/i', $line, $m ) ) {
				$out[] = $m[1];
			}
		}
		return $out;
	}

	/**
	 * Rule groups that apply to `User-agent: *`.
	 *
	 * @param string $robots Body.
	 * @return list<array{type: string, path: string}> `allow` / `disallow` lines in file order.
	 */
	public static function robots_rules( string $robots ): array {
		$rules   = array();
		$agents  = array();
		$started = false;
		foreach ( preg_split( '/\R/', $robots ) ?: array() as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( $started ) {
					$agents  = array();
					$started = false;
				}
				$agents[] = strtolower( $value );
				continue;
			}
			if ( in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				$started = true;
				if ( in_array( '*', $agents, true ) ) {
					$rules[] = array(
						'type' => $field,
						'path' => $value,
					);
				}
			}
		}
		return $rules;
	}

	/**
	 * Whether `User-agent: *` may fetch the path (longest matching rule wins, Allow wins a tie).
	 *
	 * @param string $robots Body.
	 * @param string $path   URL path with query.
	 */
	public static function robots_allows( string $robots, string $path ): bool {
		$best_len = -1;
		$allowed  = true;
		foreach ( self::robots_rules( $robots ) as $rule ) {
			if ( '' === $rule['path'] ) {
				continue;
			}
			$pattern = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $rule['path'], '#' ) ) . '#';
			if ( 1 !== preg_match( $pattern, $path ) ) {
				continue;
			}
			$len = strlen( $rule['path'] );
			if ( $len > $best_len || ( $len === $best_len && 'allow' === $rule['type'] ) ) {
				$best_len = $len;
				$allowed  = 'allow' === $rule['type'];
			}
		}
		return $allowed;
	}
}
