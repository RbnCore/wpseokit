<?php
/**
 * Pure HTML reader for the score measurements: title, canonical links, robots meta, Open Graph, JSON-LD.
 * No WordPress calls; reads what the page really prints.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Rules;

defined( 'ABSPATH' ) || exit;

final class HeadParser {

	/**
	 * Reads the `<head>` and the JSON-LD blocks of a page.
	 *
	 * @param string $html Full response body.
	 * @return array{title: string, canonicals: list<string>, robots: list<string>, og: array<string, list<string>>, jsonld: list<array<string, mixed>>}
	 */
	public static function parse( string $html ): array {
		$out = array(
			'title'      => '',
			'canonicals' => array(),
			'robots'     => array(),
			'og'         => array(),
			'jsonld'     => self::jsonld( $html ),
		);
		$pos = stripos( $html, '</head>' );
		$head = false === $pos ? $html : substr( $html, 0, $pos );

		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $head, $m ) ) {
			$out['title'] = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}

		if ( preg_match_all( '#<link\b[^>]*>#i', $head, $links ) ) {
			foreach ( $links[0] as $tag ) {
				$attrs = self::attrs( $tag );
				$rel   = strtolower( $attrs['rel'] ?? '' );
				if ( in_array( 'canonical', preg_split( '/\s+/', $rel ) ?: array(), true ) ) {
					$out['canonicals'][] = trim( $attrs['href'] ?? '' );
				}
			}
		}

		if ( preg_match_all( '#<meta\b[^>]*>#i', $head, $metas ) ) {
			foreach ( $metas[0] as $tag ) {
				$attrs = self::attrs( $tag );
				$name  = strtolower( $attrs['name'] ?? '' );
				$prop  = strtolower( $attrs['property'] ?? ( '' !== $name ? '' : ( $attrs['name'] ?? '' ) ) );
				if ( 'robots' === $name ) {
					$out['robots'][] = strtolower( trim( $attrs['content'] ?? '' ) );
				}
				if ( 0 === strpos( $prop, 'og:' ) ) {
					$out['og'][ $prop ][] = trim( $attrs['content'] ?? '' );
				}
			}
		}
		return $out;
	}

	/**
	 * Attribute map of one tag.
	 *
	 * @param string $tag Tag source.
	 * @return array<string, string>
	 */
	public static function attrs( string $tag ): array {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $tag, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$value                          = '' !== ( $row[3] ?? '' ) ? $row[3] : ( '' !== ( $row[4] ?? '' ) ? $row[4] : ( $row[5] ?? '' ) );
				$attrs[ strtolower( $row[1] ) ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $attrs;
	}

	/**
	 * Every JSON-LD node of the page, `@graph` flattened.
	 *
	 * @param string $html Full response body.
	 * @return list<array<string, mixed>>
	 */
	public static function jsonld( string $html ): array {
		$nodes = array();
		if ( ! preg_match_all( '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks ) ) {
			return $nodes;
		}
		foreach ( $blocks[1] as $json ) {
			$data = json_decode( trim( html_entity_decode( $json, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			self::collect( $data, $nodes );
		}
		return $nodes;
	}

	/**
	 * @param array<mixed>                     $data  Decoded JSON.
	 * @param list<array<string, mixed>>       $nodes Output.
	 */
	private static function collect( array $data, array &$nodes ): void {
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					self::collect( $node, $nodes );
				}
			}
			return;
		}
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			foreach ( $data as $node ) {
				if ( is_array( $node ) ) {
					self::collect( $node, $nodes );
				}
			}
			return;
		}
		$nodes[] = $data;
	}

	/**
	 * Node types as a lowercase-insensitive list.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return list<string>
	 */
	public static function types( array $node ): array {
		$type = $node['@type'] ?? array();
		return array_values( array_filter( array_map( 'strval', (array) $type ) ) );
	}

	/**
	 * Nodes that carry one of the types.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 * @param list<string>               $types Wanted types.
	 * @return list<array<string, mixed>>
	 */
	public static function by_type( array $nodes, array $types ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( array_intersect( self::types( $node ), $types ) ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * Follows an `{ "@id": … }` reference to the node it points at; other values pass through.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 * @param mixed                      $value Value.
	 * @return mixed
	 */
	public static function resolve( array $nodes, $value ) {
		if ( is_array( $value ) && isset( $value['@id'] ) && 1 === count( $value ) ) {
			foreach ( $nodes as $node ) {
				if ( ( $node['@id'] ?? null ) === $value['@id'] ) {
					return $node;
				}
			}
		}
		return $value;
	}

	/** Absolute http(s) URL with a host. */
	public static function is_absolute_url( string $url ): bool {
		return 1 === preg_match( '#^https?://[^/\s]+#i', $url );
	}

	/**
	 * URLs compare equal ignoring scheme/host case, the fragment and a trailing slash.
	 *
	 * @param string $url URL.
	 */
	public static function norm_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return rtrim( trim( $url ), '/' );
		}
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		return strtolower( ( $parts['scheme'] ?? 'http' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * True when a robots directive string carries `noindex` or `none`.
	 *
	 * @param list<string> $directives `content` values of the robots meta tags.
	 */
	public static function has_noindex( array $directives ): bool {
		foreach ( $directives as $content ) {
			foreach ( preg_split( '/\s*,\s*/', $content ) ?: array() as $token ) {
				if ( 'noindex' === $token || 'none' === $token ) {
					return true;
				}
			}
		}
		return false;
	}
}
