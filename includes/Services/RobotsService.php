<?php
/**
 * Robots meta tag, through core's `wp_robots` filter.
 *
 * - "Discourage search engines" (`blog_public = 0`): core's `noindex, nofollow` is left alone.
 * - Otherwise: indexable pages get `index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1`;
 *   noindex pages get `noindex, follow`. Paginated archives stay indexable.
 * - Always noindex: search results and 404. By setting: taxonomies in `robots.noindex_taxonomies`, post types in
 *   `robots.noindex_post_types`, date archives, authors without posts.
 * - Per-post / per-term field (`_wpseokit_robots` / `wpseokit_robots`, tokens like "noindex,follow") overrides the rule.
 * - A `noindex` set by another plugin (for example ContentHealth) is kept, never removed.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class RobotsService {

	public const META_KEY = '_wpseokit_robots';
	public const TERM_KEY = 'wpseokit_robots';

	/** Directives this service owns: everything else another plugin put in the array is kept. */
	private const OWNED = array( 'index', 'noindex', 'follow', 'nofollow', 'max-snippet', 'max-image-preview', 'max-video-preview' );

	public static function init(): void {
		add_filter( 'wp_robots', array( self::class, 'filter_robots' ), 20 );
	}

	/**
	 * @param array<string, string|bool> $robots Directives so far.
	 * @return array<string, string|bool>
	 */
	public static function filter_robots( $robots ) {
		if ( ! is_array( $robots ) || ! get_option( 'blog_public' ) ) {
			return $robots;
		}
		$rule    = self::rule( MetaService::context() );
		$noindex = $rule['noindex'] || ! empty( $robots['noindex'] );

		$out = array(
			$noindex ? 'noindex' : 'index'          => true,
			$rule['nofollow'] ? 'nofollow' : 'follow' => true,
		);
		if ( ! $noindex ) {
			$out['max-snippet']       = '-1';
			$out['max-image-preview'] = 'large';
			$out['max-video-preview'] = '-1';
		}
		foreach ( $robots as $directive => $value ) {
			if ( ! in_array( $directive, self::OWNED, true ) ) {
				$out[ $directive ] = $value;
			}
		}
		return $out;
	}

	/** Whether the page asks search engines not to index it (settings and per-page fields only; ignores `blog_public`). */
	public static function is_noindex( array $ctx ): bool {
		return self::rule( $ctx )['noindex'];
	}

	/**
	 * @param array<string, mixed> $ctx Context from MetaService::context().
	 * @return array{noindex: bool, nofollow: bool}
	 */
	private static function rule( array $ctx ): array {
		$noindex = false;
		$post    = $ctx['post'] ?? null;
		$term    = $ctx['term'] ?? null;
		$user    = $ctx['user'] ?? null;

		switch ( $ctx['type'] ) {
			case 'notfound':
			case 'search':
				$noindex = true;
				break;
			case 'date':
				$noindex = (bool) SettingsRepository::get( 'robots.noindex_date', true );
				break;
			case 'author':
				$noindex = $user instanceof \WP_User
					&& (bool) SettingsRepository::get( 'robots.noindex_author_noposts', true )
					&& 0 === (int) count_user_posts( $user->ID, 'post', true );
				break;
			case 'term':
				$noindex = $term instanceof \WP_Term && in_array( $term->taxonomy, (array) SettingsRepository::get( 'robots.noindex_taxonomies', array() ), true );
				break;
			case 'post':
				$noindex = $post instanceof \WP_Post && in_array( $post->post_type, (array) SettingsRepository::get( 'robots.noindex_post_types', array() ), true );
				break;
		}

		$nofollow = false;
		$field    = '';
		if ( $post instanceof \WP_Post ) {
			$field = (string) get_post_meta( $post->ID, self::META_KEY, true );
		} elseif ( $term instanceof \WP_Term ) {
			$field = (string) get_term_meta( $term->term_id, self::TERM_KEY, true );
		}
		$tokens = preg_split( '/[\s,]+/', strtolower( $field ), -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $tokens ) ) {
			if ( in_array( 'noindex', $tokens, true ) ) {
				$noindex = true;
			} elseif ( in_array( 'index', $tokens, true ) && ! in_array( $ctx['type'], array( 'notfound', 'search' ), true ) ) {
				$noindex = false;
			}
			$nofollow = in_array( 'nofollow', $tokens, true );
		}
		return array(
			'noindex'  => $noindex,
			'nofollow' => $nofollow,
		);
	}
}
