<?php
/**
 * Read-only list of the scored posts for the dashboard (`/score/posts`): each post's stored score and the post rules counted over them.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Rest;
use Wpseokit\Rules\RuleSet;

defined( 'ABSPATH' ) || exit;

final class ScoreListService {

	public static function init(): void {
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
	}

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/score/posts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_posts' ),
				'permission_callback' => Rest::permission(),
				'args'                => array(),
			)
		);
	}

	public static function rest_posts(): \WP_REST_Response {
		$ids   = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => RuleSet::post_sample(),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$rules = array();
		foreach ( RuleSet::rules( 'post' ) as $rule ) {
			$rules[ (string) $rule['id'] ] = array(
				'rule_id'    => (string) $rule['id'],
				'level'      => (string) $rule['level'],
				'weight'     => (float) $rule['weight'],
				'title'      => __( (string) $rule['title']['en'], 'wpseokit' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- texts come from the rule table.
				'text'       => __( (string) $rule['recommendation']['en'], 'wpseokit' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				'fix'        => (string) $rule['recommendation']['fix'],
				'target'     => (string) $rule['recommendation']['target'],
				'failing'    => 0,
				'applicable' => 0,
			);
		}
		$bands = RuleSet::bands();
		$posts = array();
		foreach ( $ids as $id ) {
			$json   = get_post_meta( (int) $id, ScoreService::META_POST, true );
			$stored = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
			if ( ! is_array( $stored ) || ! isset( $stored['score'], $stored['rules'] ) ) {
				continue;
			}
			$fail = array();
			foreach ( $rules as $rid => $info ) {
				$state = $stored['rules'][ $rid ]['state'] ?? null;
				if ( null === $state ) {
					continue;
				}
				++$rules[ $rid ]['applicable'];
				if ( (float) $state < 1.0 ) {
					++$rules[ $rid ]['failing'];
					$fail[] = $info['title'];
				}
			}
			$score = (int) $stored['score'];
			$band  = 'poor';
			foreach ( $bands as $b ) {
				if ( $score >= (int) $b['min'] && $score <= (int) $b['max'] ) {
					$band = (string) $b['id'];
				}
			}
			$posts[] = array(
				'id'      => (int) $id,
				'title'   => get_the_title( (int) $id ),
				'score'   => $score,
				'band'    => $band,
				'failing' => $fail,
				'edit'    => current_user_can( 'edit_post', (int) $id ) ? (string) get_edit_post_link( (int) $id, 'raw' ) : '',
			);
		}
		return new \WP_REST_Response(
			array(
				'posts' => $posts,
				'rules' => array_values( $rules ),
			)
		);
	}
}
