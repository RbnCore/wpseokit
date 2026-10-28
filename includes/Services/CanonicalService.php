<?php
/**
 * Canonical URL, plus the two redirects that keep duplicate pages out of the index
 * (author archive and attachment page, both switched on in `general.*`).
 *
 * Like Yoast, no canonical is printed while search engines are discouraged (`blog_public = 0`) and on 404 pages.
 * Paginated archives and split posts canonicalise to their own page (`/page/2/`, `/2/`); there is no rel prev/next.
 * A post can override the URL with the `_wpseokit_canonical` field.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CanonicalService {

	public const META_KEY = '_wpseokit_canonical';

	public static function init(): void {
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( self::class, 'render' ), 2 );
		add_action( 'template_redirect', array( self::class, 'redirect_duplicates' ), 5 );
	}

	public static function render(): void {
		if ( ! get_option( 'blog_public' ) ) {
			return;
		}
		$url = self::url( MetaService::context() );
		if ( '' !== $url ) {
			echo "\t" . '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	/**
	 * Canonical URL of the page, with its page number. Empty when the page has none.
	 *
	 * @param array<string, mixed> $ctx Context from MetaService::context().
	 */
	public static function url( array $ctx ): string {
		$post = $ctx['post'] ?? null;
		if ( $post instanceof \WP_Post ) {
			$custom = trim( (string) get_post_meta( $post->ID, self::META_KEY, true ) );
			if ( '' !== $custom && false !== wp_http_validate_url( $custom ) ) {
				return $custom;
			}
		}
		$base = self::base_url( $ctx );
		if ( '' === $base ) {
			return '';
		}
		$paged = (int) $ctx['paged'];
		if ( $paged < 2 ) {
			return $base;
		}
		if ( $post instanceof \WP_Post && empty( $ctx['front'] ) ) {
			return get_option( 'permalink_structure' ) ? trailingslashit( $base ) . user_trailingslashit( (string) $paged, 'single_paged' ) : add_query_arg( 'page', $paged, $base );
		}
		return (string) get_pagenum_link( $paged, false );
	}

	/**
	 * URL of the page without its page number: what `og:url` uses.
	 *
	 * @param array<string, mixed> $ctx Context from MetaService::context().
	 */
	public static function base_url( array $ctx ): string {
		$post = $ctx['post'] ?? null;
		$term = $ctx['term'] ?? null;
		$user = $ctx['user'] ?? null;

		if ( ! empty( $ctx['front'] ) ) {
			return home_url( '/' );
		}
		if ( $post instanceof \WP_Post ) {
			return (string) get_permalink( $post );
		}
		if ( $term instanceof \WP_Term ) {
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? '' : $link;
		}
		if ( $user instanceof \WP_User ) {
			return get_author_posts_url( $user->ID );
		}
		switch ( $ctx['type'] ) {
			case 'search':
				return get_search_link();
			case 'date':
				return (string) get_pagenum_link( 1, false );
			case 'other':
				$type = get_query_var( 'post_type' );
				$type = is_array( $type ) ? reset( $type ) : $type;
				return $type ? (string) get_post_type_archive_link( (string) $type ) : '';
		}
		return '';
	}

	/** 301 for the author archive and attachment pages when the settings turn them off. */
	public static function redirect_duplicates(): void {
		if ( is_author() && SettingsRepository::get( 'general.disable_author_archive', false ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
		if ( is_attachment() && SettingsRepository::get( 'general.disable_attachment_pages', true ) ) {
			$parent = (int) wp_get_post_parent_id( get_queried_object_id() );
			wp_safe_redirect( $parent ? (string) get_permalink( $parent ) : home_url( '/' ), 301 );
			exit;
		}
	}
}
