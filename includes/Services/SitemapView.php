<?php
/**
 * Human view of the XML sitemaps: plain server-side HTML pages at `/sitemap/`, `/sitemap/<group>/` and `/sitemap/<group>/<page>/`.
 *
 * - No XSLT (Chrome removes it and puts a red warning bar on top of every page that uses it). The XML sitemaps stay pure XML
 *   for the bots; this is a separate address for people. The same address never answers differently by Accept or user agent.
 * - Data: the same `SitemapService` readers the XML uses (`files()`, `kinds()`, `file_xml()`); the rows are read back from the
 *   XML of the file, so the page can never list anything the XML does not.
 * - No JavaScript, no outside file, font or image: one inline stylesheet and inline SVG, light and dark by
 *   `prefers-color-scheme`. Every control is a plain link, so the pages work with scripts switched off.
 * - `X-Robots-Tag: noindex, follow` + `<meta name="robots">`; not listed in any sitemap, not disallowed in robots.txt (so the
 *   noindex can be read). Sitemap switched off: not answered (WordPress 404). Served from `parse_request`, no rewrite rules.
 *
 * Design: docs/tasarim/sitemap/ozet.md (WPRJ-569), decision: docs/kapsam-ve-mimari.md §3.13 (WPRJ-581).
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

defined( 'ABSPATH' ) || exit;

final class SitemapView {

	/** Address of the index page; groups and files live below it. */
	public const BASE = 'sitemap';

	private const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	private const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';

	/** Rows per "Jump to rows" band, and the row count above which the bands show. */
	private const BAND     = 25;
	private const BAND_MIN = 50;

	/** Groups with more than this many members get a bar that scrolls with the page (it would fill the screen on a phone). */
	private const BAR_MAX = 6;

	private const ICON = array(
		'back'   => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
		'next'   => '<path d="M5 12h14M12 5l7 7-7 7"/>',
		'top'    => '<path d="M12 19V5M5 12l7-7 7 7"/>',
		'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		'image'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-8 8"/>',
		'site'   => '<path d="M12 3v6M5 21v-5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v5M12 9v5M3 21h4M10 21h4M17 21h4"/>',
		'post'   => '<path d="M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6"/>',
		'page'   => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 9h16M9 9v11"/>',
		'term'   => '<path d="M3 12V4h8l10 10-8 8zM7.5 8.5h.01"/>',
		'custom' => '<path d="M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8"/>',
		'code'   => '<path d="M8 8l-5 4 5 4M16 8l5 4-5 4M14 5l-4 14"/>',
	);

	public static function init(): void {
		add_action( 'parse_request', array( self::class, 'serve' ), 1 );
	}

	/** Address of the index page, or of a group (`$group`) or one of its files (`$page` > 1; the first file has no number). */
	public static function url( string $group = '', int $page = 1 ): string {
		$path = '/' . self::BASE . '/' . ( '' === $group ? '' : $group . '/' . ( $page > 1 ? $page . '/' : '' ) );
		return home_url( $path );
	}

	/* ------------------------------------------------------------------ request */

	/**
	 * `parse_request`: answers the human addresses when the sitemap is on; anything else is left to WordPress.
	 *
	 * @param \WP $wp Request object.
	 */
	public static function serve( $wp ): void {
		if ( ! $wp instanceof \WP || ! SitemapService::enabled() ) {
			return;
		}
		$request = (string) $wp->request;
		if ( ! preg_match( '#^' . self::BASE . '(?:/([a-z0-9_-]+)(?:/([0-9]+))?)?$#', $request, $m ) ) {
			return;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || get_page_by_path( $request ) ) {
			return; // A real page of the site with this address wins.
		}
		$group = $m[1] ?? '';
		$page  = isset( $m[2] ) ? (int) $m[2] : 1;
		if ( isset( $m[2] ) && $page < 2 ) {
			return; // "/sitemap/post/1/" does not exist: the first file has no number.
		}
		$groups = self::groups();
		if ( '' !== $group && ( ! isset( $groups[ $group ] ) || ! isset( $groups[ $group ]['files'][ $page - 1 ] ) ) ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '/' !== substr( $path, -1 ) ) {
			wp_safe_redirect( home_url( '/' . $request . '/' ), 301, 'WPSeoKit' );
			exit;
		}
		self::send( '' === $group ? self::index_document( $groups ) : self::group_document( $groups, $group, $page ), $method );
	}

	private static function send( string $body, string $method ): void {
		$etag = '"' . md5( $body ) . '"';
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Cache-Control: no-cache' );
		header( 'ETag: ' . $etag );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			status_header( 304 );
			exit;
		}
		header( 'Content-Length: ' . strlen( $body ) );
		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- every dynamic part is escaped where it is written.
		}
		exit;
	}

	/* ------------------------------------------------------------------ data */

	/**
	 * Files grouped by content type, in display order (pages, categories, posts, ...), not XML order.
	 *
	 * @return array<string, array{key: string, label: string, kind: string, icon: string, urls: int, lastmod: string, files: array<int, array{name: string, url: string, count: int, lastmod: string}>}>
	 */
	private static function groups(): array {
		$kinds  = SitemapService::kinds();
		$groups = array();
		foreach ( SitemapService::files_for_display() as $file ) {
			if ( ! preg_match( '/^(.+)-sitemap[0-9]*\.xml$/', $file['name'], $m ) || ! isset( $kinds[ $m[1] ] ) ) {
				continue;
			}
			$key = $m[1];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'     => $key,
					'label'   => self::label( $kinds[ $key ] ),
					'kind'    => $kinds[ $key ]['kind'],
					'icon'    => self::icon_for( $key, $kinds[ $key ]['kind'] ),
					'urls'    => 0,
					'lastmod' => '',
					'files'   => array(),
				);
			}
			$groups[ $key ]['urls']   += $file['count'];
			$groups[ $key ]['lastmod'] = max( $groups[ $key ]['lastmod'], $file['lastmod'] );
			$groups[ $key ]['files'][]  = $file;
		}
		return $groups;
	}

	/** Plural name WordPress gives the post type or taxonomy ("Posts", "Categories"); the file prefix when it is gone. */
	private static function label( array $kind ): string {
		if ( 'term' === $kind['kind'] ) {
			$object = get_taxonomy( $kind['name'] );
		} else {
			$object = get_post_type_object( $kind['name'] );
		}
		$name = $object && isset( $object->labels->name ) ? (string) $object->labels->name : '';
		return '' !== $name ? $name : $kind['name'];
	}

	private static function icon_for( string $key, string $kind ): string {
		if ( 'term' === $kind ) {
			return 'term';
		}
		return 'post' === $key || 'page' === $key ? $key : 'custom';
	}

	/**
	 * URL rows of one sitemap file, read back from its XML.
	 *
	 * @return array<int, array{loc: string, lastmod: string, images: int}>
	 */
	private static function rows( string $group, int $page ): array {
		$xml = SitemapService::file_xml( $group, $page );
		if ( null === $xml ) {
			return array();
		}
		$dom = new \DOMDocument();
		if ( ! @$dom->loadXML( $xml, LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return array();
		}
		$xp = new \DOMXPath( $dom );
		$xp->registerNamespace( 'sm', self::NS_SITEMAP );
		$xp->registerNamespace( 'image', self::NS_IMAGE );
		$rows = array();
		foreach ( $xp->query( '/sm:urlset/sm:url' ) ?: array() as $url ) {
			$rows[] = array(
				'loc'     => (string) $xp->evaluate( 'string(sm:loc)', $url ),
				'lastmod' => (string) $xp->evaluate( 'string(sm:lastmod)', $url ),
				'images'  => (int) $xp->evaluate( 'count(image:image)', $url ),
			);
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ pages */

	/** Whole document around the body. */
	private static function document( string $title, string $body ): string {
		$dir = is_rtl() ? ' dir="rtl"' : '';
		return '<!DOCTYPE html><html lang="' . esc_attr( (string) get_bloginfo( 'language' ) ) . '"' . $dir . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow">'
			. '<title>' . esc_html( $title . ' · ' . __( 'XML Sitemap', 'wpseokit' ) . ' · ' . (string) get_bloginfo( 'name' ) ) . '</title><style>' . self::css() . '</style></head>'
			. '<body id="top">' . $body
			. '<footer class="colophon"><div class="wrap"><p>' . esc_html__( 'Generated by WPSeoKit. This page is only a view of the XML sitemap; search engines read the XML.', 'wpseokit' ) . '</p></div></footer></body></html>' . "\n";
	}

	private static function brand(): string {
		return '<p class="brand">' . self::svg( 'site' ) . '<strong>' . esc_html( (string) get_bloginfo( 'name' ) ) . '</strong><span class="badge">' . esc_html__( 'XML Sitemap', 'wpseokit' ) . '</span></p>';
	}

	private static function xml_button( string $url ): string {
		return '<a class="btn" href="' . esc_url( $url ) . '">' . self::svg( 'code' ) . esc_html__( 'Open the XML', 'wpseokit' ) . '</a>';
	}

	private static function index_document( array $groups ): string {
		$out = '<a class="skip" href="#list">' . esc_html__( 'Skip to the list', 'wpseokit' ) . '</a><header class="hd"><div class="wrap">' . self::brand()
			. '<h1>' . esc_html__( 'Sitemap index', 'wpseokit' ) . '</h1>';
		$files = 0;
		$urls  = 0;
		foreach ( $groups as $g ) {
			$files += count( $g['files'] );
			$urls  += $g['urls'];
		}
		$host = esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out .= '<p class="lead">' . sprintf( esc_html__( 'This is the list of pages on %s that search engines such as Google can read. You can browse it here; the plain XML behind this page is what the bots use.', 'wpseokit' ), '<strong>' . $host . '</strong>' ) . '</p>'
			. '<ul class="meta"><li>' . self::count_b( count( $groups ), _n_noop( '%s group', '%s groups', 'wpseokit' ) ) . '</li><li>' . self::count_b( $files, _n_noop( '%s file', '%s files', 'wpseokit' ) ) . '</li><li>' . self::count_b( $urls, _n_noop( '%s URL', '%s URLs', 'wpseokit' ) ) . '</li></ul>'
			. '<p class="tools">' . self::xml_button( SitemapService::index_url() ) . '</p>'
			. '</div></header><main class="wrap" id="main">';
		if ( ! $groups ) {
			$out .= self::note( __( 'No sitemap files yet', 'wpseokit' ), __( 'The index is empty: no content type is switched on for the sitemap, or nothing is published yet. Search engines will find nothing to read here.', 'wpseokit' ), '', 'list' ) . '</main>';
		} else {
			$out .= '<ul class="cards" id="list">';
			foreach ( $groups as $g ) {
				$out .= self::card( $g );
			}
			$out .= '</ul></main>';
		}
		return self::document( __( 'Sitemap index', 'wpseokit' ), $out );
	}

	private static function card( array $g ): string {
		$id    = 'g-' . $g['key'];
		$first = self::url( $g['key'] );
		$multi = count( $g['files'] ) > 1;
		$out   = '<li><section class="card" aria-labelledby="' . esc_attr( $id ) . '"><div class="card__top"><span class="card__i">' . self::svg( $g['icon'] ) . '</span><div>'
			. '<h2 id="' . esc_attr( $id ) . '"><a href="' . esc_url( $first ) . '">' . esc_html( $g['label'] ) . '</a></h2>'
			. '<p class="card__file mono">' . esc_html( $g['files'][0]['name'] ) . ( $multi ? ' +' . ( count( $g['files'] ) - 1 ) : '' ) . '</p></div></div>'
			. '<p class="stat"><b>' . esc_html( number_format_i18n( $g['urls'] ) ) . '</b><span>'
			. ( $multi ? esc_html( sprintf( _n( 'URLs in %s file', 'URLs in %s files', count( $g['files'] ), 'wpseokit' ), number_format_i18n( count( $g['files'] ) ) ) ) : esc_html( trim( sprintf( _n( '%s URL', '%s URLs', $g['urls'], 'wpseokit' ), '' ) ) ) )
			. '</span></p>';
		if ( '' !== $g['lastmod'] ) {
			$out .= '<p class="card__upd">' . esc_html__( 'Last updated', 'wpseokit' ) . ' ' . self::time_tag( $g['lastmod'] ) . '</p>';
		}
		$out .= '<div class="card__act">';
		if ( $multi ) {
			$total = count( $g['files'] );
			foreach ( $g['files'] as $i => $file ) {
				$aria = sprintf(
					/* translators: 1: group name, 2: file number, 3: number of files, 4: number of URLs. */
					__( '%1$s, file %2$s of %3$s, %4$s URLs', 'wpseokit' ),
					$g['label'],
					number_format_i18n( $i + 1 ),
					number_format_i18n( $total ),
					number_format_i18n( $file['count'] )
				);
				$out .= '<a class="btn" href="' . esc_url( self::url( $g['key'], $i + 1 ) ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( sprintf( __( 'File %s', 'wpseokit' ), number_format_i18n( $i + 1 ) ) ) . ' <span class="n">' . esc_html( number_format_i18n( $file['count'] ) ) . '</span></a>';
			}
		} else {
			$out .= '<a class="btn btn--pri" href="' . esc_url( $first ) . '">' . esc_html( sprintf( __( 'View %s', 'wpseokit' ), $g['label'] ) ) . ' ' . self::svg( 'next' ) . '</a>';
		}
		return $out . '</div></section></li>';
	}

	private static function group_document( array $groups, string $key, int $pos ): string {
		$g     = $groups[ $key ];
		$files = $g['files'];
		$file  = $files[ $pos - 1 ];
		$total = count( $files );
		$rows  = self::rows( $key, $pos );
		$out   = '<a class="skip" href="#list">' . esc_html__( 'Skip to the URL list', 'wpseokit' ) . '</a>' . self::bar( $groups, $key )
			. '<header class="hd"><div class="wrap">' . self::brand()
			. '<h1>' . esc_html( $g['label'] ) . ( $total > 1 ? ' <span class="badge">' . esc_html( sprintf( /* translators: 1: file number, 2: number of files. */ __( 'File %1$s of %2$s', 'wpseokit' ), number_format_i18n( $pos ), number_format_i18n( $total ) ) ) . '</span>' : '' ) . '</h1>'
			. '<ul class="meta"><li>' . esc_html__( 'File', 'wpseokit' ) . ' <span class="mono">' . esc_html( $file['name'] ) . '</span></li>'
			. '<li>' . self::count_b( $file['count'], _n_noop( '%s URL in this file', '%s URLs in this file', 'wpseokit' ) ) . '</li>'
			. ( $total > 1 ? '<li>' . self::count_b( $g['urls'], _n_noop( '%s URL in the group', '%s URLs in the group', 'wpseokit' ) ) . '</li>' : '' )
			. ( '' !== $g['lastmod'] ? '<li>' . esc_html__( 'Newest change', 'wpseokit' ) . ' <b>' . esc_html( self::date_text( $g['lastmod'] ) ) . '</b></li>' : '' )
			. '</ul></div></header><main class="wrap" id="main">';

		$pager = $total > 1 ? self::pager( $key, $total, $pos, $g['label'] ) : '';
		$jump  = count( $rows ) > self::BAND_MIN;
		$out  .= ( '' !== $pager ? '<div class="tools">' . $pager . '</div>' : '' )
			. ( $jump ? self::jump( count( $rows ), __( 'Jump within this file', 'wpseokit' ) ) : '' )
			. self::table( $rows, $file['name'] )
			. '<div class="foot"><a class="btn" href="#top">' . self::svg( 'top' ) . esc_html__( 'Back to top', 'wpseokit' ) . '</a>' . self::xml_button( $file['url'] ) . $pager . '</div>'
			. ( $jump ? self::jump( count( $rows ), __( 'Jump within this file, bottom', 'wpseokit' ) ) : '' ) . '</main>';
		return self::document( $g['label'], $out );
	}

	/** Group bar: back to the index plus one button per group; the current group is marked with `aria-current`. */
	private static function bar( array $groups, string $key ): string {
		$class = count( $groups ) > self::BAR_MAX ? 'bar bar--many' : 'bar';
		$out   = '<nav class="' . $class . '" aria-label="' . esc_attr( __( 'Sitemap groups', 'wpseokit' ) ) . '"><div class="bar__in">'
			. '<a class="btn" href="' . esc_url( self::url() ) . '">' . self::svg( 'back' ) . esc_html__( 'Sitemap index', 'wpseokit' ) . '</a>';
		if ( $groups ) {
			$out .= '<span class="bar__sep" aria-hidden="true"></span><ul>';
			foreach ( $groups as $g ) {
				$out .= '<li><a class="btn"' . ( $g['key'] === $key ? ' aria-current="page"' : '' ) . ' href="' . esc_url( self::url( $g['key'] ) ) . '">' . esc_html( $g['label'] ) . ' <span class="n">' . esc_html( number_format_i18n( $g['urls'] ) ) . '</span></a></li>';
			}
			$out .= '</ul>';
		}
		return $out . '</div></nav>';
	}

	/**
	 * Previous / numbers / Next over the files of one group. Beyond ten files only the first, last and the neighbours of the
	 * current file are numbered; `…` stands for the rest.
	 *
	 * @param string $key   Group.
	 * @param int    $total Number of files in the group.
	 * @param int    $pos   Current file, 1-based.
	 */
	private static function pager( string $key, int $total, int $pos, string $label ): string {
		$out = '<ul class="pager" aria-label="' . esc_attr( sprintf( __( 'Files of %s', 'wpseokit' ), $label ) ) . '"><li>'
			. ( $pos > 1
				? '<a class="btn" rel="prev" href="' . esc_url( self::url( $key, $pos - 1 ) ) . '">' . self::svg( 'back' ) . esc_html__( 'Previous', 'wpseokit' ) . '</a>'
				: '<span class="btn" aria-disabled="true">' . self::svg( 'back' ) . esc_html__( 'Previous', 'wpseokit' ) . '</span>' )
			. '</li>';
		$gap = false;
		for ( $n = 1; $n <= $total; $n++ ) {
			if ( $total > 10 && 1 !== $n && $total !== $n && abs( $n - $pos ) > 1 ) {
				if ( ! $gap ) {
					$out .= '<li><span class="none" aria-hidden="true">…</span></li>';
				}
				$gap = true;
				continue;
			}
			$gap  = false;
			$aria = sprintf( __( 'File %1$s of %2$s', 'wpseokit' ), number_format_i18n( $n ), number_format_i18n( $total ) );
			$out .= '<li><a class="btn"' . ( $n === $pos ? ' aria-current="page"' : '' ) . ' href="' . esc_url( self::url( $key, $n ) ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( number_format_i18n( $n ) ) . '</a></li>';
		}
		return $out . '<li>' . ( $pos < $total
			? '<a class="btn" rel="next" href="' . esc_url( self::url( $key, $pos + 1 ) ) . '">' . esc_html__( 'Next', 'wpseokit' ) . self::svg( 'next' ) . '</a>'
			: '<span class="btn" aria-disabled="true">' . esc_html__( 'Next', 'wpseokit' ) . self::svg( 'next' ) . '</span>' ) . '</li></ul>';
	}

	/** "Jump to rows 1–25 …" bands (plain `#r<n>` links to the row ids of the table). */
	private static function jump( int $count, string $aria ): string {
		$out = '<nav class="jump" aria-label="' . esc_attr( $aria ) . '"><p>' . esc_html__( 'Jump to rows', 'wpseokit' ) . '</p><ul>';
		for ( $from = 1; $from <= $count; $from += self::BAND ) {
			$out .= '<li><a class="btn" href="#r' . $from . '">' . $from . '–' . min( $from + self::BAND - 1, $count ) . '</a></li>';
		}
		return $out . '</ul></nav>';
	}

	/**
	 * The URL table, or the empty-group note.
	 *
	 * @param array<int, array{loc: string, lastmod: string, images: int}> $rows Rows of the file.
	 */
	private static function table( array $rows, string $name ): string {
		if ( ! $rows ) {
			return '<div id="list">' . self::note( __( 'This group has no URLs', 'wpseokit' ), __( 'No published items are listed in this file, so there is nothing to show. Search engines see an empty list.', 'wpseokit' ), 'back' ) . '</div>';
		}
		$count   = count( $rows );
		$hasimg  = (bool) array_sum( array_column( $rows, 'images' ) );
		$caption = '<caption>' . esc_html( sprintf( _n( '%1$s URL in %2$s', '%1$s URLs in %2$s', $count, 'wpseokit' ), number_format_i18n( $count ), $name ) ) . '</caption>';
		$out     = '<div id="list"><div class="tw"><table>' . $caption . '<thead><tr><th scope="col" class="c-n">' . esc_html__( 'No.', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'URL', 'wpseokit' ) . '</th>'
			. ( $hasimg ? '<th scope="col" class="c-i">' . esc_html__( 'Images', 'wpseokit' ) . '</th>' : '' )
			. '<th scope="col" class="c-d">' . esc_html__( 'Last modified', 'wpseokit' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			$n    = $i + 1;
			$out .= '<tr' . ( 1 === $n % self::BAND ? ' id="r' . $n . '"' : '' ) . '><td class="c-n" data-l="' . esc_attr( __( 'No.', 'wpseokit' ) ) . '">' . $n . '</td>'
				. '<td class="c-u"><a href="' . esc_url( $row['loc'] ) . '">' . esc_html( $row['loc'] ) . '</a></td>';
			if ( $hasimg ) {
				$out .= '<td class="c-i" data-l="' . esc_attr( __( 'Images', 'wpseokit' ) ) . '">' . ( $row['images'] ? '<span class="imgs">' . self::svg( 'image' ) . $row['images'] . '</span>' : '<span class="none">—</span>' ) . '</td>';
			}
			$out .= '<td class="c-d" data-l="' . esc_attr( __( 'Last modified', 'wpseokit' ) ) . '">'
				. ( '' !== $row['lastmod'] ? self::time_tag( $row['lastmod'] ) . '<span class="mono">' . esc_html( $row['lastmod'] ) . '</span>' : '<span class="none">—</span>' ) . '</td></tr>';
		}
		return $out . '</tbody></table></div></div>';
	}

	/**
	 * Notice box (`role="status"`).
	 *
	 * @param string $title  Heading (translated).
	 * @param string $text   Text (translated).
	 * @param string $back   'back' adds the "Back to the sitemap index" button.
	 * @param string $id     Element id when the box itself is the list landmark.
	 */
	private static function note( string $title, string $text, string $back = '', string $id = '' ): string {
		$out = '<div' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' class="note note--warn" role="status">' . self::svg( 'info' ) . '<div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>';
		if ( 'back' === $back ) {
			$out .= '<a class="btn btn--pri" href="' . esc_url( self::url() ) . '">' . self::svg( 'back' ) . esc_html__( 'Back to the sitemap index', 'wpseokit' ) . '</a>';
		} elseif ( '' !== $id ) {
			$out .= '<p class="none">' . esc_html__( 'Site owner: open WPSeoKit › Sitemap and switch on at least one content type.', 'wpseokit' ) . '</p>';
		}
		return $out . '</div></div>';
	}

	/* ------------------------------------------------------------------ small helpers */

	/** `<b>127</b>` followed by the plural text with the number taken out. */
	private static function count_b( int $n, array $noop ): string {
		return '<b>' . esc_html( number_format_i18n( $n ) ) . '</b> ' . esc_html( trim( sprintf( translate_nooped_plural( $noop, $n, 'wpseokit' ), '' ) ) );
	}

	private static function time_tag( string $iso ): string {
		return '<time datetime="' . esc_attr( $iso ) . '" title="' . esc_attr( $iso ) . '">' . esc_html( self::date_text( $iso ) ) . '</time>';
	}

	/** `2026-10-28T09:14:00+00:00` -> `28 Oct 2026`. */
	private static function date_text( string $iso ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m ) ) {
			return $iso;
		}
		global $wp_locale;
		return (int) $m[3] . ' ' . $wp_locale->get_month_abbrev( $wp_locale->get_month( (int) $m[2] ) ) . ' ' . $m[1];
	}

	private static function svg( string $name ): string {
		return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . self::ICON[ $name ] . '</svg>';
	}

	/* ------------------------------------------------------------------ CSS */

	/** Inline CSS. The only fixed colours are in the two `:root` blocks (light, dark). */
	private static function css(): string {
		return <<<'CSS'
:root{color-scheme:light dark;--bg:#f0f0f1;--surface:#fff;--surface-2:#f6f7f7;--line:#dcdcde;--line-strong:#8c8f94;--text:#1d2327;--muted:#50575e;--ac:#2145e6;--ac-fg:#fff;--ac-soft:color-mix(in srgb,var(--ac) 9%,var(--surface));--warn:#8a4b00;--warn-bg:#fcf9e8;--r:6px;--r-lg:10px;--ctl:2.5rem;--mono:ui-monospace,SFMono-Regular,Consolas,monospace}
@media (prefers-color-scheme:dark){:root{--bg:#0f1316;--surface:#1a2025;--surface-2:#222a30;--line:#343d44;--line-strong:#8695a0;--text:#e8edf0;--muted:#a9b4bb;--ac:#8aa4ff;--ac-fg:#0b1020;--warn:#f0b44c;--warn-bg:#33270f}}
*{box-sizing:border-box}
html{font-size:106.25%;-webkit-text-size-adjust:100%}
@media (prefers-reduced-motion:no-preference){html{scroll-behavior:smooth}}
body{margin:0;background:var(--bg);color:var(--text);font:1rem/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--ac);text-underline-offset:.2em}
:focus-visible{outline:3px solid var(--ac);outline-offset:2px}
.skip{position:absolute;left:1rem;top:-4rem;z-index:20;padding:.6rem 1rem;background:var(--ac);color:var(--ac-fg);border-radius:var(--r)}
.skip:focus{top:.5rem}
.wrap{max-width:74rem;margin:0 auto;padding:0 1rem}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:var(--ctl);padding:.4rem .9rem;border:1px solid var(--line-strong);border-radius:var(--r);background:var(--surface);color:var(--ac);font:inherit;font-weight:600;text-decoration:none;line-height:1.25}
.btn:hover{background:var(--ac-soft)}
.btn--pri,.btn[aria-current=page]{background:var(--ac);border-color:var(--ac);color:var(--ac-fg)}
.btn--pri:hover,.btn[aria-current=page]:hover{background:var(--ac)}
.btn[aria-disabled=true]{color:var(--muted);border-color:var(--line);background:var(--surface-2);cursor:not-allowed}
.btn .n{font-weight:400;opacity:.9}
.ico{width:1.25rem;height:1.25rem;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.bar{position:sticky;top:0;z-index:10;background:var(--surface);border-bottom:1px solid var(--line)}
.bar__in{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;padding:.6rem 1rem;max-width:74rem;margin:0 auto}
.bar ul,.pager,.jump ul,.meta,.cards{display:flex;flex-wrap:wrap;gap:.5rem;margin:0;padding:0;list-style:none}
.bar__sep{width:1px;align-self:stretch;background:var(--line);margin:0 .25rem}
.bar--many{position:static}
.hd{padding:1.5rem 0 1rem}
.brand{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;color:var(--muted);margin:0 0 .75rem}
.brand strong{color:var(--text)}
.badge{display:inline-flex;align-items:center;min-height:1.75rem;padding:0 .6rem;border:1px solid var(--line-strong);border-radius:99px;background:var(--surface);font-size:1rem;font-weight:600;color:var(--text)}
h1{margin:0 0 .5rem;font-size:2rem;line-height:1.2}
.lead{max-width:46rem;margin:0 0 1rem;color:var(--muted)}
.meta{gap:.25rem 1.25rem;margin:0 0 1rem;color:var(--muted)}
.meta b{color:var(--text)}
.mono{font-family:var(--mono);overflow-wrap:anywhere}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(18rem,1fr));gap:1rem;margin:0 0 1.5rem}
.card{display:flex;flex-direction:column;height:100%;padding:1.25rem 1.25rem 1rem;background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg)}
.card__top{display:flex;align-items:center;gap:.75rem;margin:0 0 1rem}
.card__i{display:grid;place-items:center;width:2.75rem;height:2.75rem;flex:none;border-radius:var(--r);background:var(--ac-soft);color:var(--ac)}
.card h2{margin:0;font-size:1.25rem;line-height:1.25}
.card h2 a{display:inline-flex;align-items:center;min-height:var(--ctl);color:var(--text);text-decoration:none}
.card h2 a:hover{text-decoration:underline}
.card__file{margin:0;color:var(--muted)}
.stat{display:flex;align-items:baseline;gap:.5rem;margin:0 0 .25rem}
.stat b{font-size:2rem;line-height:1.1}
.stat span,.card__upd,.none{color:var(--muted)}
.card__upd{margin:0 0 1rem}
.card__act{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:auto;padding-top:1rem;border-top:1px solid var(--line)}
.card__act .btn{flex:1 1 auto}
.tools,.foot{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between;margin:0 0 1rem}
.jump{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:0 0 1rem}
.jump p{margin:0;color:var(--muted)}
.tw{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);margin:0 0 1rem;container-type:inline-size}
table{width:100%;border-collapse:collapse;table-layout:fixed}
caption{text-align:left;padding:.75rem 1rem;font-weight:600;border-bottom:1px solid var(--line)}
th,td{padding:.35rem 1rem;text-align:left;vertical-align:top;border-bottom:1px solid var(--line)}
th{background:var(--surface-2);font-weight:600}
tbody tr:last-child td{border-bottom:0}
.c-n{width:4.5rem;color:var(--muted)}
.c-i{width:7rem}
.c-d{width:20rem}
td a{display:inline-flex;align-items:center;min-height:var(--ctl);overflow-wrap:anywhere}
td .mono{display:block;margin-top:.15rem;color:var(--muted)}
.imgs{display:inline-flex;align-items:center;gap:.35rem;min-height:var(--ctl)}
tr:target td{background:var(--ac-soft)}
tbody tr{scroll-margin-top:8rem}
.note{display:flex;gap:.75rem;align-items:flex-start;margin:0 0 1.5rem;padding:1rem;border:1px solid var(--line-strong);border-radius:var(--r-lg);background:var(--warn-bg);color:var(--text)}
.note h2{margin:0 0 .25rem;font-size:1.125rem}
.note p{margin:0 0 .75rem}
.note .ico{margin-top:.2rem;color:var(--warn)}
.colophon{padding:1rem 0 2rem;color:var(--muted)}
.colophon p{margin:0}
@container (max-width:44rem){
thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
table,tbody,caption{display:block;width:100%}
tr{display:flex;flex-wrap:wrap;align-items:center;gap:0 1.25rem;padding:.4rem 0;border-bottom:1px solid var(--line)}
tbody tr:last-child{border-bottom:0}
td{display:block;border:0;padding:.15rem 1rem;width:auto}
td.c-u{width:100%}
.c-n{display:none}
td:not(.c-u)::before{content:attr(data-l) ": ";color:var(--muted)}
td .mono{display:inline;margin-left:.5rem}
.c-i,.c-d{width:auto}
}
@media (max-width:48rem){
.wrap,.bar__in{padding-left:.75rem;padding-right:.75rem}
.btn{min-height:2.75rem}
.bar__in{gap:.4rem;padding-top:.5rem;padding-bottom:.5rem}
.bar ul{gap:.4rem}
.bar__sep{display:none}
h1{font-size:1.75rem}
.card__act .btn,.pager .btn,.jump .btn{flex:1 1 auto}
}
CSS;
	}
}
