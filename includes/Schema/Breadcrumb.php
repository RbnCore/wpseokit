<?php
/**
 * BreadcrumbList schema piece.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

use Wpseokit\Services\BreadcrumbService;

defined( 'ABSPATH' ) || exit;

final class Breadcrumb extends Piece {

	public function is_needed(): bool {
		if ( ! empty( $this->context['is_404'] ) || is_404() ) {
			return false;
		}

		return true;
	}

	public function generate(): ?array {
		$items = BreadcrumbService::get_items( $this->context );
		if ( array() === $items ) {
			return null;
		}

		$is_search = ! empty( $this->context['is_search'] ) || is_search();
		$is_home   = ! empty( $this->context['is_front_page'] ) || is_front_page();

		if ( $is_search ) {
			$id = '#breadcrumb';
		} elseif ( $is_home ) {
			$id = home_url( '/#breadcrumb' );
		} else {
			$url = $this->context['url'] ?? '';
			if ( '' === $url && is_singular() ) {
				$url = get_permalink();
			}
			$id  = rtrim( (string) $url, '/' ) . '/#breadcrumb';
		}

		$list  = array();
		$total = count( $items );
		$pos   = 1;

		foreach ( $items as $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => (string) ( $item['text'] ?? '' ),
			);

			// The terminal item in the breadcrumb list must not have an 'item' URL.
			if ( $pos < $total && ! empty( $item['url'] ) ) {
				$entry['item'] = (string) $item['url'];
			}

			$list[] = $entry;
			$pos++;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $id,
			'itemListElement' => $list,
		);
	}
}
