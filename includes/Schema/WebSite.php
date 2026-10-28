<?php
/**
 * WebSite schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class WebSite extends Piece {

	public function is_needed(): bool {
		return true;
	}

	public function generate(): ?array {
		$name = (string) SettingsRepository::get( 'schema.website_name', '' );
		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}

		$represents = (string) SettingsRepository::get( 'schema.site_represents', 'organization' );
		$publisher_id = ( 'person' === $represents )
			? home_url( '/#/schema/person/' . md5( (string) SettingsRepository::get( 'schema.person_name', $name ) ) )
			: home_url( '/#organization' );

		$data = array(
			'@type'           => 'WebSite',
			'@id'             => home_url( '/#website' ),
			'url'             => home_url( '/' ),
			'name'            => $name,
			'publisher'       => array(
				'@id' => $publisher_id,
			),
		);

		$alternate_name = (string) SettingsRepository::get( 'schema.alternate_website_name', '' );
		if ( '' !== $alternate_name ) {
			$data['alternateName'] = $alternate_name;
		}

		return $data;
	}
}
