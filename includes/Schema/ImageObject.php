<?php
/**
 * ImageObject schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

defined( 'ABSPATH' ) || exit;

final class ImageObject extends Piece {

	public function is_needed(): bool {
		$image = $this->get_image_data();
		return ! empty( $image['url'] );
	}

	public function generate(): ?array {
		$image = $this->get_image_data();
		if ( empty( $image['url'] ) ) {
			return null;
		}

		$locale = get_locale();
		$lang   = strtolower( (string) substr( $locale, 0, 2 ) );
		if ( '' === $lang ) {
			$lang = 'tr';
		}

		$url_base = $this->context['url'] ?? '';
		$is_search = ! empty( $this->context['is_search'] ) || is_search();
		$id_ref    = $is_search ? '#primaryimage' : ( rtrim( $url_base, '/' ) . '/#primaryimage' );

		$data = array(
			'@type'      => 'ImageObject',
			'inLanguage' => $lang,
			'@id'        => $id_ref,
			'url'        => $image['url'],
			'contentUrl' => $image['url'],
		);

		if ( ! empty( $image['width'] ) && ! empty( $image['height'] ) ) {
			$data['width']  = (int) $image['width'];
			$data['height'] = (int) $image['height'];
		}

		return $data;
	}

	/**
	 * Retrieve primary image for current context.
	 *
	 * @return array{url: string, width: int, height: int}
	 */
	public function get_image_data(): array {
		if ( isset( $this->context['image_url'] ) && '' !== $this->context['image_url'] ) {
			return array(
				'url'    => (string) $this->context['image_url'],
				'width'  => (int) ( $this->context['image_width'] ?? 0 ),
				'height' => (int) ( $this->context['image_height'] ?? 0 ),
			);
		}

		$post = $this->context['post'] ?? null;
		if ( ! $post instanceof \WP_Post && is_singular() ) {
			$post = get_post();
		}

		if ( $post instanceof \WP_Post ) {
			$thumb_id = get_post_thumbnail_id( $post->ID );
			if ( $thumb_id > 0 ) {
				$src  = wp_get_attachment_image_url( $thumb_id, 'full' );
				$meta = wp_get_attachment_metadata( $thumb_id );
				if ( $src ) {
					return array(
						'url'    => $src,
						'width'  => (int) ( $meta['width'] ?? 0 ),
						'height' => (int) ( $meta['height'] ?? 0 ),
					);
				}
			}
			return array( 'url' => '', 'width' => 0, 'height' => 0 );
		}

		// Archive or search: use first post's thumbnail from main query.
		global $wp_query;
		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) && ! empty( $wp_query->posts ) ) {
			foreach ( $wp_query->posts as $p ) {
				$p_obj = ( $p instanceof \WP_Post ) ? $p : get_post( $p );
				if ( $p_obj instanceof \WP_Post ) {
					$thumb_id = get_post_thumbnail_id( $p_obj->ID );
					if ( $thumb_id > 0 ) {
						$src  = wp_get_attachment_image_url( $thumb_id, 'full' );
						$meta = wp_get_attachment_metadata( $thumb_id );
						if ( $src ) {
							return array(
								'url'    => $src,
								'width'  => (int) ( $meta['width'] ?? 0 ),
								'height' => (int) ( $meta['height'] ?? 0 ),
							);
						}
					}
				}
			}
		}

		return array( 'url' => '', 'width' => 0, 'height' => 0 );
	}
}
