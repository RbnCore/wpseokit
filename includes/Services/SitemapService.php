<?php
/**
 * XML sitemap: `/sitemap_index.xml` plus `/post-sitemap.xml`, `/page-sitemap.xml`, `/category-sitemap.xml` (the same
 * addresses and shape Yoast SEO used, so search engines keep their submitted sitemap).
 *
 * - Served on the fly from `parse_request` (no rewrite rules, nothing to flush); documents are cached in the `wpseokit.sitemap` group
 *   behind one version token, so a content change clears everything at once.
 * - Posts and pages: `lastmod` = last modified (UTC), `image:image` = featured image then images used in the content
 *   (uploads of this site only). Terms: `lastmod` = newest modified post inside the term; empty terms are left out.
 * - Left out: noindex content (the same rule as the robots tag, `RobotsService::is_noindex()`), password-protected posts.
 * - Paged: `post-sitemap.xml`, `post-sitemap2.xml`, … with `sitemap.per_page` URLs each.
 * - WordPress' own `/wp-sitemap.xml` is switched off (two sitemaps would be duplicate content). `robots.txt` gets a
 *   `Sitemap:` line. A static front page is listed as `/` in the page sitemap, a posts front page at the top of the
 *   post sitemap. `/sitemap.xml` redirects to the index.
 * REST: `/sitemap/status`, `/sitemap/flush`. Contract: docs/sozlesme.md §2.1 and §4.5, decision: docs/kapsam-ve-mimari.md §2 #8.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use AdminKit\Core\Cache;
use Wpseokit\Registry;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class SitemapService {

	public const INDEX = 'sitemap_index.xml';

	private const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	private const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';

	public static function init(): void {
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'parse_request', array( self::class, 'serve' ), 1 );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 20, 2 );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		add_filter( 'wpseokit_status', array( self::class, 'filter_status' ) );

		foreach ( array( 'save_post', 'deleted_post', 'transition_post_status', 'created_term', 'edited_term', 'delete_term', 'set_object_terms', 'clean_attachment_cache' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
		foreach ( array( 'permalink_structure', 'category_base', 'home', 'show_on_front', 'page_on_front', 'wpseokit_settings' ) as $option ) {
			add_action( 'update_option_' . $option, array( self::class, 'flush' ) );
		}
		add_action( 'add_option_wpseokit_settings', array( self::class, 'flush' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_for_meta' ), 10, 3 );
		}
	}

	/** Address of the sitemap index. */
	public static function index_url(): string {
		return home_url( '/' . self::INDEX );
	}

	/** Whether the sitemap is switched on in the settings. */
	public static function enabled(): bool {
		return (bool) SettingsRepository::get( 'sitemap.enabled', true );
	}

	/* ------------------------------------------------------------------ request */

	/**
	 * `parse_request`: answers the sitemap addresses when the tool is on; anything else is left to WordPress.
	 *
	 * @param \WP $wp Request object.
	 */
	public static function serve( $wp ): void {
		if ( ! $wp instanceof \WP || ! self::enabled() ) {
			return;
		}
		$request = (string) $wp->request;
		$method  = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		if ( 'sitemap.xml' === $request ) {
			wp_safe_redirect( self::index_url(), 301, 'WPSeoKit' );
			exit;
		}
		if ( self::INDEX === $request ) {
			if ( self::browse( '', 1, $method ) ) {
				return;
			}
			self::send( self::index_xml(), 'text/xml', $method, true );
			return;
		}
		if ( ! preg_match( '/^([a-z0-9_-]+)-sitemap([0-9]*)\.xml$/', $request, $m ) ) {
			return;
		}
		$page = '' === $m[2] ? 1 : (int) $m[2];
		if ( $page < 1 || '' !== $m[2] && 1 === $page || ! isset( self::kinds()[ $m[1] ] ) ) {
			return; // "post-sitemap1.xml" does not exist: the first file has no number.
		}
		$xml = self::file_xml( $m[1], $page );
		if ( null === $xml ) {
			return;
		}
		self::browse( $m[1], $page, $method );
		self::send( $xml, 'text/xml', $method, true );
	}

	/**
	 * A person opening a sitemap address in a browser gets the designed page (`SitemapView`) by 302; bots, curl and every
	 * other client (they do not send these headers) get the same pure XML at the same address. The XML body never changes.
	 * A click from a page of this site (`Sec-Fetch-Site: same-origin`, e.g. the "Open the XML" button) still gets the XML.
	 *
	 * @param string $group  Group key of the file ('' = index).
	 * @param int    $page   File number in the group.
	 * @param string $method GET or HEAD.
	 * @return bool Always false when it returns (a redirect exits).
	 */
	private static function browse( string $group, int $page, string $method ): bool {
		header( 'Vary: Sec-Fetch-Dest, Sec-Fetch-Mode, Sec-Fetch-Site, Accept' );
		$h = static function ( string $name ): string {
			return strtolower( trim( (string) ( $_SERVER[ $name ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		};
		if ( 'document' !== $h( 'HTTP_SEC_FETCH_DEST' ) || 'navigate' !== $h( 'HTTP_SEC_FETCH_MODE' ) || 'same-origin' === $h( 'HTTP_SEC_FETCH_SITE' ) || false === strpos( $h( 'HTTP_ACCEPT' ), 'text/html' ) ) {
			return false;
		}
		header( 'Cache-Control: private, no-store' );
		wp_safe_redirect( SitemapView::url( $group, $page ), 302, 'WPSeoKit' );
		exit;
	}

	/**
	 * Prints a finished document and stops.
	 *
	 * @param string $body    Document.
	 * @param string $type    Content type (without charset).
	 * @param string $method  GET or HEAD.
	 * @param bool   $noindex Whether to add `X-Robots-Tag: noindex, follow` (sitemaps are not pages for search results).
	 */
	private static function send( string $body, string $type, string $method, bool $noindex = false ): void {
		$etag = '"' . md5( $body ) . '"';
		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}
		header( 'Cache-Control: public, max-age=3600' );
		header( 'ETag: ' . $etag );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			status_header( 304 );
			exit;
		}
		header( 'Content-Length: ' . strlen( $body ) );
		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- XML built with self::esc().
		}
		exit;
	}

	/**
	 * `robots_txt`: adds the `Sitemap:` line unless the site asks not to be indexed or the line is already there.
	 *
	 * @param string     $output Robots file so far.
	 * @param string|int $public `blog_public`.
	 */
	public static function robots_txt( $output, $public = 1 ): string {
		$output = (string) $output;
		$url    = self::index_url();
		if ( ! self::enabled() || ! (int) $public || false !== stripos( $output, 'Sitemap: ' . $url ) ) {
			return $output;
		}
		return rtrim( $output ) . "\n\nSitemap: " . $url . "\n";
	}

	/* ------------------------------------------------------------------ what is listed */

	/**
	 * File prefix => definition for every enabled public post type and taxonomy.
	 *
	 * @return array<string, array{kind: string, name: string}>
	 */
	public static function kinds(): array {
		$out = array();
		foreach ( (array) SettingsRepository::get( 'sitemap.post_types', array( 'post', 'page' ) ) as $type ) {
			if ( is_string( $type ) && is_post_type_viewable( $type ) && 'attachment' !== $type ) {
				$out[ $type ] = array(
					'kind' => 'post',
					'name' => $type,
				);
			}
		}
		foreach ( (array) SettingsRepository::get( 'sitemap.taxonomies', array( 'category' ) ) as $tax ) {
			if ( is_string( $tax ) && ! isset( $out[ $tax ] ) && is_taxonomy_viewable( $tax ) ) {
				$out[ $tax ] = array(
					'kind' => 'term',
					'name' => $tax,
				);
			}
		}
		return $out;
	}

	private static function per_page(): int {
		return max( 1, (int) SettingsRepository::get( 'sitemap.per_page', 1000 ) );
	}

	/**
	 * Every listed address of one file group, in file order (cached, small: id + date only).
	 *
	 * @return array<int, array{0: int, 1: string}> Rows of [post or term ID, lastmod in `Y-m-d H:i:s` UTC].
	 */
	private static function rows( string $prefix ): array {
		$kinds = self::kinds();
		if ( ! isset( $kinds[ $prefix ] ) ) {
			return array();
		}
		return (array) Cache::remember(
			Registry::SITEMAP,
			'rows-' . $prefix,
			self::ttl(),
			static fn(): array => 'post' === $kinds[ $prefix ]['kind'] ? self::post_rows( $prefix ) : self::term_rows( $prefix )
		);
	}

	/** @return array<int, array{0: int, 1: string}> */
	private static function post_rows( string $type ): array {
		global $wpdb;
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' ORDER BY post_modified_gmt ASC, ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
				$type
			),
			ARRAY_N
		);
		$found = is_array( $found ) ? $found : array();
		$ids   = array_map( 'intval', array_column( $found, 0 ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		$rows = array();
		foreach ( $found as $row ) {
			$post = get_post( (int) $row[0] );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$ctx = array(
				'type'      => 'post',
				'front'     => false,
				'post'      => $post,
				'paged'     => 1,
				'max_pages' => 1,
			);
			// A redirected or gone address is not a page to list: the sitemap holds 200 addresses only.
			if ( ! RobotsService::is_noindex( $ctx ) && ! RedirectService::is_redirected_url( (string) get_permalink( $post ) ) ) {
				$rows[] = array( (int) $row[0], (string) $row[1] );
			}
		}
		return self::with_home( $type, $rows );
	}

	/**
	 * The front page: a static front page keeps its place but is listed as `/` (see `entry()`); a posts front page is
	 * added on top of the post sitemap with the newest modified date. ID 0 stands for the home address.
	 *
	 * @param array<int, array{0: int, 1: string}> $rows Rows so far.
	 * @return array<int, array{0: int, 1: string}>
	 */
	private static function with_home( string $type, array $rows ): array {
		if ( 'post' !== $type || 'page' === get_option( 'show_on_front' ) || ! $rows ) {
			return self::front_page_first( $type, $rows );
		}
		$latest = max( array_column( $rows, 1 ) );
		array_unshift( $rows, array( 0, $latest ) );
		return $rows;
	}

	/**
	 * @param array<int, array{0: int, 1: string}> $rows Rows so far.
	 * @return array<int, array{0: int, 1: string}>
	 */
	private static function front_page_first( string $type, array $rows ): array {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		if ( 'page' !== $type || ! $front ) {
			return $rows;
		}
		foreach ( $rows as $i => $row ) {
			if ( $row[0] === $front ) {
				unset( $rows[ $i ] );
				array_unshift( $rows, $row );
				break;
			}
		}
		return array_values( $rows );
	}

	/** @return array<int, array{0: int, 1: string}> */
	private static function term_rows( string $taxonomy ): array {
		global $wpdb;
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $terms ) || ! $terms ) {
			return array();
		}
		// Newest modified published post in each term, one query (children do not count for their parent).
		$latest = array();
		$found  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, MAX(p.post_modified_gmt) FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.post_status = 'publish' GROUP BY tt.term_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names.
				$taxonomy
			),
			ARRAY_N
		);
		foreach ( is_array( $found ) ? $found : array() as $row ) {
			$latest[ (int) $row[0] ] = (string) $row[1];
		}
		$rows = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$ctx = array(
				'type'      => 'term',
				'term'      => $term,
				'paged'     => 1,
				'max_pages' => 1,
			);
			if ( RobotsService::is_noindex( $ctx ) ) {
				continue;
			}
			$rows[] = array( $term->term_id, $latest[ $term->term_id ] ?? '' );
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ documents */

	/**
	 * The index document.
	 */
	public static function index_xml(): string {
		return (string) Cache::remember(
			Registry::SITEMAP,
			'index',
			self::ttl(),
			static function (): string {
				$xml = self::head( 'sitemapindex' );
				foreach ( self::files() as $file ) {
					$xml .= "\t<sitemap>\n\t\t<loc>" . self::esc( $file['url'] ) . "</loc>\n";
					if ( '' !== $file['lastmod'] ) {
						$xml .= "\t\t<lastmod>" . self::esc( $file['lastmod'] ) . "</lastmod>\n";
					}
					$xml .= "\t</sitemap>\n";
				}
				return $xml . "</sitemapindex>\n";
			}
		);
	}

	/**
	 * Every sitemap file, in index order.
	 *
	 * @return array<int, array{name: string, url: string, count: int, lastmod: string}> `lastmod` is ISO-8601 or ''.
	 */
	public static function files(): array {
		$per   = self::per_page();
		$files = array();
		foreach ( array_keys( self::kinds() ) as $prefix ) {
			$rows = self::rows( $prefix );
			if ( ! $rows ) {
				continue;
			}
			foreach ( array_chunk( $rows, $per ) as $i => $chunk ) {
				$name    = $prefix . '-sitemap' . ( 0 === $i ? '' : (string) ( $i + 1 ) ) . '.xml';
				$files[] = array(
					'name'    => $name,
					'url'     => home_url( '/' . $name ),
					'count'   => count( $chunk ),
					'lastmod' => self::iso( max( array_column( $chunk, 1 ) ) ),
				);
			}
		}
		return $files;
	}

	/** Fixed place of the well-known groups in every human-facing list; anything else sorts between post_tag and author. */
	private const DISPLAY_ORDER = array( 'page', 'category', 'post', 'post_tag' );

	/**
	 * Position of a group key in human-facing lists: pages, categories, posts, tags, then custom types A-Z, then authors.
	 * The XML index keeps `files()` order; this only orders what people read.
	 *
	 * @return array{0: int, 1: string}
	 */
	public static function display_rank( string $key ): array {
		$fixed = array_search( $key, self::DISPLAY_ORDER, true );
		if ( false !== $fixed ) {
			return array( (int) $fixed, '' );
		}
		return array( 'author' === $key ? 6 : 5, $key );
	}

	/**
	 * Group keys in display order.
	 *
	 * @param array<int, string> $keys Group keys.
	 * @return array<int, string>
	 */
	public static function display_sort( array $keys ): array {
		usort( $keys, static fn( string $a, string $b ): int => self::display_rank( $a ) <=> self::display_rank( $b ) );
		return $keys;
	}

	/**
	 * `files()` regrouped in display order (same entries, page order inside a group kept).
	 *
	 * @return array<int, array{name: string, url: string, count: int, lastmod: string}>
	 */
	public static function files_for_display(): array {
		$by_group = array();
		foreach ( self::files() as $file ) {
			$by_group[ (string) strstr( $file['name'], '-sitemap', true ) ][] = $file;
		}
		$out = array();
		foreach ( self::display_sort( array_keys( $by_group ) ) as $key ) {
			$out = array_merge( $out, $by_group[ $key ] );
		}
		return $out;
	}

	/**
	 * One file of a group, or null when that file does not exist.
	 */
	public static function file_xml( string $prefix, int $page ): ?string {
		$kinds = self::kinds();
		if ( ! isset( $kinds[ $prefix ] ) ) {
			return null;
		}
		$chunks = array_chunk( self::rows( $prefix ), self::per_page() );
		if ( ! isset( $chunks[ $page - 1 ] ) ) {
			return null;
		}
		return (string) Cache::remember(
			Registry::SITEMAP,
			'file-' . $prefix . '-' . $page,
			self::ttl(),
			static function () use ( $kinds, $prefix, $page, $chunks ): string {
				$chunk  = $chunks[ $page - 1 ];
				$images = 'post' === $kinds[ $prefix ]['kind'] && (bool) SettingsRepository::get( 'sitemap.include_images', true );
				if ( 'post' === $kinds[ $prefix ]['kind'] ) {
					$ids = array_filter( array_map( 'intval', array_column( $chunk, 0 ) ) );
					if ( $ids ) {
						_prime_post_caches( $ids, false, true );
					}
				}
				$xml = self::head( 'urlset', $prefix . '-sitemap' . ( 1 === $page ? '' : (string) $page ) . '.xml' );
				foreach ( $chunk as $row ) {
					$xml .= self::url_xml( $kinds[ $prefix ], (int) $row[0], (string) $row[1], $images );
				}
				return $xml . "</urlset>\n";
			}
		);
	}

	/** One `<url>` block ('' when the object vanished since the list was cached). */
	private static function url_xml( array $kind, int $id, string $modified, bool $images ): string {
		$pics = array();
		if ( 'term' === $kind['kind'] ) {
			$link = get_term_link( $id, $kind['name'] );
			$loc  = is_wp_error( $link ) ? '' : (string) $link;
		} elseif ( 0 === $id ) {
			$loc = home_url( '/' );
		} else {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				return '';
			}
			$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			$loc   = $id === $front ? home_url( '/' ) : (string) get_permalink( $post );
			$pics  = $images ? self::images( $post ) : array();
		}
		if ( '' === $loc ) {
			return '';
		}
		$xml = "\t<url>\n\t\t<loc>" . self::esc( $loc ) . "</loc>\n";
		if ( '' !== $modified ) {
			$xml .= "\t\t<lastmod>" . self::esc( self::iso( $modified ) ) . "</lastmod>\n";
		}
		foreach ( $pics as $pic ) {
			$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . self::esc( $pic ) . "</image:loc>\n\t\t</image:image>\n";
		}
		return $xml . "\t</url>\n";
	}

	/**
	 * Images of a post: the featured image first, then images in the content. Only files of this site (an image
	 * hosted elsewhere is not ours to advertise); each address once.
	 *
	 * @return string[]
	 */
	private static function images( \WP_Post $post ): array {
		$found = array();
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$url = wp_get_attachment_url( $thumb );
			if ( is_string( $url ) && '' !== $url ) {
				$found[] = $url;
			}
		}
		if ( false !== stripos( $post->post_content, '<img' ) && preg_match_all( '/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/is', $post->post_content, $m ) ) {
			foreach ( $m[2] as $src ) {
				$found[] = html_entity_decode( trim( $src ), ENT_QUOTES | ENT_HTML5 );
			}
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = array();
		foreach ( $found as $src ) {
			if ( 0 === strpos( $src, '//' ) ) {
				$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
			} elseif ( 0 === strpos( $src, '/' ) ) {
				$src = home_url( $src );
			}
			if ( ! preg_match( '#^https?://#i', $src ) || strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) ) !== strtolower( $host ) ) {
				continue;
			}
			$out[ $src ] = $src;
		}
		return array_values( $out );
	}

	/**
	 * XML declaration and the opening tag.
	 *
	 * @param string $root `sitemapindex` or `urlset`.
	 * @param string $file File name of a `urlset` document (unused: the XML is the same for every viewer).
	 */
	private static function head( string $root, string $file = '' ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ( 'sitemapindex' === $root ) {
			return $xml . '<sitemapindex xmlns="' . self::NS_SITEMAP . "\">\n";
		}
		return $xml . '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:image="' . self::NS_IMAGE . '" xsi:schemaLocation="' . self::NS_SITEMAP . ' http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd ' . self::NS_IMAGE . ' http://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd" xmlns="' . self::NS_SITEMAP . "\">\n";
	}

	/* ------------------------------------------------------------------ cache */

	private static function ttl(): int {
		return max( 1, (int) SettingsRepository::get( 'sitemap.cache_hours', 12 ) ) * HOUR_IN_SECONDS;
	}

	/** Clears every cached document. Hooked to each change that can alter a sitemap. */
	public static function flush(): void {
		Cache::flush_group( Registry::SITEMAP );
	}

	/**
	 * Meta hook: only robots fields, featured image and our own keys matter.
	 *
	 * @param int|string $meta_id Meta ID (unused).
	 * @param int|string $object_id Object ID (unused).
	 * @param string     $meta_key Meta key.
	 */
	public static function flush_for_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id, $object_id );
		if ( is_string( $meta_key ) && ( '_thumbnail_id' === $meta_key || 0 === strpos( $meta_key, '_wpseokit_' ) || 0 === strpos( $meta_key, 'wpseokit_' ) ) ) {
			self::flush();
		}
	}

	/* ------------------------------------------------------------------ small helpers */

	/** `2025-02-15 09:58:42` (UTC) -> `2025-02-15T09:58:42+00:00`. */
	private static function iso( string $mysql_gmt ): string {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $mysql_gmt, $m ) || '0000-00-00' === $m[1] ) {
			return '';
		}
		return $m[1] . 'T' . $m[2] . '+00:00';
	}

	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/* ------------------------------------------------------------------ status and REST */

	/**
	 * `wpseokit_status`: adds `sitemap_urls` (file name => address) for the status screen.
	 *
	 * @param mixed $status Response so far.
	 * @return mixed
	 */
	public static function filter_status( $status ) {
		if ( is_array( $status ) ) {
			$urls = array();
			if ( self::enabled() ) {
				$urls[ self::INDEX ] = self::index_url();
				foreach ( self::files() as $file ) {
					$urls[ $file['name'] ] = $file['url'];
				}
			}
			$status['sitemap_urls'] = $urls;
		}
		return $status;
	}

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/sitemap/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_status' ),
				'permission_callback' => Rest::permission(),
				'args'                => array(),
			)
		);
		register_rest_route(
			$namespace,
			'/sitemap/flush',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function (): \WP_REST_Response {
					self::flush();
					return new \WP_REST_Response( array( 'flushed' => true ) );
				},
				'permission_callback' => Rest::permission(),
				'args'                => array(),
			)
		);
	}

	/** `{ enabled, index_url, items: [{name, url, count, lastmod}] }`; the index itself comes first (count = files). */
	public static function rest_status(): \WP_REST_Response {
		$enabled = self::enabled();
		$items   = array();
		if ( $enabled ) {
			$files = self::files_for_display();
			$items[] = array(
				'name'    => self::INDEX,
				'url'     => self::index_url(),
				'count'   => count( $files ),
				'lastmod' => $files ? max( array_column( $files, 'lastmod' ) ) : '',
			);
			foreach ( $files as $file ) {
				$items[] = $file;
			}
		}
		return new \WP_REST_Response(
			array(
				'enabled'   => $enabled,
				'index_url' => self::index_url(),
				'items'     => $items,
			)
		);
	}
}
