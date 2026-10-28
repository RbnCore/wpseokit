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

		$logo_id  = (int) SettingsRepository::get( 'schema.organization_logo_id', 0 );
		$logo_url = (string) SettingsRepository::get( 'schema.organization_logo_url', '' );
		$logo_w   = 0;
		$logo_h   = 0;

		// 1. Settings organization logo.
		if ( $logo_id > 0 ) {
			$meta = wp_get_attachment_metadata( $logo_id );
			if ( is_array( $meta ) ) {
				$logo_w = (int) ( $meta['width'] ?? 0 );
				$logo_h = (int) ( $meta['height'] ?? 0 );
			}
			if ( '' === $logo_url ) {
				$src = wp_get_attachment_image_url( $logo_id, 'full' );
				if ( is_string( $src ) ) {
					$logo_url = $src;
				}
			}
		}

		// 2. WordPress site icon fallback.
		if ( '' === $logo_url && function_exists( 'get_site_icon_url' ) ) {
			$site_icon = (string) get_site_icon_url();
			if ( '' !== $site_icon ) {
				$logo_url = $site_icon;
				$icon_id  = (int) get_option( 'site_icon', 0 );
				if ( $icon_id > 0 ) {
					$meta = wp_get_attachment_metadata( $icon_id );
					if ( is_array( $meta ) ) {
						$logo_w = (int) ( $meta['width'] ?? 0 );
						$logo_h = (int) ( $meta['height'] ?? 0 );
					}
				}
			}
		}

		$data = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);

		// 3. Output logo only if a logo URL is resolved (omitted when empty).
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
