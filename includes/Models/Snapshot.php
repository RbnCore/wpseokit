<?php
/**
 * One daily snapshot: the values the plugin's own services measured on one day. No personal data.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Models;

defined( 'ABSPATH' ) || exit;

final class Snapshot {

	/** Layout version of `metrics`; raise it when keys are renamed or removed (adding a key needs no bump). */
	public const SCHEMA_VERSION = 1;

	/**
	 * @param array<string, mixed> $metrics Nested metrics (docs/kapsam-ve-mimari.md §3.12).
	 */
	public function __construct(
		public int $id,
		public string $snapshot_date,
		public array $metrics,
		public int $schema_version,
		public string $taken_at
	) {}

	/** @param array<string, mixed> $row Database row (object cast to array). */
	public static function from_row( array $row ): self {
		$metrics = json_decode( (string) ( $row['metrics'] ?? '' ), true );
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['snapshot_date'] ?? '' ),
			is_array( $metrics ) ? $metrics : array(),
			(int) ( $row['schema_version'] ?? 1 ),
			(string) ( $row['taken_at'] ?? '' )
		);
	}

	/**
	 * REST form (docs/sozlesme.md §4.5).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'date'           => $this->snapshot_date,
			'taken_at'       => Redirect::iso( $this->taken_at ),
			'schema_version' => $this->schema_version,
			'metrics'        => $this->metrics,
		);
	}
}
