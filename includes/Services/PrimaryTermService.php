<?php
/**
 * Primary category: the term that decides the `%category%` part of a post permalink.
 *
 * The permalink structure is `/%category%/%postname%/`. WordPress picks the post's lowest-ID category unless the
 * `post_link_category` filter returns another one; this service returns the editor's primary category. Without it a
 * post with several categories would move to a different URL (a real SEO loss).
 *
 * Storage: post meta `_wpseokit_primary_category` (term ID). The value is used only while the term is still
 * assigned to the post, otherwise the WordPress default applies.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class PrimaryTermService {

	public const META_KEY = '_wpseokit_primary_category';

	/** Only `category` can carry a primary term: it is the only taxonomy in the permalink structure. */
	private const TAXONOMY = 'category';

	public static function init(): void {
		add_filter( 'post_link_category', array( self::class, 'filter_permalink_category' ), 10, 3 );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
	}

	/**
	 * `post_link_category`: returns the primary category when it is still assigned to the post.
	 *
	 * @param \WP_Term                     $category   Term WordPress chose (lowest ID).
	 * @param array<int, \WP_Term>         $categories All categories of the post.
	 * @param \WP_Post|null                $post       The post.
	 * @return \WP_Term
	 */
	public static function filter_permalink_category( $category, $categories = array(), $post = null ) {
		if ( ! $post instanceof \WP_Post || ! SettingsRepository::get( 'general.primary_category', true ) ) {
			return $category;
		}
		$primary = self::get_term_id( (int) $post->ID );
		if ( $primary < 1 || ! is_array( $categories ) ) {
			return $category;
		}
		foreach ( $categories as $candidate ) {
			if ( $candidate instanceof \WP_Term && (int) $candidate->term_id === $primary ) {
				return $candidate;
			}
		}
		return $category;
	}

	/** Stored primary term ID (0 when none). Not checked against the post's terms: use get_term() for that. */
	public static function get_term_id( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * The primary term of a post, or the first assigned term when none is stored. Null when the post has no term.
	 *
	 * @param \WP_Post|int $post Post or ID.
	 */
	public static function get_term( $post, string $taxonomy = self::TAXONOMY ): ?\WP_Term {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}
		$primary = ( self::TAXONOMY === $taxonomy ) ? self::get_term_id( (int) $post->ID ) : 0;
		foreach ( $terms as $term ) {
			if ( (int) $term->term_id === $primary ) {
				return $term;
			}
		}
		return reset( $terms );
	}

	/**
	 * Stores the primary category. `0` removes it. Fails when the term is not assigned to the post.
	 *
	 * @return bool True when stored (or removed).
	 */
	public static function set( int $post_id, int $term_id ): bool {
		if ( $term_id < 1 ) {
			delete_post_meta( $post_id, self::META_KEY );
			return true;
		}
		if ( ! has_term( $term_id, self::TAXONOMY, $post_id ) ) {
			return false;
		}
		update_post_meta( $post_id, self::META_KEY, $term_id );
		return true;
	}

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/primary-term',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_set' ),
				'permission_callback' => array( Rest::class, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'taxonomy' => array(
						'type'    => 'string',
						'default' => self::TAXONOMY,
						'enum'    => array( self::TAXONOMY ),
					),
					'term_id'  => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
				),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_set( \WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$term_id = (int) $request['term_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'wpseokit_not_found', __( 'Post not found.', 'wpseokit' ), array( 'status' => 404 ) );
		}
		if ( ! self::set( $post_id, $term_id ) ) {
			return new \WP_Error( 'wpseokit_term_not_assigned', __( 'The category is not assigned to this post.', 'wpseokit' ), array( 'status' => 422 ) );
		}
		return new \WP_REST_Response(
			array(
				'post_id'   => $post_id,
				'term_id'   => $term_id,
				'permalink' => get_permalink( $post_id ),
			)
		);
	}
}
