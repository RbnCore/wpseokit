<?php
/**
 * Measures the 11 post rules of `score-rules.json` from the post's own page (HTTP) and returns a state per rule.
 * Each method follows the `checks` / `pass_condition` / `partial` / `na_when` fields of its rule one to one.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Rules;

use Wpseokit\Services\PrimaryTermService;

defined( 'ABSPATH' ) || exit;

final class PostChecks {

	private const DATE_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$/';

	private const ARTICLE_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle' );

	/**
	 * Measures one published post.
	 *
	 * @param \WP_Post $post        Post.
	 * @param string   $sitemap_url Sitemap (index) address.
	 * @return array{status: int, rules: array<string, array{state: float|null, checks: array<string, bool>, measured: string}>}|null Null when the page does not answer 200 with a body.
	 */
	public static function run( \WP_Post $post, string $sitemap_url ): ?array {
		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return null;
		}
		$res = Probe::fetch( $permalink );
		if ( 200 !== $res['status'] || '' === trim( $res['body'] ) ) {
			return null;
		}
		$head   = HeadParser::parse( $res['body'] );
		$robots = Probe::fetch( home_url( '/robots.txt' ) );

		return array(
			'status' => $res['status'],
			'rules'  => array(
				'title_present'           => self::title_present( $head ),
				'canonical_self'          => self::canonical_self( $head, $permalink ),
				'canonical_single_method' => self::canonical_single_method( $head, $res['headers'] ),
				'indexable'               => self::indexable( $head, $robots, $permalink ),
				'schema_type_valid'       => self::schema_type_valid( $head ),
				'schema_article_fields'   => self::schema_article_fields( $head, $post ),
				'schema_dates'            => self::schema_dates( $head ),
				'primary_category'        => self::primary_category( $post, $permalink ),
				'og_post_complete'        => self::og_post_complete( $head, $permalink ),
				'breadcrumb_schema'       => self::breadcrumb_schema( $head, $post, $permalink ),
				'post_in_sitemap'         => self::post_in_sitemap( $head, $post, $permalink, $sitemap_url ),
			),
		);
	}

	/**
	 * @param array<string, bool> $checks   Check results.
	 * @param float|null          $state    State; null = passed / total.
	 * @param string              $measured Short value shown to the user.
	 * @return array{state: float|null, checks: array<string, bool>, measured: string}
	 */
	private static function result( array $checks, ?float $state = null, string $measured = '' ): array {
		return array(
			'state'    => null === $state ? Calculator::ratio( $checks ) : $state,
			'checks'   => $checks,
			'measured' => $measured,
		);
	}

	/** Every check must pass (rules without a `partial` field). */
	private static function all_or_nothing( array $checks, string $measured = '' ): array {
		return self::result( $checks, in_array( false, $checks, true ) ? 0.0 : 1.0, $measured );
	}

	/** The canonical the page really prints (first head link), else the permalink. */
	private static function canonical_of( array $head, string $permalink ): string {
		return $head['canonicals'][0] ?? $permalink;
	}

	private static function title_present( array $head ): array {
		return self::all_or_nothing( array( 'title' => '' !== $head['title'] ), $head['title'] );
	}

	private static function canonical_self( array $head, string $permalink ): array {
		$list   = $head['canonicals'];
		$single = 1 === count( $list );
		$value  = $list[0] ?? '';
		$abs    = $single && HeadParser::is_absolute_url( $value );
		$self   = $abs && HeadParser::norm_url( $value ) === HeadParser::norm_url( $permalink );
		$checks = array(
			'single'   => $single,
			'absolute' => $abs,
			'self'     => $self,
		);
		if ( count( $list ) > 1 || ! $list ) {
			$state = 0.0;
		} elseif ( ! $abs ) {
			$state = 0.5;
		} else {
			$state = Calculator::ratio( $checks );
		}
		return self::result( $checks, $state, $value );
	}

	/**
	 * @param array<string, string> $headers Response headers (lower-case names).
	 */
	private static function canonical_single_method( array $head, array $headers ): array {
		$link_header = (string) ( $headers['link'] ?? '' );
		$in_header   = 1 === preg_match( '/<([^>]+)>\s*;[^,]*rel\s*=\s*"?canonical"?/i', $link_header, $m ) ? $m[1] : '';
		$in_head     = $head['canonicals'];
		if ( ! $in_head && '' === $in_header ) {
			return array(
				'state'    => null,
				'checks'   => array(),
				'measured' => __( 'no canonical output', 'wpseokit' ),
			);
		}
		$all = array_merge( $in_head, '' !== $in_header ? array( $in_header ) : array() );
		$norm = array_unique( array_map( array( HeadParser::class, 'norm_url' ), $all ) );
		$checks = array(
			'one_method'  => ! ( $in_head && '' !== $in_header ),
			'no_conflict' => 1 === count( $norm ),
		);
		return self::all_or_nothing( $checks, $in_head ? 'head' : 'header' );
	}

	private static function indexable( array $head, array $robots, string $permalink ): array {
		$path   = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$query  = (string) wp_parse_url( $permalink, PHP_URL_QUERY );
		$target = '' !== $query ? $path . '?' . $query : $path;
		$checks = array(
			'meta'       => ! HeadParser::has_noindex( $head['robots'] ),
			'robots_txt' => 200 !== $robots['status'] || Probe::robots_allows( $robots['body'], '' === $target ? '/' : $target ),
		);
		return self::all_or_nothing( $checks, implode( ' | ', $head['robots'] ) );
	}

	private static function article_nodes( array $head ): array {
		return HeadParser::by_type( $head['jsonld'], self::ARTICLE_TYPES );
	}

	private static function schema_type_valid( array $head ): array {
		$count = count( self::article_nodes( $head ) );
		$state = 1 === $count ? 1.0 : ( $count > 1 ? 0.5 : 0.0 );
		return self::result( array( 'one_node' => 1 === $count ), $state, (string) $count );
	}

	private static function schema_article_fields( array $head, \WP_Post $post ): array {
		$nodes   = $head['jsonld'];
		$article = self::article_nodes( $head )[0] ?? array();
		$title   = self::plain( get_the_title( $post ) );

		$image = HeadParser::resolve( $nodes, $article['image'] ?? '' );
		if ( is_array( $image ) ) {
			$image = isset( $image['url'] ) ? $image['url'] : ( $image[0] ?? '' );
			if ( is_array( $image ) ) {
				$image = (string) ( $image['url'] ?? '' );
			}
		}
		$author = HeadParser::resolve( $nodes, $article['author'] ?? '' );
		if ( is_array( $author ) && isset( $author[0] ) ) {
			$author = HeadParser::resolve( $nodes, $author[0] );
		}
		$author_name  = is_array( $author ) ? (string) ( $author['name'] ?? '' ) : (string) $author;
		$publisher    = HeadParser::resolve( $nodes, $article['publisher'] ?? '' );
		$has_pub      = is_array( $publisher ) ? (bool) $publisher : '' !== trim( (string) $publisher );
		$headline     = self::plain( (string) ( $article['headline'] ?? '' ) );

		$checks = array(
			'headline'  => '' !== $headline && $headline === $title,
			'image'     => is_string( $image ) && HeadParser::is_absolute_url( $image ) && 200 === Probe::head_status( $image ),
			'author'    => '' !== trim( $author_name ),
			'publisher' => $has_pub,
		);
		return self::result( $checks );
	}

	private static function schema_dates( array $head ): array {
		$article = self::article_nodes( $head )[0] ?? array();
		$checks  = array(
			'date_published' => 1 === preg_match( self::DATE_PATTERN, (string) ( $article['datePublished'] ?? '' ) ),
			'date_modified'  => 1 === preg_match( self::DATE_PATTERN, (string) ( $article['dateModified'] ?? '' ) ),
		);
		return self::result( $checks, null, (string) ( $article['dateModified'] ?? '' ) );
	}

	private static function primary_category( \WP_Post $post, string $permalink ): array {
		$term_id = PrimaryTermService::get_term_id( (int) $post->ID );
		$term    = $term_id > 0 ? get_term( $term_id, 'category' ) : null;
		$set     = $term instanceof \WP_Term;
		$in_link = $set && false !== strpos( '/' . trim( (string) wp_parse_url( $permalink, PHP_URL_PATH ), '/' ) . '/', '/' . $term->slug . '/' );
		$checks  = array(
			'set'          => $set,
			'in_permalink' => $in_link,
		);
		$state = ! $set ? 0.0 : ( $in_link ? 1.0 : 0.5 );
		return self::result( $checks, $state, $set ? $term->slug : '' );
	}

	private static function og_post_complete( array $head, string $permalink ): array {
		$og     = $head['og'];
		$first  = static fn( string $prop ): string => (string) ( $og[ $prop ][0] ?? '' );
		$image  = $first( 'og:image' );
		$url    = $first( 'og:url' );
		$checks = array(
			'og_type'        => 'article' === strtolower( $first( 'og:type' ) ),
			'og_title'       => '' !== $first( 'og:title' ),
			'og_description' => '' !== $first( 'og:description' ),
			'og_image'       => HeadParser::is_absolute_url( $image ) && 200 === Probe::head_status( $image ),
			'og_url'         => '' !== $url && HeadParser::norm_url( $url ) === HeadParser::norm_url( self::canonical_of( $head, $permalink ) ),
		);
		return self::result( $checks );
	}

	private static function breadcrumb_schema( array $head, \WP_Post $post, string $permalink ): array {
		$lists  = HeadParser::by_type( $head['jsonld'], array( 'BreadcrumbList' ) );
		$items  = array();
		$raw    = $lists[0]['itemListElement'] ?? array();
		$raw    = ( is_array( $raw ) && isset( $raw['@type'] ) ) ? array( $raw ) : (array) $raw;
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$target = $item['item'] ?? '';
			if ( is_array( $target ) ) {
				$target = (string) ( $target['@id'] ?? $target['url'] ?? '' );
			}
			$items[] = array(
				'pos'  => (int) ( $item['position'] ?? 0 ),
				'url'  => (string) $target,
				'name' => self::plain( (string) ( $item['name'] ?? '' ) ),
			);
		}
		usort( $items, static fn( array $a, array $b ): int => $a['pos'] <=> $b['pos'] );

		$last  = $items ? end( $items ) : array();
		$title = self::plain( get_the_title( $post ) );
		$last_is_post = $last && (
			( '' !== $last['url'] && HeadParser::norm_url( $last['url'] ) === HeadParser::norm_url( $permalink ) )
			|| ( '' === $last['url'] && '' !== $last['name'] && $last['name'] === $title )
		);
		$urls_ok = (bool) $items;
		foreach ( $items as $item ) {
			// The last item may omit its URL (it is the current page); every other item must answer 200.
			if ( '' === $item['url'] ) {
				if ( $item !== $last ) {
					$urls_ok = false;
				}
				continue;
			}
			if ( 200 !== Probe::head_status( $item['url'] ) ) {
				$urls_ok = false;
			}
		}
		$checks = array(
			'item_count'   => count( $items ) >= 2,
			'item_urls'    => $urls_ok,
			'last_is_post' => (bool) $last_is_post,
		);
		$state = ( $checks['item_count'] && $checks['last_is_post'] && ! $checks['item_urls'] ) ? 0.5 : Calculator::ratio( $checks );
		return self::result( $checks, $state, (string) count( $items ) );
	}

	private static function post_in_sitemap( array $head, \WP_Post $post, string $permalink, string $sitemap_url ): array {
		$map     = Probe::sitemap_map( $sitemap_url );
		$key     = HeadParser::norm_url( self::canonical_of( $head, $permalink ) );
		$present = array_key_exists( $key, $map );
		if ( ! $present ) {
			$key     = HeadParser::norm_url( $permalink );
			$present = array_key_exists( $key, $map );
		}
		$lastmod  = $present ? (int) strtotime( (string) $map[ $key ] ) : 0;
		$modified = (int) strtotime( (string) $post->post_modified_gmt . ' UTC' );
		$checks   = array(
			'present'       => $present,
			'lastmod_fresh' => $present && $lastmod > 0 && $lastmod >= $modified,
		);
		$state = ! $present ? 0.0 : ( $checks['lastmod_fresh'] ? 1.0 : 0.5 );
		return self::result( $checks, $state, $present ? (string) $map[ $key ] : '' );
	}

	/** Plain text for comparisons: tags stripped, entities decoded, whitespace collapsed. */
	private static function plain( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
