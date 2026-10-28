<?php
/**
 * Schema service: generates and prints the Schema.org JSON-LD graph.
 *
 * Emits a single <script type="application/ld+json" class="wpseokit-schema"> block in wp_head.
 * Integrates theme and legacy filters: `wpseokit_schema_graph` and `wpseo_schema_graph`.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Plugin;
use Wpseokit\Schema\Article;
use Wpseokit\Schema\Breadcrumb;
use Wpseokit\Schema\ImageObject;
use Wpseokit\Schema\Movie;
use Wpseokit\Schema\Organization;
use Wpseokit\Schema\Person;
use Wpseokit\Schema\Piece;
use Wpseokit\Schema\TVSeries;
use Wpseokit\Schema\WebPage;
use Wpseokit\Schema\WebSite;

defined( 'ABSPATH' ) || exit;

final class SchemaService {

	/**
	 * Boot service if output is allowed.
	 */
	public static function init(): void {
		if ( ! Plugin::output_enabled() ) {
			return;
		}

		add_action( 'wp_head', array( self::class, 'output' ), 20 );
	}

	/**
	 * Output JSON-LD script block in the document head.
	 */
	public static function output(): void {
		if ( ! Plugin::output_enabled() ) {
			return;
		}

		$graph = self::generate_graph();
		if ( array() === $graph ) {
			return;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		$json = wp_json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $json ) {
			return;
		}

		echo "\t" . '<script type="application/ld+json" class="wpseokit-schema">' . $json . "</script>\n";
	}

	/**
	 * Build and filter the schema pieces array.
	 *
	 * @param array<string, mixed>|null $context Context override for tests or custom rendering.
	 * @return array<int, array<string, mixed>>
	 */
	public static function generate_graph( ?array $context = null ): array {
		if ( null === $context ) {
			$context = self::build_context();
		}

		/** @var array<int, class-string<Piece>> $piece_classes */
		$piece_classes = array(
			Article::class,
			Movie::class,
			TVSeries::class,
			WebPage::class,
			ImageObject::class,
			Breadcrumb::class,
			WebSite::class,
			Person::class,
			Organization::class,
		);

		$graph = array();

		foreach ( $piece_classes as $class ) {
			/** @var Piece $piece */
			$piece = new $class( $context );
			if ( $piece->is_needed() ) {
				$data = $piece->generate();
				if ( is_array( $data ) && array() !== $data ) {
					$graph[] = $data;
				}
			}
		}

		$graph = apply_filters( 'wpseokit_schema_graph', $graph );
		if ( has_filter( 'wpseo_schema_graph' ) ) {
			$graph = apply_filters( 'wpseo_schema_graph', $graph );
		}

		/* Last guard: a node field that still holds `%variable%` is dropped, never sent to Google. */
		return array_values( Variables::scrub( (array) $graph ) );
	}

	/**
	 * Build context from the current WordPress query.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_context(): array {
		$context = array(
			'is_front_page' => is_front_page(),
			'is_home'       => is_home(),
			'is_singular'   => is_singular(),
			'is_post'       => is_singular( 'post' ),
			'is_page'       => is_singular( 'page' ),
			'is_category'   => is_category(),
			'is_tag'        => is_tag(),
			'is_tax'        => is_tax(),
			'is_search'     => is_search(),
			'is_author'     => is_author(),
			'is_404'        => is_404(),
			'is_paged'      => is_paged(),
			'paged'         => max( 1, (int) get_query_var( 'paged' ) ),
		);

		if ( $context['is_front_page'] ) {
			$context['url'] = home_url( '/' );
			if ( $context['is_paged'] && $context['paged'] > 1 ) {
				$context['url'] = home_url( '/page/' . $context['paged'] . '/' );
			}
		} elseif ( $context['is_singular'] ) {
			$post = get_post();
			if ( $post instanceof \WP_Post ) {
				$context['post'] = $post;
				$context['url']  = get_permalink( $post );
			}
		} elseif ( $context['is_category'] || $context['is_tag'] || $context['is_tax'] ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$context['term'] = $term;
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$context['url'] = $link;
					if ( $context['is_paged'] && $context['paged'] > 1 ) {
						$context['url'] = trailingslashit( $link ) . 'page/' . $context['paged'] . '/';
					}
				}
			}
		} elseif ( $context['is_search'] ) {
			$query                   = get_search_query();
			$context['search_query'] = $query;
			$context['url']          = home_url( '/?s=' . rawurlencode( $query ) );
		} elseif ( $context['is_author'] ) {
			$author = get_queried_object();
			if ( $author instanceof \WP_User ) {
				$context['author_user'] = $author;
				$context['url']         = get_author_posts_url( (int) $author->ID );
			}
		} else {
			global $wp;
			$context['url'] = home_url( add_query_arg( array(), $wp->request ?? '' ) );
		}

		return $context;
	}
}
