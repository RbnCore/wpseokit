<?php
/**
 * Page title and meta description, the request context every other head service reads, and the
 * `/variables` and `/preview` REST routes.
 *
 * Head output is wrapped in one comment block (open: MetaService p1, close: MetaService p99):
 * `<title>`, meta description, site verification tags; CanonicalService and SocialService print inside it.
 *
 * Title order: per-post / per-term field -> template for the post type / taxonomy -> site template.
 * Description order (posts): field -> template -> excerpt -> start of the content.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class MetaService {

	public const META_TITLE       = '_wpseokit_title';
	public const META_DESCRIPTION = '_wpseokit_description';
	public const TERM_TITLE       = 'wpseokit_title';
	public const TERM_DESCRIPTION = 'wpseokit_description';

	private const FALLBACK_POST_TITLE = '%title% %page% %sep% %sitename%';
	private const FALLBACK_TERM_TITLE = '%term_title% %page% %sep% %sitename%';

	public static function init(): void {
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		add_filter( 'pre_get_document_title', array( self::class, 'filter_document_title' ), 20 );
		add_action( 'wp_head', array( self::class, 'open_block' ), 1 );
		add_action( 'wp_head', array( self::class, 'render_head' ), 1 );
		add_action( 'wp_head', array( self::class, 'close_block' ), 99 );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
	}

	/**
	 * What is being viewed. Contract: docs/sozlesme.md §3.1 plus `front` (static front page) and `object`.
	 *
	 * @return array<string, mixed>
	 */
	public static function context(): array {
		$paged     = max( 1, (int) get_query_var( 'paged' ) );
		$max_pages = max( 1, (int) $GLOBALS['wp_query']->max_num_pages );
		$ctx       = array(
			'type'      => 'other',
			'front'     => false,
			'paged'     => $paged,
			'max_pages' => $max_pages,
		);

		if ( is_404() ) {
			$ctx['type'] = 'notfound';
		} elseif ( is_search() ) {
			$ctx['type']          = 'search';
			$ctx['search_phrase'] = (string) get_search_query( false );
		} elseif ( is_front_page() && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) ) {
			$ctx          = self::singular_context( $ctx, get_post( (int) get_option( 'page_on_front' ) ) );
			$ctx['front'] = true;
			/* `/page/2/` of a static front page: the page number is kept for the canonical URL, there is still one page of content. */
			$ctx['paged'] = max( $paged, (int) get_query_var( 'page' ) );
		} elseif ( is_home() && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_for_posts' ) ) {
			$ctx          = self::singular_context( $ctx, get_post( (int) get_option( 'page_for_posts' ) ) );
			$ctx['paged'] = $paged;
		} elseif ( is_front_page() || is_home() ) {
			$ctx['type']  = 'home';
			$ctx['front'] = true;
		} elseif ( is_singular() ) {
			$ctx = self::singular_context( $ctx, get_queried_object() );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$ctx['type'] = 'term';
				$ctx['term'] = $term;
			}
		} elseif ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof \WP_User ) {
				$ctx['type'] = 'author';
				$ctx['user'] = $user;
			}
		} elseif ( is_date() ) {
			$ctx['type'] = 'date';
			$ctx['date'] = self::archive_date();
		}
		return $ctx;
	}

	/** Resolved `<title>` text for a context (no HTML escaping). */
	public static function title( array $ctx ): string {
		$template = self::title_template( $ctx );
		$title    = Variables::replace( $template, $ctx );
		return '' !== $title && ! Variables::has_token( $title ) ? $title : Variables::plain( (string) get_option( 'blogname' ) );
	}

	/** Resolved meta description for a context (no HTML escaping). Empty when the page has none. */
	public static function description( array $ctx ): string {
		$post = $ctx['post'] ?? null;
		$term = $ctx['term'] ?? null;

		if ( $post instanceof \WP_Post ) {
			$custom = trim( (string) get_post_meta( $post->ID, self::META_DESCRIPTION, true ) );
			if ( '' === $custom && ! empty( $ctx['front'] ) ) {
				$custom = trim( (string) SettingsRepository::get( 'templates.home_description', '' ) );
			}
			if ( '' === $custom ) {
				$custom = trim( self::template( 'post_types', $post->post_type, 'description', '' ) );
			}
			$text = '' !== $custom ? Variables::replace( $custom, $ctx ) : '';
			if ( '' === $text ) {
				$text = '' !== trim( (string) $post->post_excerpt ) ? Variables::plain( (string) $post->post_excerpt ) : Variables::auto_excerpt( $post );
			}
			return self::one_line( $text );
		}
		if ( $term instanceof \WP_Term ) {
			$custom = trim( (string) get_term_meta( $term->term_id, self::TERM_DESCRIPTION, true ) );
			if ( '' === $custom ) {
				$custom = trim( self::template( 'taxonomies', $term->taxonomy, 'description', '' ) );
			}
			return self::one_line( Variables::replace( $custom, $ctx ) );
		}
		if ( 'home' === $ctx['type'] ) {
			return self::one_line( Variables::replace( (string) SettingsRepository::get( 'templates.home_description', '' ), $ctx ) );
		}
		return '';
	}

	/** `pre_get_document_title`: keeps `wp_get_document_title()` (themes, REST, other plugins) in line with the page head. */
	public static function filter_document_title( $title ) {
		return self::title( self::context() );
	}

	public static function open_block(): void {
		/* The version is part of the comment on purpose: it shows in "view source" which plugin built the head. */
		echo "\n\t<!-- SEO by WPSeoKit v" . esc_html( WPSEOKIT_VERSION ) . " -->\n";
	}

	public static function close_block(): void {
		echo "\t<!-- / WPSeoKit -->\n\n";
	}

	public static function render_head(): void {
		$ctx = self::context();
		echo "\t<title>" . esc_html( self::title( $ctx ) ) . "</title>\n";
		$description = self::description( $ctx );
		if ( '' !== $description && ! Variables::has_token( $description ) ) {
			echo "\t" . '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		$codes = array(
			'google-site-verification' => 'verify_google',
			'msvalidate.01'            => 'verify_bing',
			'yandex-verification'      => 'verify_yandex',
			'baidu-site-verification'  => 'verify_baidu',
		);
		foreach ( $codes as $name => $key ) {
			$code = trim( (string) SettingsRepository::get( 'general.' . $key, '' ) );
			if ( '' !== $code ) {
				echo "\t" . '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $code ) . '" />' . "\n";
			}
		}
	}

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/variables',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => new \WP_REST_Response( array( 'names' => Variables::names() ) ),
				'permission_callback' => Rest::permission(),
			)
		);
		register_rest_route(
			$namespace,
			'/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_preview' ),
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					return $request['post_id'] ? Rest::can_edit_post( $request ) : Rest::can_manage();
				},
				'args'                => array(
					'post_id'     => array( 'type' => 'integer' ),
					'term_id'     => array( 'type' => 'integer' ),
					'title'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_preview( \WP_REST_Request $request ) {
		$ctx = array(
			'type'      => 'post',
			'paged'     => 1,
			'max_pages' => 1,
		);
		$url = home_url( '/' );
		if ( $request['post_id'] ) {
			$post = get_post( (int) $request['post_id'] );
			if ( ! $post ) {
				return new \WP_Error( 'wpseokit_not_found', __( 'Post not found.', 'wpseokit' ), array( 'status' => 404 ) );
			}
			$ctx['post'] = $post;
			$url         = (string) get_permalink( $post );
		} elseif ( $request['term_id'] ) {
			$term = get_term( (int) $request['term_id'] );
			if ( ! $term instanceof \WP_Term ) {
				return new \WP_Error( 'wpseokit_not_found', __( 'Term not found.', 'wpseokit' ), array( 'status' => 404 ) );
			}
			$ctx['type'] = 'term';
			$ctx['term'] = $term;
			$url         = (string) get_term_link( $term );
		}

		$title_template = null !== $request['title'] && '' !== trim( (string) $request['title'] ) ? (string) $request['title'] : self::title_template( $ctx );
		$title          = Variables::replace( $title_template, $ctx );
		if ( null !== $request['description'] && '' !== trim( (string) $request['description'] ) ) {
			$description = self::one_line( Variables::replace( (string) $request['description'], $ctx ) );
		} else {
			$description = self::description( $ctx );
		}
		return new \WP_REST_Response(
			array(
				'title'              => $title,
				'description'        => $description,
				'title_length'       => mb_strlen( $title ),
				'description_length' => mb_strlen( $description ),
				'url_display'        => preg_replace( '#^https?://#', '', $url ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function title_template( array $ctx ): string {
		$post = $ctx['post'] ?? null;
		$term = $ctx['term'] ?? null;

		if ( $post instanceof \WP_Post ) {
			$custom = trim( (string) get_post_meta( $post->ID, self::META_TITLE, true ) );
			if ( '' !== $custom ) {
				return $custom;
			}
			if ( ! empty( $ctx['front'] ) ) {
				return (string) SettingsRepository::get( 'templates.home_title', '' );
			}
			return self::template( 'post_types', $post->post_type, 'title', self::FALLBACK_POST_TITLE );
		}
		if ( $term instanceof \WP_Term ) {
			$custom = trim( (string) get_term_meta( $term->term_id, self::TERM_TITLE, true ) );
			if ( '' !== $custom ) {
				return $custom;
			}
			return self::template( 'taxonomies', $term->taxonomy, 'title', self::FALLBACK_TERM_TITLE );
		}
		$map = array(
			'home'     => 'home_title',
			'search'   => 'search_title',
			'notfound' => 'notfound_title',
			'date'     => 'date_title',
			'author'   => 'author_title',
		);
		if ( isset( $map[ $ctx['type'] ] ) ) {
			return (string) SettingsRepository::get( 'templates.' . $map[ $ctx['type'] ], '' );
		}
		return '%sitename%';
	}

	/**
	 * @param array<string, mixed> $ctx  Context so far.
	 * @param mixed                $post Queried post.
	 * @return array<string, mixed>
	 */
	private static function singular_context( array $ctx, $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $ctx;
		}
		$ctx['type'] = 'post';
		$ctx['post'] = $post;
		/* A post split with <!--nextpage--> pages by `page`, not `paged`. */
		$pages = substr_count( $post->post_content, '<!--nextpage-->' ) + 1;
		if ( $pages > 1 ) {
			$ctx['paged']     = max( 1, (int) get_query_var( 'page' ) );
			$ctx['max_pages'] = $pages;
		} else {
			$ctx['paged']     = 1;
			$ctx['max_pages'] = 1;
		}
		return $ctx;
	}

	private static function archive_date(): string {
		$year  = (int) get_query_var( 'year' );
		$month = (int) get_query_var( 'monthnum' );
		$day   = (int) get_query_var( 'day' );
		if ( 0 === $year && '' !== (string) get_query_var( 'm' ) ) {
			$m     = (string) get_query_var( 'm' );
			$year  = (int) substr( $m, 0, 4 );
			$month = (int) substr( $m, 4, 2 );
			$day   = (int) substr( $m, 6, 2 );
		}
		$stamp = sprintf( '%04d-%02d-%02d 00:00:00', max( 1, $year ), max( 1, $month ), max( 1, $day ) );
		if ( $day > 0 ) {
			return mysql2date( (string) get_option( 'date_format' ), $stamp );
		}
		return mysql2date( $month > 0 ? 'F Y' : 'Y', $stamp );
	}

	/** A row of the per-post-type / per-taxonomy template map (`SettingsRepository::get()` reads one level only). */
	private static function template( string $map, string $name, string $field, string $default ): string {
		$rows = (array) SettingsRepository::get( 'templates.' . $map, array() );
		return isset( $rows[ $name ][ $field ] ) ? (string) $rows[ $name ][ $field ] : $default;
	}

	private static function one_line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
