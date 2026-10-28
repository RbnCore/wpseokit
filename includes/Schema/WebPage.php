<?php
/**
 * WebPage schema piece.
 *
 * Handles WebPage, CollectionPage, and SearchResultsPage.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class WebPage extends Piece {

	public function is_needed(): bool {
		if ( ! empty( $this->context['is_404'] ) || is_404() ) {
			return false;
		}

		return true;
	}

	public function generate(): ?array {
		$is_search = ! empty( $this->context['is_search'] ) || is_search();
		$is_home   = ! empty( $this->context['is_front_page'] ) || is_front_page();
		$is_cat    = ! empty( $this->context['is_category'] ) || is_category();
		$is_tag    = ! empty( $this->context['is_tag'] ) || is_tag();
		$is_tax    = ! empty( $this->context['is_tax'] ) || is_tax();
		$is_author = ! empty( $this->context['is_author'] ) || is_author();
		$is_paged  = ! empty( $this->context['is_paged'] ) || is_paged();
		$paged     = (int) ( $this->context['paged'] ?? ( get_query_var( 'paged' ) ?: 1 ) );

		// Determine @type.
		if ( $is_search ) {
			$type = array( 'CollectionPage', 'SearchResultsPage' );
		} elseif ( $is_cat || $is_tag || $is_tax || $is_author || ( is_archive() && ! $is_home ) ) {
			$type = 'CollectionPage';
		} else {
			$type = 'WebPage';
		}

		$current_url = (string) ( $this->context['url'] ?? home_url( '/' ) );
		$page_id     = $this->compute_id( $current_url, $is_home, $is_cat, $is_tag, $is_tax );

		$locale = get_locale();
		$lang   = strtolower( (string) substr( $locale, 0, 2 ) );
		if ( '' === $lang ) {
			$lang = 'tr';
		}

		$webpage = array(
			'@type'      => $type,
			'@id'        => $page_id,
			'url'        => $current_url,
			'name'       => $this->get_page_name( $is_home, $is_search, $is_cat, $is_tag, $is_tax, $paged ),
			'isPartOf'   => array(
				'@id' => home_url( '/#website' ),
			),
			'inLanguage' => $lang,
		);

		// Primary image.
		$img_piece = new ImageObject( $this->context );
		$img_data  = $img_piece->get_image_data();
		if ( ! empty( $img_data['url'] ) ) {
			$img_id_ref = $is_search ? '#primaryimage' : ( rtrim( $current_url, '/' ) . '/#primaryimage' );
			$webpage['primaryImageOfPage'] = array(
				'@id' => $img_id_ref,
			);
			$webpage['image'] = array(
				'@id' => $img_id_ref,
			);
			$webpage['thumbnailUrl'] = $img_data['url'];
		}

		// Dates (on singular or front page).
		$post = $this->context['post'] ?? null;
		if ( ! $post instanceof \WP_Post && is_singular() ) {
			$post = get_post();
		}
		if ( $post instanceof \WP_Post && ! ( $is_cat || $is_tag || $is_tax || $is_search ) ) {
			$pub_time = ! empty( $post->post_date_gmt ) ? strtotime( $post->post_date_gmt ) : strtotime( $post->post_date );
			$mod_time = ! empty( $post->post_modified_gmt ) ? strtotime( $post->post_modified_gmt ) : strtotime( $post->post_modified );
			if ( $pub_time ) {
				$webpage['datePublished'] = wp_date( 'c', $pub_time );
			}
			if ( $mod_time ) {
				$webpage['dateModified'] = wp_date( 'c', $mod_time );
			}

			// Author link for singular posts.
			if ( 'post' === $post->post_type && (int) $post->post_author > 0 ) {
				$author = get_userdata( (int) $post->post_author );
				if ( $author instanceof \WP_User ) {
					$webpage['author'] = array(
						'@id' => Person::get_person_id( $author ),
					);
				}
			}
		}

		// Description.
		$desc = $this->get_description( $is_home, $is_search, $is_cat, $is_tag, $is_tax, $post );
		if ( '' !== $desc ) {
			$webpage['description'] = $desc;
		}

		// Breadcrumb linkage (only when the list is emitted: 2+ items).
		if ( ( new Breadcrumb( $this->context ) )->is_needed() ) {
			$bc_id                 = $is_search ? '#breadcrumb' : ( $is_home ? home_url( '/#breadcrumb' ) : ( rtrim( $current_url, '/' ) . '/#breadcrumb' ) );
			$webpage['breadcrumb'] = array(
				'@id' => $bc_id,
			);
		}

		// ReadAction on front page, singular post, or static page.
		if ( $is_home || ( $post instanceof \WP_Post && ( 'post' === $post->post_type || 'page' === $post->post_type ) ) ) {
			$webpage['potentialAction'] = array(
				array(
					'@type'  => 'ReadAction',
					'target' => array( (string) $current_url ),
				),
			);
		}

		return $webpage;
	}

	private function compute_id( string $current_url, bool $is_home, bool $is_cat, bool $is_tag, bool $is_tax ): string {
		if ( $is_home ) {
			return home_url( '/' );
		}

		if ( $is_cat || $is_tag || $is_tax ) {
			$term = $this->context['term'] ?? get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					return $link;
				}
			}
		}

		return $current_url;
	}

	private function get_page_name( bool $is_home, bool $is_search, bool $is_cat, bool $is_tag, bool $is_tax, int $paged ): string {
		$sitename = (string) SettingsRepository::get( 'schema.website_name', '' );
		if ( '' === $sitename ) {
			$sitename = get_bloginfo( 'name' );
		}

		if ( $is_home ) {
			$tpl_home = (string) SettingsRepository::get( 'templates.home_title', '' );
			if ( '' !== $tpl_home && '%sitename% %sep% %sitedesc%' !== $tpl_home ) {
				return $tpl_home;
			}
			$desc = get_bloginfo( 'description' );
			return '' !== $desc ? ( $sitename . ' - ' . $desc ) : $sitename;
		}

		if ( $is_search ) {
			$query = (string) ( $this->context['search_query'] ?? get_search_query() );
			return $query . ' için arama yaptınız - ' . $sitename;
		}

		if ( $is_cat || $is_tag || $is_tax ) {
			$term = $this->context['term'] ?? get_queried_object();
			$term_name = ( $term instanceof \WP_Term ) ? $term->name : '';
			if ( $paged > 1 ) {
				global $wp_query;
				$max = (int) ( $this->context['max_num_pages'] ?? ( $wp_query->max_num_pages ?? 1 ) );
				if ( $max < $paged ) {
					$max = $paged;
				}
				return sprintf( '%s arşivleri - Sayfa %d / %d - %s', $term_name, $paged, $max, $sitename );
			}
			return sprintf( '%s arşivleri - %s', $term_name, $sitename );
		}

		$post = $this->context['post'] ?? null;
		if ( ! $post instanceof \WP_Post && is_singular() ) {
			$post = get_post();
		}
		if ( $post instanceof \WP_Post ) {
			$custom_title = get_post_meta( $post->ID, '_wpseokit_title', true );
			if ( is_string( $custom_title ) && '' !== trim( $custom_title ) ) {
				return trim( $custom_title );
			}
			$yoast_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
			if ( is_string( $yoast_title ) && '' !== trim( $yoast_title ) ) {
				// Replace basic Yoast vars if present.
				$yoast_title = str_replace(
					array( '%%title%%', '%%sitename%%', '%%sep%%', '%%page%%' ),
					array( get_the_title( $post ), $sitename, '-', '' ),
					$yoast_title
				);
				return trim( (string) preg_replace( '/\s+/', ' ', $yoast_title ) );
			}
			return get_the_title( $post ) . ' - ' . $sitename;
		}

		return wp_get_document_title();
	}

	private function get_description( bool $is_home, bool $is_search, bool $is_cat, bool $is_tag, bool $is_tax, ?\WP_Post $post ): string {
		if ( $is_search ) {
			return '';
		}

		if ( $is_home ) {
			$desc = (string) SettingsRepository::get( 'templates.home_description', '' );
			if ( '' !== $desc ) {
				return $desc;
			}
			$yoast_titles = get_option( 'wpseo_titles' );
			if ( is_array( $yoast_titles ) && ! empty( $yoast_titles['metadesc-home-wpseo'] ) ) {
				return (string) $yoast_titles['metadesc-home-wpseo'];
			}
			return (string) get_bloginfo( 'description' );
		}

		if ( $is_cat || $is_tag || $is_tax ) {
			$term = $this->context['term'] ?? get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$desc = (string) get_term_meta( $term->term_id, 'wpseokit_description', true );
				if ( '' !== $desc ) {
					return $desc;
				}
				$yoast_tax_meta = get_option( 'wpseo_taxonomy_meta' );
				if ( is_array( $yoast_tax_meta ) && isset( $yoast_tax_meta[ $term->taxonomy ][ $term->term_id ]['wpseo_desc'] ) ) {
					return (string) $yoast_tax_meta[ $term->taxonomy ][ $term->term_id ]['wpseo_desc'];
				}
				$wp_desc = term_description( $term->term_id, $term->taxonomy );
				return is_string( $wp_desc ) ? trim( wp_strip_all_tags( $wp_desc ) ) : '';
			}
			return '';
		}

		if ( $post instanceof \WP_Post ) {
			$desc = (string) get_post_meta( $post->ID, '_wpseokit_description', true );
			if ( '' !== $desc ) {
				return $desc;
			}
			$yoast_desc = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			if ( '' !== $yoast_desc ) {
				return $yoast_desc;
			}
			return wp_strip_all_tags( get_the_excerpt( $post ) );
		}

		return '';
	}
}
