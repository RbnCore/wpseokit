<?php
/**
 * Breadcrumb service: computes trail items and exposes filters for theme compatibility.
 *
 * Emits both `wpseokit_breadcrumb_links` and legacy `wpseo_breadcrumb_links` filters.
 * Exposes global helper `wpseokit_breadcrumb_items()`.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services {

	use Wpseokit\Plugin;

	defined( 'ABSPATH' ) || exit;

	final class BreadcrumbService {

		public static function init(): void {
			// Service lifecycle hook required by Plugin::SERVICES.
		}

		/**
		 * Compute breadcrumb trail for current page or custom context.
		 *
		 * @param array<string, mixed>|null $context Optional context for unit testing or overrides.
		 * @return array<int, array<string, mixed>>
		 */
		public static function get_items( ?array $context = null ): array {
			$links      = array();
			$home_title = 'Anasayfa';
			$ctx_empty  = ( null === $context || array() === $context );

			// Front page: only home item.
			if ( ( ! $ctx_empty && ! empty( $context['is_front_page'] ) ) || ( $ctx_empty && is_front_page() ) ) {
				$links[] = array(
					'url'  => home_url( '/' ),
					'text' => $home_title,
				);
				return self::filter_links( $links );
			}

			// Home archive (posts page).
			if ( ( ! $ctx_empty && ! empty( $context['is_home'] ) ) || ( $ctx_empty && is_home() ) ) {
				$links[] = array(
					'url'  => home_url( '/' ),
					'text' => $home_title,
				);
				return self::filter_links( $links );
			}

			// 404: no breadcrumb trail.
			if ( ( ! $ctx_empty && ! empty( $context['is_404'] ) ) || ( $ctx_empty && is_404() ) ) {
				return array();
			}

			// Base home link for all other pages.
			$links[] = array(
				'url'  => home_url( '/' ),
				'text' => $home_title,
			);

			$is_singular = ( ! $ctx_empty && ! empty( $context['is_singular'] ) ) || ( $ctx_empty && is_singular() );
			$is_category = ( ! $ctx_empty && ! empty( $context['is_category'] ) ) || ( $ctx_empty && is_category() );
			$is_tag      = ( ! $ctx_empty && ! empty( $context['is_tag'] ) ) || ( $ctx_empty && is_tag() );
			$is_tax      = ( ! $ctx_empty && ! empty( $context['is_tax'] ) ) || ( $ctx_empty && is_tax() );
			$is_search   = ( ! $ctx_empty && ! empty( $context['is_search'] ) ) || ( $ctx_empty && is_search() );
			$is_author   = ( ! $ctx_empty && ! empty( $context['is_author'] ) ) || ( $ctx_empty && is_author() );

			if ( $is_singular ) {
				$post = ( null !== $context && isset( $context['post'] ) && $context['post'] instanceof \WP_Post )
					? $context['post']
					: get_post();

				if ( $post instanceof \WP_Post ) {
					if ( 'post' === $post->post_type ) {
						// Add primary category and its ancestors.
						$term = null;
						if ( class_exists( PrimaryTermService::class ) ) {
							$term = PrimaryTermService::get_term( $post, 'category' );
						}
						if ( ! $term instanceof \WP_Term && function_exists( 'fm_primary_category' ) ) {
							$term = fm_primary_category( $post->ID );
						}
						if ( ! $term instanceof \WP_Term ) {
							$cats = get_the_category( $post->ID );
							if ( is_array( $cats ) && array() !== $cats ) {
								$term = reset( $cats );
							}
						}

						if ( $term instanceof \WP_Term ) {
							if ( $term->parent > 0 ) {
								$ancestors = get_ancestors( (int) $term->term_id, 'category' );
								if ( is_array( $ancestors ) ) {
									$ancestors = array_reverse( $ancestors );
									foreach ( $ancestors as $anc_id ) {
										$anc = get_term( (int) $anc_id, 'category' );
										if ( $anc instanceof \WP_Term ) {
											$links[] = array(
												'url'     => get_term_link( $anc ),
												'text'    => $anc->name,
												'term_id' => (int) $anc->term_id,
											);
										}
									}
								}
							}

							$links[] = array(
								'url'     => get_term_link( $term ),
								'text'    => $term->name,
								'term_id' => (int) $term->term_id,
							);
						}
					} elseif ( is_post_type_hierarchical( $post->post_type ) && $post->post_parent > 0 ) {
						$ancestors = get_post_ancestors( $post );
						if ( is_array( $ancestors ) ) {
							$ancestors = array_reverse( $ancestors );
							foreach ( $ancestors as $anc_id ) {
								$anc = get_post( (int) $anc_id );
								if ( $anc instanceof \WP_Post ) {
									$links[] = array(
										'url'  => get_permalink( $anc ),
										'text' => get_the_title( $anc ),
										'id'   => (int) $anc->ID,
									);
								}
							}
						}
					}

					$links[] = array(
						'url'  => get_permalink( $post ),
						'text' => get_the_title( $post ),
						'id'   => (int) $post->ID,
					);
				}
			} elseif ( $is_category || $is_tag || $is_tax ) {
				$term = ( null !== $context && isset( $context['term'] ) && $context['term'] instanceof \WP_Term )
					? $context['term']
					: get_queried_object();

				if ( $term instanceof \WP_Term ) {
					$tax = $term->taxonomy;
					if ( is_taxonomy_hierarchical( $tax ) && $term->parent > 0 ) {
						$ancestors = get_ancestors( (int) $term->term_id, $tax );
						if ( is_array( $ancestors ) ) {
							$ancestors = array_reverse( $ancestors );
							foreach ( $ancestors as $anc_id ) {
								$anc = get_term( (int) $anc_id, $tax );
								if ( $anc instanceof \WP_Term ) {
									$links[] = array(
										'url'     => get_term_link( $anc ),
										'text'    => $anc->name,
										'term_id' => (int) $anc->term_id,
									);
								}
							}
						}
					}

					$links[] = array(
						'url'     => get_term_link( $term ),
						'text'    => $term->name,
						'term_id' => (int) $term->term_id,
					);
				}
			} elseif ( $is_search ) {
				$query = ( null !== $context && isset( $context['search_query'] ) )
					? (string) $context['search_query']
					: get_search_query();

				$links[] = array(
					'url'  => home_url( '/?s=' . rawurlencode( $query ) ),
					/* translators: %s: search phrase. */
					'text' => sprintf( __( 'You searched for %s', 'wpseokit' ), $query ),
				);
			} elseif ( $is_author ) {
				$author = ( null !== $context && isset( $context['author'] ) && $context['author'] instanceof \WP_User )
					? $context['author']
					: get_queried_object();

				if ( $author instanceof \WP_User ) {
					$links[] = array(
						'url'  => get_author_posts_url( (int) $author->ID ),
						'text' => $author->display_name ?: $author->user_nicename,
					);
				}
			} else {
				$title = wp_strip_all_tags( get_the_archive_title() );
				if ( '' !== $title ) {
					$links[] = array(
						'url'  => '',
						'text' => $title,
					);
				}
			}

			return self::filter_links( $links );
		}

		/**
		 * Apply filters with backward compatibility for Yoast hooks.
		 *
		 * @param array<int, array<string, mixed>> $links Trail links.
		 * @return array<int, array<string, mixed>>
		 */
		private static function filter_links( array $links ): array {
			$links = apply_filters( 'wpseokit_breadcrumb_links', $links );
			if ( has_filter( 'wpseo_breadcrumb_links' ) ) {
				$links = apply_filters( 'wpseo_breadcrumb_links', $links );
			}
			return array_values( (array) $links );
		}
	}
}

namespace {
	if ( ! function_exists( 'wpseokit_breadcrumb_items' ) ) {
		/**
		 * Template helper: returns breadcrumb items array for themes.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		function wpseokit_breadcrumb_items(): array {
			return \Wpseokit\Services\BreadcrumbService::get_items();
		}
	}
}
