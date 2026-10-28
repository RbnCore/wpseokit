<?php
/**
 * Open Graph and Twitter Card tags.
 *
 * Shape follows what Yoast prints (so social previews and parsers see the same page):
 * og:type is `website` on the home page and `article` elsewhere; the 404 page prints only locale, title and site name;
 * `og:url` has no page number; `article:*` times only where they apply; Twitter repeats nothing Open Graph already
 * says (title, description and image are left out), it adds the card type and the author / reading-time labels.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class SocialService {

	public const META_OG_TITLE       = '_wpseokit_og_title';
	public const META_OG_DESCRIPTION = '_wpseokit_og_description';
	public const META_OG_IMAGE       = '_wpseokit_og_image';
	public const TERM_OG_IMAGE       = 'wpseokit_og_image';

	/** Words a reader gets through in a minute; fitted to the 328 reading times Yoast stored on this site (297 equal). */
	private const WORDS_PER_MINUTE = 161;

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'render' ), 3 );
	}

	public static function render(): void {
		$ctx = MetaService::context();
		if ( SettingsRepository::get( 'social.opengraph', true ) ) {
			self::print_tags( self::open_graph( $ctx ) );
		}
		$post = $ctx['post'] ?? null;
		if ( $post instanceof \WP_Post && 'post' === $post->post_type && empty( $ctx['front'] ) ) {
			echo "\t" . '<meta name="author" content="' . esc_attr( Variables::plain( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ) ) . '" />' . "\n";
		}
		if ( SettingsRepository::get( 'social.twitter', true ) && 'notfound' !== $ctx['type'] ) {
			self::print_tags( self::twitter( $ctx ) );
		}
	}

	/** Reading time in whole minutes (at least 1). */
	public static function reading_minutes( \WP_Post $post ): int {
		$text  = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$words = count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
		return max( 1, (int) ceil( $words / self::WORDS_PER_MINUTE ) );
	}

	/**
	 * Open Graph tags as `[ attribute, name, content ]` rows, in print order.
	 *
	 * @param array<string, mixed> $ctx Context from MetaService::context().
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function open_graph( array $ctx ): array {
		$post = $ctx['post'] ?? null;
		$rows = array( array( 'property', 'og:locale', (string) get_locale() ) );

		if ( 'notfound' !== $ctx['type'] ) {
			$rows[] = array( 'property', 'og:type', ! empty( $ctx['front'] ) ? 'website' : 'article' );
		}
		$custom_title = $post instanceof \WP_Post ? trim( (string) get_post_meta( $post->ID, self::META_OG_TITLE, true ) ) : '';
		$rows[]       = array( 'property', 'og:title', '' !== $custom_title ? Variables::replace( $custom_title, $ctx ) : MetaService::title( $ctx ) );

		if ( 'notfound' !== $ctx['type'] ) {
			$custom_desc = $post instanceof \WP_Post ? trim( (string) get_post_meta( $post->ID, self::META_OG_DESCRIPTION, true ) ) : '';
			$description = '' !== $custom_desc ? Variables::replace( $custom_desc, $ctx ) : MetaService::description( $ctx );
			if ( '' !== $description ) {
				$rows[] = array( 'property', 'og:description', $description );
			}
			$url = CanonicalService::base_url( $ctx );
			if ( '' !== $url ) {
				$rows[] = array( 'property', 'og:url', $url );
			}
		}
		$rows[] = array( 'property', 'og:site_name', Variables::plain( (string) get_option( 'blogname' ) ) );

		$facebook = trim( (string) SettingsRepository::get( 'social.facebook_site', '' ) );
		if ( $post instanceof \WP_Post && '' !== $facebook && 'post' === $post->post_type ) {
			$rows[] = array( 'property', 'article:publisher', $facebook );
		}
		if ( $post instanceof \WP_Post ) {
			if ( 'post' === $post->post_type ) {
				$rows[] = array( 'property', 'article:published_time', gmdate( 'c', (int) strtotime( $post->post_date_gmt ) ) );
			}
			if ( $post->post_modified_gmt !== $post->post_date_gmt ) {
				$rows[] = array( 'property', 'article:modified_time', gmdate( 'c', (int) strtotime( $post->post_modified_gmt ) ) );
			}
		}
		foreach ( self::image( $ctx ) as $name => $value ) {
			$rows[] = array( 'property', $name, (string) $value );
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $ctx Context from MetaService::context().
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function twitter( array $ctx ): array {
		$post = $ctx['post'] ?? null;
		$rows = array( array( 'name', 'twitter:card', (string) SettingsRepository::get( 'social.twitter_card_type', 'summary_large_image' ) ) );

		/* Yoast prints twitter:title on the search page only, where it differs from nothing: kept for parity. */
		if ( 'search' === $ctx['type'] ) {
			$rows[] = array( 'name', 'twitter:title', MetaService::title( $ctx ) );
		}
		$site = trim( (string) SettingsRepository::get( 'social.twitter_site', '' ) );
		if ( '' !== $site ) {
			$rows[] = array( 'name', 'twitter:site', '@' . ltrim( $site, '@' ) );
		}
		if ( $post instanceof \WP_Post && empty( $ctx['front'] ) ) {
			$labels = array();
			if ( 'post' === $post->post_type ) {
				$labels[] = array( __( 'Written by', 'wpseokit' ), Variables::plain( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ) );
			}
			$minutes  = self::reading_minutes( $post );
			$labels[] = array( __( 'Estimated reading time', 'wpseokit' ), sprintf( _n( '%d minute', '%d minutes', $minutes, 'wpseokit' ), $minutes ) );
			foreach ( $labels as $i => $pair ) {
				$rows[] = array( 'name', 'twitter:label' . ( $i + 1 ), $pair[0] );
				$rows[] = array( 'name', 'twitter:data' . ( $i + 1 ), $pair[1] );
			}
		}
		return $rows;
	}

	/**
	 * Image tags for the page: field -> featured image -> first image in the content -> default image.
	 *
	 * @param array<string, mixed> $ctx Context from MetaService::context().
	 * @return array<string, string|int> Tag name => value (empty when the page has no image).
	 */
	public static function image( array $ctx ): array {
		$post = $ctx['post'] ?? null;
		$term = $ctx['term'] ?? null;
		$spec = '';
		if ( $post instanceof \WP_Post ) {
			$spec = trim( (string) get_post_meta( $post->ID, self::META_OG_IMAGE, true ) );
			if ( '' === $spec && has_post_thumbnail( $post ) ) {
				$spec = (string) get_post_thumbnail_id( $post );
			}
			if ( '' === $spec && preg_match( '/wp-image-(\d+)/', (string) $post->post_content, $m ) ) {
				$spec = $m[1];
			}
		} elseif ( $term instanceof \WP_Term ) {
			$spec = trim( (string) get_term_meta( $term->term_id, self::TERM_OG_IMAGE, true ) );
		}
		$image = self::describe_image( $spec );
		if ( array() === $image ) {
			$default_id = (int) SettingsRepository::get( 'social.default_image_id', 0 );
			$image      = self::describe_image( $default_id ? (string) $default_id : trim( (string) SettingsRepository::get( 'social.default_image_url', '' ) ) );
		}
		return $image;
	}

	/**
	 * @param string $spec Attachment ID or image URL.
	 * @return array<string, string|int>
	 */
	private static function describe_image( string $spec ): array {
		if ( '' === $spec ) {
			return array();
		}
		if ( ctype_digit( $spec ) ) {
			$src = wp_get_attachment_image_src( (int) $spec, 'full' );
			if ( ! $src ) {
				return array();
			}
			$out = array(
				'og:image'        => $src[0],
				'og:image:width'  => (int) $src[1],
				'og:image:height' => (int) $src[2],
			);
			$mime = (string) get_post_mime_type( (int) $spec );
			if ( '' !== $mime ) {
				$out['og:image:type'] = $mime;
			}
			return $out;
		}
		return false !== wp_http_validate_url( $spec ) ? array( 'og:image' => $spec ) : array();
	}

	/**
	 * @param array<int, array{0: string, 1: string, 2: string}> $rows Tag rows.
	 */
	private static function print_tags( array $rows ): void {
		foreach ( $rows as $row ) {
			if ( '' === $row[2] || Variables::has_token( $row[2] ) ) {
				continue;
			}
			$value = in_array( $row[1], array( 'og:url', 'og:image' ), true ) ? esc_url( $row[2] ) : esc_attr( $row[2] );
			echo "\t" . '<meta ' . $row[0] . '="' . esc_attr( $row[1] ) . '" content="' . $value . '" />' . "\n";
		}
	}
}
