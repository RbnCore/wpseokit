<?php
/**
 * Movie schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class Movie extends Piece {

	public function is_needed(): bool {
		$post = $this->context['post'] ?? null;
		if ( ! $post instanceof \WP_Post && is_singular( 'post' ) ) {
			$post = get_post();
		}
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$data = self::get_schema_data( $post->ID );
		if ( empty( $data ) ) {
			return false;
		}

		return 'Movie' === self::resolve_post_schema_type( $post );
	}

	public function generate(): ?array {
		$post = $this->context['post'] ?? null;
		if ( ! $post instanceof \WP_Post && is_singular( 'post' ) ) {
			$post = get_post();
		}
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$url_base = $this->context['url'] ?? get_permalink( $post );
		$id_ref   = rtrim( (string) $url_base, '/' ) . '/#movie';
		$raw_data = self::get_schema_data( $post->ID );

		$name = ! empty( $raw_data['name'] ) ? (string) $raw_data['name'] : get_the_title( $post );
		$desc = ! empty( $raw_data['description'] ) ? (string) $raw_data['description'] : wp_strip_all_tags( get_the_excerpt( $post ) );

		$movie = array(
			'@type' => 'Movie',
			'@id'   => $id_ref,
			'name'  => $name,
			'url'   => (string) $url_base,
		);

		if ( '' !== $desc ) {
			$movie['description'] = $desc;
		}

		if ( ! empty( $this->context['has_image'] ) || has_post_thumbnail( $post ) ) {
			$movie['image'] = array(
				'@id' => rtrim( (string) $url_base, '/' ) . '/#primaryimage',
			);
		}

		if ( ! empty( $raw_data['datePublished'] ) ) {
			$movie['datePublished'] = (string) $raw_data['datePublished'];
		}

		if ( ! empty( $raw_data['duration'] ) ) {
			$dur = (string) $raw_data['duration'];
			if ( 1 === preg_match( '/^\d+$/', $dur ) ) {
				$dur = 'PT' . $dur . 'M';
			}
			$movie['duration'] = $dur;
		}

		if ( ! empty( $raw_data['director'] ) ) {
			$directors = is_array( $raw_data['director'] ) ? $raw_data['director'] : array( $raw_data['director'] );
			$dir_list  = array();
			foreach ( $directors as $d ) {
				if ( is_string( $d ) && '' !== trim( $d ) ) {
					$dir_list[] = array(
						'@type' => 'Person',
						'name'  => trim( $d ),
					);
				}
			}
			if ( array() !== $dir_list ) {
				$movie['director'] = $dir_list;
			}
		}

		if ( ! empty( $raw_data['actors'] ) || ! empty( $raw_data['actor'] ) ) {
			$actors   = $raw_data['actors'] ?? $raw_data['actor'];
			$act_arr  = is_array( $actors ) ? $actors : array( $actors );
			$act_list = array();
			foreach ( $act_arr as $a ) {
				if ( is_string( $a ) && '' !== trim( $a ) ) {
					$act_list[] = array(
						'@type' => 'Person',
						'name'  => trim( $a ),
					);
				}
			}
			if ( array() !== $act_list ) {
				$movie['actor'] = $act_list;
			}
		}

		if ( ! empty( $raw_data['genre'] ) ) {
			$genres = is_array( $raw_data['genre'] ) ? $raw_data['genre'] : array( $raw_data['genre'] );
			$movie['genre'] = array_values( array_filter( array_map( 'strval', $genres ) ) );
		} else {
			$cats = wp_get_post_categories( (int) $post->ID, array( 'fields' => 'names' ) );
			if ( is_array( $cats ) && array() !== $cats ) {
				$movie['genre'] = $cats;
			}
		}

		return $movie;
	}

	/**
	 * Read schema metadata array from post meta.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_schema_data( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_wpseokit_schema_data', true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Resolve effective schema type for a post: 'Movie', 'TVSeries', or 'Article'.
	 *
	 * Post meta `_wpseokit_schema_type` takes highest precedence when set to 'Movie', 'TVSeries', or 'Article'.
	 * When 'auto' or empty, `schema.type_map` (term_id => 'Movie'|'TVSeries') is checked against the post's categories.
	 * If no mapping matches, returns 'Article'.
	 */
	public static function resolve_post_schema_type( \WP_Post $post ): string {
		$meta = (string) get_post_meta( $post->ID, '_wpseokit_schema_type', true );
		if ( in_array( $meta, array( 'Movie', 'TVSeries', 'Article' ), true ) ) {
			return $meta;
		}

		$type_map = SettingsRepository::get( 'schema.type_map', array() );
		if ( ! is_array( $type_map ) || array() === $type_map ) {
			return 'Article';
		}

		// Check primary category first if present.
		$primary = (int) get_post_meta( $post->ID, '_wpseokit_primary_category', true );
		if ( $primary > 0 ) {
			$matched = $type_map[ $primary ] ?? ( $type_map[ (string) $primary ] ?? null );
			if ( is_string( $matched ) && in_array( $matched, array( 'Movie', 'TVSeries' ), true ) ) {
				return $matched;
			}
		}

		$cats = wp_get_post_categories( (int) $post->ID );
		if ( is_array( $cats ) ) {
			foreach ( $cats as $cat_id ) {
				$cat_id  = (int) $cat_id;
				$matched = $type_map[ $cat_id ] ?? ( $type_map[ (string) $cat_id ] ?? null );
				if ( is_string( $matched ) && in_array( $matched, array( 'Movie', 'TVSeries' ), true ) ) {
					return $matched;
				}
			}
		}

		return 'Article';
	}
}
