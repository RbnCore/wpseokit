<?php
/**
 * Movie schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

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

		$schema_type = get_post_meta( $post->ID, '_wpseokit_schema_type', true );
		if ( 'Article' === $schema_type ) {
			return false;
		}

		$data = self::get_schema_data( $post->ID );
		if ( empty( $data ) ) {
			return false;
		}

		if ( 'Movie' === $schema_type ) {
			return true;
		}

		if ( 'TVSeries' === $schema_type ) {
			return false;
		}

		// Auto category mapping: "Yabancı Dizi" / "Dizi" -> TVSeries, others -> Movie.
		return ! self::is_tv_series_post( $post );
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
	 * Check whether a post belongs to a TV series category.
	 */
	public static function is_tv_series_post( \WP_Post $post ): bool {
		$terms = get_the_terms( $post->ID, 'category' );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$name = mb_strtolower( (string) $term->name );
			$slug = (string) $term->slug;
			if ( 'yabancı dizi' === $name || 'dizi' === $name || 'yabanci-dizi' === $slug || 'dizi' === $slug ) {
				return true;
			}
		}
		return false;
	}
}
