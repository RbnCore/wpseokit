<?php
/**
 * Yoast import report: one row per source, with counters that must add up.
 *
 * Every source row ends in exactly one bucket, so for each row:
 * found = will_import + same + empty + invalid + conflicts
 * (`will_import` = would be written in a dry run, was written in an apply; `same` = target already holds the value;
 * `empty` = nothing to import; `invalid` = cannot be imported, for example a primary category the post does not have;
 * `conflicts` = target holds another value and overwrite is off.) Sources WPSeoKit does not migrate by design are listed
 * apart in `not_migrated`.
 *
 * Contract: docs/kapsam-ve-mimari.md §4 "Rapor biçimi".
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Models;

defined( 'ABSPATH' ) || exit;

final class ImportReport {

	public const MODE_DRY_RUN = 'dry-run';
	public const MODE_APPLY   = 'apply';
	public const MODE_VERIFY  = 'verify';

	/** Row buckets (everything but `found` and `converted`). */
	private const BUCKETS = array( 'will_import', 'same', 'empty', 'invalid', 'conflicts' );

	/** @var array<string, array<string, mixed>> */
	private array $rows = array();

	/** @var array<int, array{label: string, count: int, reason: string}> */
	private array $not_migrated = array();

	/** @var string[] Warnings that need a human look (conflicts between sources, unknown variables). */
	private array $notes = array();

	/** @var array<string, int> Source row counts at report time (proof that Yoast data is untouched). */
	private array $source_counts = array();

	/** @var array<string, int> Permalink check: posts checked / changed. */
	private array $extra = array();

	public function __construct(
		private string $mode,
		private bool $overwrite = false,
		private int $time = 0
	) {
		$this->time = $time > 0 ? $time : time();
	}

	public function mode(): string {
		return $this->mode;
	}

	public function overwrite(): bool {
		return $this->overwrite;
	}

	public function time(): int {
		return $this->time;
	}

	/** Starts (or resets) a row. */
	public function row( string $key, string $label ): void {
		$this->rows[ $key ] = array(
			'key'         => $key,
			'label'       => $label,
			'found'       => 0,
			'will_import' => 0,
			'same'        => 0,
			'empty'       => 0,
			'invalid'     => 0,
			'conflicts'   => 0,
			'converted'   => 0,
			'note'        => '',
		);
	}

	/** Counts one source item into a bucket of a row (`found` rises with it). */
	public function count( string $key, string $bucket ): void {
		if ( ! isset( $this->rows[ $key ] ) || ! in_array( $bucket, self::BUCKETS, true ) ) {
			return;
		}
		++$this->rows[ $key ]['found'];
		++$this->rows[ $key ][ $bucket ];
	}

	/** An item whose value had Yoast variables converted. */
	public function converted( string $key ): void {
		if ( isset( $this->rows[ $key ] ) ) {
			++$this->rows[ $key ]['converted'];
		}
	}

	public function note_row( string $key, string $note ): void {
		if ( isset( $this->rows[ $key ] ) ) {
			$this->rows[ $key ]['note'] = $note;
		}
	}

	public function note( string $message ): void {
		$this->notes[] = $message;
	}

	public function not_migrated( string $label, int $count, string $reason ): void {
		$this->not_migrated[] = array(
			'label'  => $label,
			'count'  => $count,
			'reason' => $reason,
		);
	}

	/** @param array<string, int> $counts Source row counts. */
	public function set_source_counts( array $counts ): void {
		$this->source_counts = $counts;
	}

	/** @return array<string, int> */
	public function source_counts(): array {
		return $this->source_counts;
	}

	public function set_extra( string $key, int $value ): void {
		$this->extra[ $key ] = $value;
	}

	/** @return array<string, array<string, mixed>> */
	public function rows(): array {
		return $this->rows;
	}

	public function get( string $key, string $field ): int {
		return (int) ( $this->rows[ $key ][ $field ] ?? 0 );
	}

	/** Total of one counter over all rows. */
	public function total( string $field ): int {
		$sum = 0;
		foreach ( $this->rows as $row ) {
			$sum += (int) ( $row[ $field ] ?? 0 );
		}
		return $sum;
	}

	/** Rows whose counters do not add up (must be empty). @return string[] */
	public function unbalanced(): array {
		$bad = array();
		foreach ( $this->rows as $key => $row ) {
			$sum = 0;
			foreach ( self::BUCKETS as $bucket ) {
				$sum += (int) $row[ $bucket ];
			}
			if ( $sum !== (int) $row['found'] ) {
				$bad[] = $key;
			}
		}
		return $bad;
	}

	/**
	 * Array form, also the REST response. `rows[]` keeps the keys the admin screen reads:
	 * `found`, `will_import`, `skipped` (same + empty + invalid), `conflicts`, `note`.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$rows = array();
		foreach ( $this->rows as $row ) {
			$row['skipped'] = (int) $row['same'] + (int) $row['empty'] + (int) $row['invalid'];
			$rows[]         = $row;
		}
		return array(
			'mode'          => $this->mode,
			'overwrite'     => $this->overwrite,
			'time'          => $this->time,
			'rows'          => $rows,
			'totals'        => array(
				'found'       => $this->total( 'found' ),
				'will_import' => $this->total( 'will_import' ),
				'same'        => $this->total( 'same' ),
				'empty'       => $this->total( 'empty' ),
				'invalid'     => $this->total( 'invalid' ),
				'conflicts'   => $this->total( 'conflicts' ),
			),
			'not_migrated'  => $this->not_migrated,
			'notes'         => $this->notes,
			'source_counts' => $this->source_counts,
			'extra'         => $this->extra,
			'balanced'      => array() === $this->unbalanced(),
		);
	}

	/** @param array<string, mixed> $data Output of to_array(). */
	public static function from_array( array $data ): self {
		$report               = new self( (string) ( $data['mode'] ?? self::MODE_DRY_RUN ), ! empty( $data['overwrite'] ), (int) ( $data['time'] ?? 0 ) );
		$report->not_migrated = is_array( $data['not_migrated'] ?? null ) ? $data['not_migrated'] : array();
		$report->notes        = is_array( $data['notes'] ?? null ) ? array_map( 'strval', $data['notes'] ) : array();
		$report->source_counts = is_array( $data['source_counts'] ?? null ) ? array_map( 'intval', $data['source_counts'] ) : array();
		$report->extra        = is_array( $data['extra'] ?? null ) ? array_map( 'intval', $data['extra'] ) : array();
		foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) ) {
				unset( $row['skipped'] );
				$report->rows[ (string) $row['key'] ] = $row;
			}
		}
		return $report;
	}
}
