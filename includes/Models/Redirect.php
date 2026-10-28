<?php
/**
 * One redirect rule (a row of the redirects table).
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Models;

defined( 'ABSPATH' ) || exit;

final class Redirect {

	/** Status codes a rule may use. 301 moves for good, 302 and 307 move for a while, 410 has no target. */
	public const CODES = array( 301, 302, 307, 410 );

	/** The only match type: the whole path, case-insensitive. No prefix, wildcard or regex rules. */
	public const MATCH_EXACT = 'exact';

	public function __construct(
		public int $id,
		public string $source_path,
		public string $target_url,
		public int $status_code,
		public string $match_type,
		public int $hits,
		public ?string $last_hit_at,
		public bool $is_active,
		public string $created_at,
		public string $updated_at
	) {}

	/** @param array<string, mixed> $row Database row (object cast to array). */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['source_path'] ?? '' ),
			(string) ( $row['target_url'] ?? '' ),
			(int) ( $row['status_code'] ?? 301 ),
			self::MATCH_EXACT,
			(int) ( $row['hits'] ?? 0 ),
			empty( $row['last_hit_at'] ) ? null : (string) $row['last_hit_at'],
			! empty( $row['is_active'] ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	public function is_gone(): bool {
		return 410 === $this->status_code;
	}

	/**
	 * REST form (docs/sozlesme.md §4.5). Dates are ISO-8601 UTC.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'source_path' => $this->source_path,
			'target_url'  => $this->target_url,
			'status_code' => $this->status_code,
			'match_type'  => $this->match_type,
			'hits'        => $this->hits,
			'last_hit_at' => self::iso( $this->last_hit_at ),
			'is_active'   => $this->is_active,
			'created_at'  => self::iso( $this->created_at ),
			'updated_at'  => self::iso( $this->updated_at ),
		);
	}

	/** MySQL datetime (stored in UTC) to ISO-8601, or null. */
	public static function iso( ?string $mysql ): ?string {
		return ( null === $mysql || '' === $mysql || str_starts_with( $mysql, '0000' ) ) ? null : str_replace( ' ', 'T', $mysql );
	}
}
