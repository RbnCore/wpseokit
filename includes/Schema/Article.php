<?php
/**
 * Article schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

defined( 'ABSPATH' ) || exit;

final class Article extends Piece {

	public function is_needed(): bool {
		if ( ! empty( $this->context['is_post'] ) ) {
			return true;
		}

		$post = $this->context['post'] ?? null;
		if ( ! $post instanceof \WP_Post && is_singular( 'post' ) ) {
			$post = get_post();
		}

		return $post instanceof \WP_Post && 'post' === $post->post_type;
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
		$id_ref   = rtrim( (string) $url_base, '/' ) . '/#article';

		$author = null;
		if ( isset( $this->context['author_user'] ) && $this->context['author_user'] instanceof \WP_User ) {
			$author = $this->context['author_user'];
		} elseif ( (int) $post->post_author > 0 ) {
			$user = get_userdata( (int) $post->post_author );
			if ( $user instanceof \WP_User ) {
				$author = $user;
			}
		}

		$author_data = array();
		if ( $author instanceof \WP_User ) {
			$author_data = array(
				'name' => $author->display_name ?: $author->user_nicename,
				'@id'  => Person::get_person_id( $author ),
			);
		}

		$pub_time = ! empty( $post->post_date_gmt ) ? strtotime( $post->post_date_gmt ) : strtotime( $post->post_date );
		$mod_time = ! empty( $post->post_modified_gmt ) ? strtotime( $post->post_modified_gmt ) : strtotime( $post->post_modified );

		// Compute word count using filtered content to match reference baseline.
		$filtered_content = apply_filters( 'the_content', $post->post_content );
		$word_count       = str_word_count( strip_tags( $post->post_title . ' ' . $filtered_content ) );

		$locale = get_locale();
		$lang   = strtolower( (string) substr( $locale, 0, 2 ) );
		if ( '' === $lang ) {
			$lang = 'tr';
		}

		$article = array(
			'@type'            => 'Article',
			'@id'              => $id_ref,
			'isPartOf'         => array(
				'@id' => (string) $url_base,
			),
			'author'           => $author_data,
			'headline'         => get_the_title( $post ),
			'datePublished'    => gmdate( 'c', (int) $pub_time ),
			'dateModified'     => gmdate( 'c', (int) $mod_time ),
			'mainEntityOfPage' => array(
				'@id' => (string) $url_base,
			),
			'wordCount'        => $word_count,
			'commentCount'     => (int) $post->comment_count,
		);

		// Primary image linkage.
		$has_img = ! empty( $this->context['has_image'] ) || has_post_thumbnail( $post );
		if ( $has_img ) {
			$article['image'] = array(
				'@id' => rtrim( (string) $url_base, '/' ) . '/#primaryimage',
			);
			if ( ! empty( $this->context['image_url'] ) ) {
				$article['thumbnailUrl'] = (string) $this->context['image_url'];
			} else {
				$thumb_id = get_post_thumbnail_id( $post->ID );
				if ( $thumb_id > 0 ) {
					$src = wp_get_attachment_image_url( $thumb_id, 'full' );
					if ( $src ) {
						$article['thumbnailUrl'] = $src;
					}
				}
			}
		}

		// Tags as keywords.
		$tags = wp_get_post_tags( (int) $post->ID, array( 'fields' => 'names' ) );
		if ( is_array( $tags ) && array() !== $tags ) {
			$article['keywords'] = $tags;
		}

		// Categories as articleSection.
		$cats = wp_get_post_categories( (int) $post->ID, array( 'fields' => 'names' ) );
		if ( is_array( $cats ) && array() !== $cats ) {
			$article['articleSection'] = $cats;
		}

		$article['inLanguage'] = $lang;

		// Potential CommentAction.
		if ( comments_open( $post ) || (int) $post->comment_count > 0 ) {
			$article['potentialAction'] = array(
				array(
					'@type'  => 'CommentAction',
					'name'   => 'Comment',
					'target' => array( rtrim( (string) $url_base, '/' ) . '/#respond' ),
				),
			);
		}

		$article['publisher'] = array(
			'@id' => home_url( '/#organization' ),
		);

		// Movie / TVSeries linkage when metadata is populated.
		$movie_piece = new Movie( $this->context );
		if ( $movie_piece->is_needed() ) {
			$article['about'] = array(
				'@id' => rtrim( (string) $url_base, '/' ) . '/#movie',
			);
		} else {
			$tv_piece = new TVSeries( $this->context );
			if ( $tv_piece->is_needed() ) {
				$article['about'] = array(
					'@id' => rtrim( (string) $url_base, '/' ) . '/#tvseries',
				);
			}
		}

		return $article;
	}
}
