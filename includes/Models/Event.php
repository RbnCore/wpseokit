<?php
/**
 * One activity event (a row of the events table). No personal data: type, time and a short text only.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Models;

defined( 'ABSPATH' ) || exit;

final class Event {

	/** Event types of the Activity list (docs/sozlesme.md §9). */
	public const TYPES = array( 'sitemap', 'score', 'snapshot', 'settings', 'redirects', 'feed', 'llms' );

	public function __construct(
		public int $id,
		public string $at,
		public string $type,
		public string $event,
		public string $detail,
		public string $link
	) {}

	/** @param array<string, mixed> $row Database row. */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['at'] ?? '' ),
			(string) ( $row['type'] ?? '' ),
			(string) ( $row['event'] ?? '' ),
			(string) ( $row['detail'] ?? '' ),
			(string) ( $row['link'] ?? '' )
		);
	}

	/** @return array<string, mixed> REST form. */
	public function to_array(): array {
		return array(
			'id'     => $this->id,
			'at'     => Redirect::iso( $this->at ),
			'type'   => $this->type,
			'event'  => $this->event,
			'detail' => $this->detail,
			'link'   => $this->link,
		);
	}
}
