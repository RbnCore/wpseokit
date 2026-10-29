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

	/** Smallest side Google asks of an organization logo, in pixels. */
	public const LOGO_MIN_SIDE = 112;

	/**
	 * The logo this site publishes: the single source for the JSON-LD, the dashboard card, the Schema screen and the site check.
	 *
	 * @return array{url:string,source:string,width:int,height:int} source: settings | site_icon | none.
	 */
	public static function resolved_logo(): array {
		$logo_id  = (int) SettingsRepository::get( 'schema.organization_logo_id', 0 );
		$logo_url = (string) SettingsRepository::get( 'schema.organization_logo_url', '' );
		$logo_w   = 0;
		$logo_h   = 0;
		$source   = 'settings';

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
				$source   = 'site_icon';
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

		if ( '' === $logo_url ) {
			return array( 'url' => '', 'source' => 'none', 'width' => 0, 'height' => 0 );
		}
		return array( 'url' => $logo_url, 'source' => $source, 'width' => $logo_w, 'height' => $logo_h );
	}

	/**
	 * Publisher status shared by the dashboard and the Schema screen.
	 *
	 * @return array{key:string,logo:array{url:string,source:string,width:int,height:int},small:bool} key: set | icon | logo_missing | missing.
	 */
	public static function publisher_status(): array {
		$named = '' !== trim( (string) SettingsRepository::get( 'schema.organization_name', '' ) ) || '' !== trim( (string) SettingsRepository::get( 'schema.person_name', '' ) );
		$logo  = self::resolved_logo();
		$small = 'site_icon' === $logo['source'] && $logo['width'] > 0 && min( $logo['width'], $logo['height'] ) < self::LOGO_MIN_SIDE;
		if ( ! $named ) {
			$key = 'missing';
		} elseif ( 'settings' === $logo['source'] ) {
			$key = 'set';
		} elseif ( 'site_icon' === $logo['source'] ) {
			$key = 'icon';
		} else {
			$key = 'logo_missing';
		}
		return array( 'key' => $key, 'logo' => $logo, 'small' => $small );
	}

	public function generate(): ?array {
		$name = (string) SettingsRepository::get( 'schema.organization_name', '' );
		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}

		$logo     = self::resolved_logo();
		$logo_url = $logo['url'];
		$logo_w   = $logo['width'];
		$logo_h   = $logo['height'];

		$data = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);

		// Output logo only if a logo URL is resolved (omitted when empty).
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
