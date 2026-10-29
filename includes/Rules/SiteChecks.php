<?php
/**
 * Measures the 14 site rules of `score-rules.json` against the live site (HTTP) and returns a state per rule.
 * Each method follows the `checks` / `pass_condition` / `partial` / `na_when` fields of its rule one to one.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Rules;

use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Schema\Organization;

defined( 'ABSPATH' ) || exit;

final class SiteChecks {

	/** Sitemap URLs sampled for the 200 check, the lastmod check and the canonical check. */
	private const SAMPLE_URLS    = 50;
	private const SAMPLE_LASTMOD = 100;
	private const SAMPLE_CANON   = 3;
	private const SAMPLE_LINKS   = 5;
	private const SAMPLE_LLMS    = 10;

	/**
	 * Runs every site rule.
	 *
	 * @param string $sitemap_url Sitemap (index) address.
	 * @return array<string, array{state: float|null, checks: array<string, bool>, measured: string}>
	 */
	public static function run( string $sitemap_url ): array {
		$home    = home_url( '/' );
		$page    = Probe::fetch( $home );
		$head    = 200 === $page['status'] ? HeadParser::parse( $page['body'] ) : null;
		$robots  = Probe::fetch( home_url( '/robots.txt' ) );
		$entries = Probe::sitemap_entries( $sitemap_url, self::SAMPLE_LASTMOD );

		return array(
			'site_indexable'             => self::site_indexable( $head, $robots ),
			'robots_txt_healthy'         => self::robots_txt_healthy( $robots ),
			'robots_txt_sitemap_line'    => self::robots_txt_sitemap_line( $robots, $sitemap_url ),
			'sitemap_reachable_valid'    => self::sitemap_reachable_valid( $sitemap_url ),
			'sitemap_urls_resolve'       => self::sitemap_urls_resolve( $entries ),
			'sitemap_lastmod_honest'     => self::sitemap_lastmod_honest( $entries ),
			'https_secure'               => self::https_secure(),
			'canonical_output'           => self::canonical_output( $head, $home, $entries ),
			'og_site_complete'           => self::og_site_complete( $head, $home ),
			'schema_organization_complete' => self::schema_organization_complete( $head, $home ),
			'schema_forbidden_nodes'     => self::schema_forbidden_nodes( $head ),
			'not_found_returns_404'      => self::not_found_returns_404( $home ),
			'feed_valid'                 => self::feed_valid(),
			'llms_txt_consistent'        => self::llms_txt_consistent(),
		);
	}

	/**
	 * @param array<string, bool> $checks   Check results.
	 * @param float|null          $state    State; null = derive from the checks (passed / total).
	 * @param string              $measured Short value shown to the user (no personal data).
	 * @return array{state: float|null, checks: array<string, bool>, measured: string}
	 */
	private static function result( array $checks, ?float $state = null, string $measured = '' ): array {
		return array(
			'state'    => null === $state ? Calculator::ratio( $checks ) : $state,
			'checks'   => $checks,
			'measured' => $measured,
		);
	}

	/** Pass only when every check passes (rules without a `partial` field). */
	private static function all_or_nothing( array $checks, string $measured = '' ): array {
		return self::result( $checks, in_array( false, $checks, true ) ? 0.0 : 1.0, $measured );
	}

	/**
	 * @param array<string, mixed>|null $head   Parsed home page head.
	 * @param array<string, mixed>      $robots Fetched robots.txt.
	 */
	private static function site_indexable( ?array $head, array $robots ): array {
		$meta_ok = null !== $head && ! HeadParser::has_noindex( $head['robots'] );
		$root_ok = 200 !== $robots['status'] || Probe::robots_allows( $robots['body'], '/' );
		$checks  = array(
			'option'               => '1' === (string) get_option( 'blog_public', '1' ),
			'meta_robots'          => $meta_ok,
			'robots_disallow_root' => $root_ok,
		);
		return self::all_or_nothing( $checks, sprintf( 'blog_public=%s', (string) get_option( 'blog_public', '1' ) ) );
	}

	/** @param array<string, mixed> $robots Fetched robots.txt. */
	private static function robots_txt_healthy( array $robots ): array {
		$type   = strtolower( $robots['headers']['content-type'] ?? '' );
		$checks = array(
			'status'       => 200 === $robots['status'],
			'content_type' => 0 === strpos( $type, 'text/plain' ),
			'size'         => 200 === $robots['status'] && strlen( $robots['body'] ) <= 512000,
		);
		return self::result( $checks, null, sprintf( '%d %s %d B', $robots['status'], $type, strlen( $robots['body'] ) ) );
	}

	/** @param array<string, mixed> $robots Fetched robots.txt. */
	private static function robots_txt_sitemap_line( array $robots, string $sitemap_url ): array {
		$lines    = 200 === $robots['status'] ? Probe::robots_sitemaps( $robots['body'] ) : array();
		$absolute = array_values( array_filter( $lines, array( HeadParser::class, 'is_absolute_url' ) ) );
		$target   = $absolute ? $absolute[0] : ( $lines ? $lines[0] : $sitemap_url );
		$target_ok = $absolute && 200 === Probe::head_status( $target );
		$checks   = array(
			'line_present' => (bool) $absolute,
			'target_ok'    => (bool) $target_ok,
		);
		$state = ( ! $lines ) ? 0.0 : ( $checks['line_present'] && $checks['target_ok'] ? 1.0 : 0.5 );
		return self::result( $checks, $state, sprintf( /* translators: %d: number of lines. */ __( '%d line(s)', 'wpseokit' ), count( $lines ) ) );
	}

	private static function sitemap_reachable_valid( string $sitemap_url ): array {
		$res  = Probe::fetch( $sitemap_url );
		$type = strtolower( $res['headers']['content-type'] ?? '' );
		$doc  = 200 === $res['status'] ? Probe::parse_sitemap( $res['body'] ) : null;
		$locs_ok = null !== $doc && $doc['entries'];
		if ( $locs_ok ) {
			foreach ( $doc['entries'] as $row ) {
				if ( 1 !== preg_match( '#^https://[^/\s]+#i', $row['loc'] ) ) {
					$locs_ok = false;
					break;
				}
			}
		}
		$checks = array(
			'reachable_xml' => 200 === $res['status'] && false !== strpos( $type, 'xml' ),
			'structure'     => (bool) $locs_ok,
		);
		return self::result( $checks, null, sprintf( '%d %s', $res['status'], $type ) );
	}

	/** @param list<array{loc: string, lastmod: string}> $entries Sitemap entries in order. */
	private static function sitemap_urls_resolve( array $entries ): array {
		$sample = array_slice( $entries, 0, self::SAMPLE_URLS );
		if ( ! $sample ) {
			return self::result( array( 'sample_200' => false ), 0.0, __( '0 URLs', 'wpseokit' ) );
		}
		$ok = 0;
		foreach ( $sample as $row ) {
			if ( 200 === Probe::head_status( $row['loc'] ) ) {
				++$ok;
			}
		}
		return self::result( array( 'sample_200' => $ok === count( $sample ) ), $ok / count( $sample ), sprintf( '%d/%d', $ok, count( $sample ) ) );
	}

	/**
	 * Distinct lastmod values / sampled URLs; one timestamp over 90% of a sample of two or more forces 0.
	 *
	 * @param list<array{loc: string, lastmod: string}> $entries Sitemap entries in order.
	 */
	private static function sitemap_lastmod_honest( array $entries ): array {
		$sample = array_slice( $entries, 0, self::SAMPLE_LASTMOD );
		$n      = count( $sample );
		if ( 0 === $n ) {
			return self::result( array( 'distinct' => false, 'bulk_generation' => false ), 0.0, __( '0 URLs', 'wpseokit' ) );
		}
		$counts = array_count_values( array_filter( array_column( $sample, 'lastmod' ), 'strlen' ) );
		$ratio  = count( $counts ) / $n;
		$top    = $counts ? max( $counts ) / $n : 0.0;
		$bulk   = $n >= 2 && $top > 0.9;
		$checks = array(
			'distinct'        => $ratio >= 1.0,
			'bulk_generation' => ! $bulk,
		);
		return self::result( $checks, $bulk ? 0.0 : min( 1.0, $ratio ), sprintf( /* translators: 1: distinct counts, 2: sample size. */ __( '%1$d distinct / %2$d', 'wpseokit' ), count( $counts ), $n ) );
	}

	private static function https_secure(): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$http = Probe::fetch( 'http://' . $host . '/' );
		$tls  = Probe::fetch( 'https://' . $host . '/' );
		$loc  = strtolower( $http['location'] );
		$checks = array(
			'http_redirect' => in_array( $http['status'], array( 301, 308 ), true ) && 0 === strpos( $loc, 'https://' . strtolower( $host ) ),
			'certificate'   => 0 !== $tls['status'],
			'no_downgrade'  => 0 !== $tls['status'] && 0 !== strpos( strtolower( $tls['location'] ), 'http://' ),
		);
		return self::result( $checks, null, sprintf( 'http %d, https %d', $http['status'], $tls['status'] ) );
	}

	/**
	 * @param array<string, mixed>|null                 $head    Parsed home head.
	 * @param list<array{loc: string, lastmod: string}> $entries Sitemap entries.
	 */
	private static function canonical_output( ?array $head, string $home, array $entries ): array {
		$checks = array( 'home' => self::canonical_is_self( $head, $home ) );
		foreach ( range( 1, self::SAMPLE_CANON ) as $i ) {
			$loc = $entries[ $i - 1 ]['loc'] ?? '';
			$ok  = false;
			if ( '' !== $loc ) {
				$res = Probe::fetch( $loc );
				$ok  = 200 === $res['status'] && self::canonical_is_self( HeadParser::parse( $res['body'] ), $loc );
			}
			$checks[ 'sample_' . $i ] = $ok;
		}
		return self::result( $checks, null, sprintf( '%d/%d', count( array_filter( $checks ) ), count( $checks ) ) );
	}

	/** Exactly one canonical link, absolute, equal to the page URL. */
	private static function canonical_is_self( ?array $head, string $url ): bool {
		if ( null === $head || 1 !== count( $head['canonicals'] ) ) {
			return false;
		}
		$canonical = $head['canonicals'][0];
		return HeadParser::is_absolute_url( $canonical ) && HeadParser::norm_url( $canonical ) === HeadParser::norm_url( $url );
	}

	/** @param array<string, mixed>|null $head Parsed home head. */
	private static function og_site_complete( ?array $head, string $home ): array {
		$og     = $head['og'] ?? array();
		$first  = static fn( string $prop ): string => (string) ( $og[ $prop ][0] ?? '' );
		$image  = $first( 'og:image' );
		$url    = $first( 'og:url' );
		$canon  = $head['canonicals'][0] ?? $home;
		$checks = array(
			'og_title' => '' !== $first( 'og:title' ),
			'og_type'  => '' !== $first( 'og:type' ),
			'og_image' => HeadParser::is_absolute_url( $image ) && 200 === Probe::head_status( $image ),
			'og_url'   => HeadParser::is_absolute_url( $url ) && HeadParser::norm_url( $url ) === HeadParser::norm_url( $canon ),
		);
		return self::result( $checks );
	}

	/** @param array<string, mixed>|null $head Parsed home head. */
	private static function schema_organization_complete( ?array $head, string $home ): array {
		$nodes = $head['jsonld'] ?? array();
		$org   = HeadParser::by_type( $nodes, array( 'Organization', 'Corporation', 'LocalBusiness', 'NewsMediaOrganization', 'OnlineStore' ) )[0] ?? array();
		$host  = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$url   = (string) ( $org['url'] ?? '' );
		$logo  = HeadParser::resolve( $nodes, $org['logo'] ?? '' );
		if ( is_array( $logo ) ) {
			$logo = (string) ( $logo['url'] ?? $logo['contentUrl'] ?? '' );
		}
		$checks = array(
			'name' => '' !== trim( (string) ( $org['name'] ?? '' ) ),
			'url'  => HeadParser::is_absolute_url( $url ) && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host,
			'logo' => HeadParser::is_absolute_url( (string) $logo ),
		);
		// The check measures the published JSON-LD; the source (settings / site_icon / none) comes from the one logo resolver the card and the Schema screen use.
		return self::result( $checks, null, 'logo_source=' . Organization::resolved_logo()['source'] );
	}

	/** @param array<string, mixed>|null $head Parsed home head. */
	private static function schema_forbidden_nodes( ?array $head ): array {
		$nodes    = $head['jsonld'] ?? array();
		$orgs     = HeadParser::by_type( $nodes, array( 'Organization', 'Corporation', 'LocalBusiness', 'NewsMediaOrganization', 'OnlineStore' ) );
		$self_set = false;
		foreach ( $orgs as $org ) {
			if ( isset( $org['aggregateRating'] ) || isset( $org['review'] ) ) {
				$self_set = true;
			}
		}
		$search = false;
		foreach ( HeadParser::by_type( $nodes, array( 'WebSite' ) ) as $site ) {
			$actions = $site['potentialAction'] ?? array();
			$actions = ( is_array( $actions ) && isset( $actions['@type'] ) ) ? array( $actions ) : (array) $actions;
			foreach ( $actions as $action ) {
				if ( is_array( $action ) && in_array( 'SearchAction', HeadParser::types( $action ), true ) ) {
					$search = true;
				}
			}
		}
		$checks = array(
			'no_faqpage'         => ! HeadParser::by_type( $nodes, array( 'FAQPage' ) ),
			'no_self_rating'     => ! $self_set,
			'no_product_on_list' => self::list_page_has_no_product(),
			'no_searchaction'    => ! $search,
		);
		return self::result( $checks );
	}

	/** Reads the first category archive; a site without one has no list page to fault. */
	private static function list_page_has_no_product(): bool {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( ! is_array( $terms ) || ! $terms || is_wp_error( $terms[0] ) ) {
			return true;
		}
		$link = get_term_link( $terms[0] );
		if ( is_wp_error( $link ) ) {
			return true;
		}
		$res = Probe::fetch( $link );
		if ( 200 !== $res['status'] ) {
			return true;
		}
		return ! HeadParser::by_type( HeadParser::jsonld( $res['body'] ), array( 'Product' ) );
	}

	private static function not_found_returns_404( string $home ): array {
		$path = substr( md5( $home . 'wpseokit-score-404' ), 0, 12 );
		$res  = Probe::fetch( trailingslashit( $home ) . $path . '/' );
		return self::all_or_nothing( array( 'status' => in_array( $res['status'], array( 404, 410 ), true ) ), (string) $res['status'] );
	}

	private static function feed_valid(): array {
		$res    = Probe::fetch( get_feed_link() );
		$checks = array(
			'status'  => 200 === $res['status'],
			'parse'   => false,
			'links'   => false,
			'pubdate' => false,
		);
		if ( 200 === $res['status'] ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_string( $res['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			if ( false !== $xml && isset( $xml->channel ) ) {
				$checks['parse'] = true;
				$items           = array();
				foreach ( $xml->channel->item as $item ) {
					$items[] = $item;
					if ( count( $items ) >= self::SAMPLE_LINKS ) {
						break;
					}
				}
				$links_ok = (bool) $items;
				$date_ok  = (bool) $items;
				foreach ( $items as $item ) {
					$link = trim( (string) $item->link );
					if ( ! HeadParser::is_absolute_url( $link ) || 200 !== Probe::head_status( $link ) ) {
						$links_ok = false;
					}
					if ( 1 !== preg_match( '/^(?:[A-Z][a-z]{2}, )?\d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}(?::\d{2})? (?:[+-]\d{4}|GMT|UT|[A-Z]{1,3})$/', trim( (string) $item->pubDate ) ) ) {
						$date_ok = false;
					}
				}
				$checks['links']   = $links_ok;
				$checks['pubdate'] = $date_ok;
			}
		}
		return self::result( $checks, null, (string) $res['status'] );
	}

	private static function llms_txt_consistent(): array {
		if ( ! (bool) ( SettingsRepository::section( 'llms' )['enabled'] ?? false ) ) {
			return array(
				'state'    => null,
				'checks'   => array(),
				'measured' => __( 'llms.txt is off', 'wpseokit' ),
			);
		}
		$res    = Probe::fetch( home_url( '/llms.txt' ) );
		$type   = strtolower( $res['headers']['content-type'] ?? '' );
		$first  = strtok( ltrim( $res['body'], "\xEF\xBB\xBF" ), "\n" );
		$name   = (string) get_bloginfo( 'name' );
		$links  = array();
		if ( preg_match_all( '#\]\((https?://[^)\s]+)\)#', $res['body'], $m ) ) {
			$links = array_slice( array_unique( $m[1] ), 0, self::SAMPLE_LLMS );
		}
		$links_ok = 200 === $res['status'];
		foreach ( $links as $link ) {
			if ( 200 !== Probe::head_status( $link ) ) {
				$links_ok = false;
			}
		}
		$checks = array(
			'content_type' => 200 === $res['status'] && 0 === strpos( $type, 'text/plain' ) && false !== strpos( $type, 'utf-8' ),
			'no_bom'       => 200 === $res['status'] && 0 !== strpos( $res['body'], "\xEF\xBB\xBF" ),
			'h1_first'     => is_string( $first ) && 0 === strpos( $first, '# ' ) && ( '' === $name || false !== stripos( $first, $name ) ),
			'links'        => $links_ok,
		);
		return self::result( $checks, null, sprintf( /* translators: 1: HTTP status, 2: number of links. */ __( '%1$d, %2$d link(s)', 'wpseokit' ), $res['status'], count( $links ) ) );
	}
}
