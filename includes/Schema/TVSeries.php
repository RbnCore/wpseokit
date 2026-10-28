<?php
/**
 * TVSeries schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

defined( 'ABSPATH' ) || exit;

final class TVSeries extends Piece {

	public function is_needed(): bool {
		$post = $this->context['post'] ?? null;
		if ( ! $post instanceof \WP_Post && is_singular( 'post' ) ) {
			$post = get_post();
		}
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$schema_type = get_post_meta( $post->ID, '_wpseokit_schema_type', true );
		if ( 'Article' === $schema_type || 'Movie' === $schema_type ) {
			return false;
		}

		$data = Movie::get_schema_data( $post->ID );
		if ( empty( $data ) ) {
			return false;
		}

		if ( 'TVSeries' === $schema_type ) {
			return true;
		}

		return Movie::is_tv_series_post( $post );
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
		$id_ref   = rtrim( (string) $url_base, '/' ) . '/#tvseries';
		$raw_data = Movie::get_schema_data( $post->ID );

		$name = ! empty( $raw_data['name'] ) ? (string) $raw_data['name'] : get_the_title( $post );
		$desc = ! empty( $raw_data['description'] ) ? (string) $raw_data['description'] : wp_strip_all_tags( get_the_excerpt( $post ) );

		$series = array(
			'@type' => 'TVSeries',
			'@id'   => $id_ref,
			'name'  => $name,
			'url'   => (string) $url_base,
		);

		if ( '' !== $desc ) {
			$series['description'] = $desc;
		}

		if ( ! empty( $this->context['has_image'] ) || has_post_thumbnail( $post ) ) {
			$series['image'] = array(
				'@id' => rtrim( (string) $url_base, '/' ) . '/#primaryimage',
			);
		}

		if ( ! empty( $raw_data['datePublished'] ) ) {
			$series['datePublished'] = (string) $raw_data['datePublished'];
		}

		$seasons = $raw_data['numberOfSeasons'] ?? ( $raw_data['seasons'] ?? null );
		if ( null !== $seasons && (int) $seasons > 0 ) {
			$series['numberOfSeasons'] = (int) $seasons;
		}

		$episodes = $raw_data['numberOfEpisodes'] ?? ( $raw_data['episodes'] ?? null );
		if ( null !== $episodes && (int) $episodes > 0 ) {
			$series['numberOfEpisodes'] = (int) $episodes;
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
				$series['director'] = $dir_list;
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
				$series['actor'] = $act_list;
			}
		}

		if ( ! empty( $raw_data['genre'] ) ) {
			$genres = is_array( $raw_data['genre'] ) ? $raw_data['genre'] : array( $raw_data['genre'] );
			$series['genre'] = array_values( array_filter( array_map( 'strval', $genres ) ) );
		} else {
			$cats = wp_get_post_categories( (int) $post->ID, array( 'fields' => 'names' ) );
			if ( is_array( $cats ) && array() !== $cats ) {
				$series['genre'] = $cats;
			}
		}

		return $series;
	}
}
