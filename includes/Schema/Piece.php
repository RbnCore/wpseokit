<?php
/**
 * Abstract schema piece.
 *
 * Each Schema.org node in the JSON-LD `@graph` inherits from this class.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Schema;

defined( 'ABSPATH' ) || exit;

abstract class Piece {

	/**
	 * Schema context.
	 *
	 * @var array<string, mixed>
	 */
	protected array $context;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $context Page/request context.
	 */
	public function __construct( array $context = array() ) {
		$this->context = $context;
	}

	/**
	 * Whether this piece should be included in the schema graph.
	 */
	abstract public function is_needed(): bool;

	/**
	 * Generate the schema piece data.
	 *
	 * @return array<string, mixed>|null
	 */
	abstract public function generate(): ?array;
}
