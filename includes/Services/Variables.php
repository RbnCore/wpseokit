<?php
/**
 * Template variables: `%title% %page% %sep% %sitename%` -> text. Stateless; called by MetaService, SchemaService,
 * SitemapService and YoastImportService.
 *
 * Contract: docs/sozlesme.md §3. Unknown variables resolve to an empty string (raw text never leaks into the page).
 * `%page%` includes its leading separator ("- Page 2 of 6"), exactly like Yoast's `%%page%%`, so imported Yoast
 * templates render unchanged.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class Variables {

	/** Yoast variable name => WPSeoKit variable name (only names WPSeoKit knows; anything else is left as `%name%`). */
	public const VAR_MAP = array(
		'title'            => 'title',
		'sitename'         => 'sitename',
		'sitedesc'         => 'sitedesc',
		'sep'              => 'sep',
		'page'             => 'page',
		'term_title'       => 'term_title',
		'searchphrase'     => 'searchphrase',
		'name'             => 'name',
		'date'             => 'date',
		'excerpt'          => 'excerpt',
		'category'         => 'category',
		'primary_category' => 'primary_category',
	);

	/** Private-use character that stands for "a separator" while a template is being cleaned. */
	private const SEP_MARK = "\u{E000}";

	/** Length of the automatic excerpt taken from the post content. */
	private const EXCERPT_LENGTH = 155;

	/** No state to start: kept so the registry in Plugin::start_services() can call every service the same way. */
	public static function init(): void {
	}

	/**
	 * Resolves a template. Repeated and edge separators are dropped, whitespace is collapsed.
	 *
	 * @param array<string, mixed> $ctx Context (docs/sozlesme.md §3.1).
	 */
	public static function replace( string $template, array $ctx = array() ): string {
		$out = (string) preg_replace_callback(
			'/%([a-z_]+)%/',
			static function ( array $m ) use ( $ctx ): string {
				$value = self::resolve( $m[1], $ctx );
				/* Text from the database never carries a separator mark; only `%page%` adds its own. */
				return 'page' === $m[1] ? $value : str_replace( self::SEP_MARK, '', $value );
			},
			self::protect_separator( $template )
		);
		$out = (string) preg_replace( '/\s+/u', ' ', $out );
		$out = (string) preg_replace( '/(?:\s*\x{E000}\s*){2,}/u', ' ' . self::SEP_MARK . ' ', $out );
		$out = (string) preg_replace( '/^\s*\x{E000}\s*|\s*\x{E000}\s*$/u', '', $out );
		$out = str_replace( self::SEP_MARK, self::separator(), $out );
		return trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
	}

	/**
	 * Variable catalogue for the settings screen and the REST `/variables` route.
	 *
	 * @return array<string, array{label: string, contexts: string[]}>
	 */
	public static function names(): array {
		return array(
			'title'            => array(
				'label'    => __( 'Post title', 'wpseokit' ),
				'contexts' => array( 'post' ),
			),
			'sitename'         => array(
				'label'    => __( 'Site title', 'wpseokit' ),
				'contexts' => array( 'all' ),
			),
			'sitedesc'         => array(
				'label'    => __( 'Tagline', 'wpseokit' ),
				'contexts' => array( 'all' ),
			),
			'sep'              => array(
				'label'    => __( 'Separator', 'wpseokit' ),
				'contexts' => array( 'all' ),
			),
			'page'             => array(
				'label'    => __( 'Page number (empty on the first page)', 'wpseokit' ),
				'contexts' => array( 'all' ),
			),
			'term_title'       => array(
				'label'    => __( 'Term name', 'wpseokit' ),
				'contexts' => array( 'term' ),
			),
			'searchphrase'     => array(
				'label'    => __( 'Search phrase', 'wpseokit' ),
				'contexts' => array( 'search' ),
			),
			'name'             => array(
				'label'    => __( 'Author name', 'wpseokit' ),
				'contexts' => array( 'post', 'author' ),
			),
			'date'             => array(
				'label'    => __( 'Date', 'wpseokit' ),
				'contexts' => array( 'post', 'date' ),
			),
			'excerpt'          => array(
				'label'    => __( 'Excerpt', 'wpseokit' ),
				'contexts' => array( 'post' ),
			),
			'category'         => array(
				'label'    => __( 'Categories', 'wpseokit' ),
				'contexts' => array( 'post' ),
			),
			'primary_category' => array(
				'label'    => __( 'Primary category', 'wpseokit' ),
				'contexts' => array( 'post' ),
			),
		);
	}

	/**
	 * Variable names in a template that WPSeoKit does not know.
	 *
	 * @return string[]
	 */
	public static function unknown( string $template ): array {
		preg_match_all( '/%([a-z_]+)%/', $template, $found );
		return array_values( array_unique( array_diff( $found[1], array_keys( self::names() ) ) ) );
	}

	/** Yoast syntax `%%title%%` -> `%title%`. A name WPSeoKit does not know becomes `%name%` (blank when rendered, listed by unknown()). */
	public static function from_yoast( string $template ): string {
		return (string) preg_replace_callback(
			'/%%([a-z_]+)%%/',
			static function ( array $m ): string {
				return '%' . ( self::VAR_MAP[ $m[1] ] ?? $m[1] ) . '%';
			},
			$template
		);
	}

	/**
	 * Description taken from the post content when no excerpt or description exists.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function auto_excerpt( \WP_Post $post, int $length = self::EXCERPT_LENGTH ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', self::plain( strip_shortcodes( (string) $post->post_content ) ) ) );
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $length );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false === $space ? $cut : mb_substr( $cut, 0, $space ), " ,.;:-\u{2013}" ) . "\u{2026}";
	}

	/** HTML -> plain text: tags removed, entities decoded. */
	public static function plain( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Whether a value still carries an unresolved `%variable%` (3+ letters, so a percent-encoded `%ab%cd` never matches).
	 * Output layers drop such a field instead of printing the raw template (docs/sozlesme.md §3).
	 */
	public static function has_token( string $value ): bool {
		return 1 === preg_match( '/%[a-z_]{3,}%/', $value );
	}

	/**
	 * Removes every string field with an unresolved token from a nested array (JSON-LD graph). Silent on purpose:
	 * the `wpseokit_unresolved_token` action is the only trace, no log line.
	 *
	 * @param array<mixed> $data Graph node(s).
	 * @return array<mixed>
	 */
	public static function scrub( array $data, string $path = '' ): array {
		foreach ( $data as $key => $value ) {
			$where = '' === $path ? (string) $key : $path . '.' . $key;
			if ( is_array( $value ) ) {
				$data[ $key ] = self::scrub( $value, $where );
			} elseif ( is_string( $value ) && self::has_token( $value ) ) {
				do_action( 'wpseokit_unresolved_token', $where, $value );
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/** The separator from the settings (`-` when empty). */
	public static function separator(): string {
		$sep = trim( (string) SettingsRepository::get( 'templates.sep', '-' ) );
		return '' === $sep ? '-' : $sep;
	}

	/** `%sep%` is swapped for a mark first so cleaning treats a separator typed by the editor and one from `%page%` alike. */
	private static function protect_separator( string $template ): string {
		return str_replace( '%sep%', self::SEP_MARK, $template );
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function resolve( string $name, array $ctx ): string {
		$post = ( isset( $ctx['post'] ) && $ctx['post'] instanceof \WP_Post ) ? $ctx['post'] : null;
		$term = ( isset( $ctx['term'] ) && $ctx['term'] instanceof \WP_Term ) ? $ctx['term'] : null;
		$user = ( isset( $ctx['user'] ) && $ctx['user'] instanceof \WP_User ) ? $ctx['user'] : null;

		switch ( $name ) {
			case 'title':
				return $post ? self::plain( (string) $post->post_title ) : '';
			case 'sitename':
				return self::plain( (string) get_option( 'blogname' ) );
			case 'sitedesc':
				return self::plain( (string) get_option( 'blogdescription' ) );
			case 'page':
				return self::page( $ctx );
			case 'term_title':
				return $term ? self::plain( $term->name ) : '';
			case 'searchphrase':
				return isset( $ctx['search_phrase'] ) ? self::plain( (string) $ctx['search_phrase'] ) : '';
			case 'name':
				if ( $user ) {
					return self::plain( $user->display_name );
				}
				return $post ? self::plain( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ) : '';
			case 'date':
				if ( $post ) {
					return mysql2date( (string) get_option( 'date_format' ), $post->post_date );
				}
				return isset( $ctx['date'] ) ? self::plain( (string) $ctx['date'] ) : '';
			case 'excerpt':
				if ( ! $post ) {
					return '';
				}
				return '' !== trim( (string) $post->post_excerpt ) ? self::plain( (string) $post->post_excerpt ) : self::auto_excerpt( $post );
			case 'category':
				return $post ? self::category_names( $post ) : '';
			case 'primary_category':
				$primary = $post ? PrimaryTermService::get_term( $post ) : null;
				return $primary ? self::plain( $primary->name ) : '';
		}
		return '';
	}

	/** "- Page 2 of 6": empty on the first page and when the listing has a single page. */
	private static function page( array $ctx ): string {
		$paged = (int) ( $ctx['paged'] ?? 1 );
		$max   = (int) ( $ctx['max_pages'] ?? 1 );
		if ( $paged < 2 || $max < 2 ) {
			return '';
		}
		/* translators: 1: current page number, 2: number of pages. */
		return self::SEP_MARK . ' ' . sprintf( __( 'Page %1$d of %2$d', 'wpseokit' ), $paged, $max );
	}

	private static function category_names( \WP_Post $post ): string {
		$terms = get_the_terms( $post, 'category' );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		return implode( ', ', array_map( static fn( $t ) => self::plain( $t->name ), $terms ) );
	}
}
