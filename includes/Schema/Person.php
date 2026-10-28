<?php
/**
 * Person schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class Person extends Piece {

	public function is_needed(): bool {
		$represents = (string) SettingsRepository::get( 'schema.site_represents', 'organization' );
		if ( 'person' === $represents ) {
			return true;
		}

		if ( ! empty( $this->context['is_post'] ) || ( is_singular( 'post' ) && ! empty( $this->get_author() ) ) ) {
			return true;
		}

		return false;
	}

	public function generate(): ?array {
		$author = $this->get_author();
		if ( ! $author instanceof \WP_User ) {
			$represents = (string) SettingsRepository::get( 'schema.site_represents', 'organization' );
			if ( 'person' !== $represents ) {
				return null;
			}
			$name = (string) SettingsRepository::get( 'schema.person_name', '' );
			if ( '' === $name ) {
				$name = get_bloginfo( 'name' );
			}
			return array(
				'@type' => 'Person',
				'@id'   => home_url( '/#/schema/person/' . md5( $name ) ),
				'name'  => $name,
			);
		}

		$person_id = self::get_person_id( $author );

		$data = array(
			'@type' => 'Person',
			'@id'   => $person_id,
			'name'  => $author->display_name ?: $author->user_nicename,
		);

		// Respect privacy: no email/gravatar/image; only author website if explicitly set.
		if ( ! empty( $author->user_url ) ) {
			$data['sameAs'] = array( esc_url_raw( $author->user_url ) );
		}

		return $data;
	}

	/**
	 * Compute deterministic Person schema @id for a user.
	 */
	public static function get_person_id( \WP_User $user ): string {
		$key = $user->user_nicename ?: (string) $user->ID;
		return home_url( '/#/schema/person/' . md5( $key ) );
	}

	private function get_author(): ?\WP_User {
		if ( isset( $this->context['author_user'] ) && $this->context['author_user'] instanceof \WP_User ) {
			return $this->context['author_user'];
		}

		$post = $this->context['post'] ?? get_post();
		if ( $post instanceof \WP_Post && (int) $post->post_author > 0 ) {
			$user = get_userdata( (int) $post->post_author );
			return $user instanceof \WP_User ? $user : null;
		}

		return null;
	}
}
