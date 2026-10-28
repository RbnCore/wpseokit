<?php
/**
 * One 404 log row: a missing address, how often it was hit and a short hash of the last visitor's network.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Models;

defined( 'ABSPATH' ) || exit;

final class Log404 {

	public function __construct(
		public int $id,
		public string $url_path,
		public string $ip_hash,
		public string $referer,
		public string $user_agent,
		public int $hit_count,
		public string $first_seen_at,
		public string $last_seen_at
	) {}

	/** @param array<string, mixed> $row Database row (object cast to array). */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['url_path'] ?? '' ),
			(string) ( $row['ip_hash'] ?? '' ),
			(string) ( $row['referer'] ?? '' ),
			(string) ( $row['user_agent'] ?? '' ),
			(int) ( $row['hit_count'] ?? 1 ),
			(string) ( $row['first_seen_at'] ?? '' ),
			(string) ( $row['last_seen_at'] ?? '' )
		);
	}

	/**
	 * REST form (docs/sozlesme.md §4.5). Only the first 8 hex digits of the hash leave the server.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'url_path'      => $this->url_path,
			'hit_count'     => $this->hit_count,
			'referer'       => $this->referer,
			'user_agent'    => $this->user_agent,
			'ip_hash8'      => substr( $this->ip_hash, 0, 8 ),
			'first_seen_at' => Redirect::iso( $this->first_seen_at ),
			'last_seen_at'  => Redirect::iso( $this->last_seen_at ),
		);
	}
}
