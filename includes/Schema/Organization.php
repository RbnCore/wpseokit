<?php
/**
 * Organization schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class Organization extends Piece {

	public function is_needed(): bool {
		$represents = (string) SettingsRepository::get( 'schema.site_represents', 'organization' );
		return 'organization' === $represents;
	}

	public function generate(): ?array {
		$name = (string) SettingsRepository::get( 'schema.organization_name', '' );
		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}

		$logo_url = (string) SettingsRepository::get( 'schema.organization_logo_url', '' );
		$logo_w   = 0;
		$logo_h   = 0;

		if ( '' !== $logo_url ) {
			$logo_id = (int) SettingsRepository::get( 'schema.organization_logo_id', 0 );
			if ( $logo_id > 0 ) {
				$meta = wp_get_attachment_metadata( $logo_id );
				if ( is_array( $meta ) ) {
					$logo_w = (int) ( $meta['width'] ?? 0 );
					$logo_h = (int) ( $meta['height'] ?? 0 );
				}
			}
		} else {
			// Theme fallback matching Filmmakinesi theme patch.
			$scheme     = wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https';
			$theme_logo = set_url_scheme( get_template_directory_uri() . '/assets/img/apple-touch-icon-180.png', $scheme );
			$logo_url   = $theme_logo;
			$logo_w     = 180;
			$logo_h     = 180;
		}

		$data = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);

		if ( '' !== $logo_url ) {
			$logo_data = array(
				'@type' => 'ImageObject',
				'url'   => $logo_url,
			);
			if ( $logo_w > 0 && $logo_h > 0 ) {
				$logo_data['width']  = $logo_w;
				$logo_data['height'] = $logo_h;
			}
			$data['logo'] = $logo_data;
		}

		$profiles = SettingsRepository::get( 'social.profiles', array() );
		if ( is_array( $profiles ) && array() !== $profiles ) {
			$urls = array_values( array_filter( array_map( 'esc_url_raw', $profiles ) ) );
			if ( array() !== $urls ) {
				$data['sameAs'] = $urls;
			}
		}

		return $data;
	}
}
