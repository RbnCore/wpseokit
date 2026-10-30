<?php
/**
 * Redirect CSV: export and import (`from,to,type`). A cell that starts with `=`, `+`, `-` or `@` (or a tab or carriage
 * return) is written with a leading `'` so a spreadsheet cannot run it as a formula; the import takes that `'` off
 * again. Import reads at most 1 MB of UTF-8, saves in batches of 500 rows, skips addresses that already have a rule
 * (never overwrites) and reports every rejected line. Contract: docs/sozlesme.md §4.5.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use AdminKit\Core\Security\Guard;

use Wpseokit\Repositories\RedirectRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class CsvService {

	/** Largest file or text accepted, in bytes. */
	public const MAX_BYTES = 1048576;

	/** Rows saved before the redirect map is rebuilt. */
	public const BATCH = 500;

	/** Longest error list returned. */
	private const MAX_ERRORS = 100;

	/** Header names that mark the first line as a header. */
	private const HEADERS = array( 'from', 'source', 'source_path', 'old', 'url' );

	public static function init(): void {
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_csv' ), 10, 3 );
	}

	/**
	 * Sends the export as plain CSV instead of JSON: a response whose `Content-Type` is `text/csv` is printed as is.
	 *
	 * @param bool                      $served  Whether the request was already served.
	 * @param \WP_HTTP_Response|mixed   $result  Response.
	 * @param \WP_REST_Request          $request Request.
	 */
	public static function serve_csv( $served, $result, $request ): bool {
		if ( $served || ! $result instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request || '/' . Rest::NAMESPACE . '/redirects/export' !== $request->get_route() ) {
			return (bool) $served;
		}
		if ( 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return false;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV text; each cell is protected by cell().
		return true;
	}

	/** Makes one cell safe for a spreadsheet and quotes it for CSV when needed. */
	public static function cell( string $value ): string {
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			$value = "'" . $value;
		}
		return preg_match( '/[",\r\n]/', $value ) ? '"' . str_replace( '"', '""', $value ) . '"' : $value;
	}

	/** Undoes cell(): takes the protective `'` off a cell that it protected. */
	public static function uncell( string $value ): string {
		return ( strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( "=+-@\t\r", $value[1] ) ) ? substr( $value, 1 ) : $value;
	}

	/** All rules as CSV text (header `from,to,type,active`). */
	public static function export(): string {
		$out  = "from,to,type,active\r\n";
		$page = 1;
		do {
			$res = RedirectRepository::query(
				array(
					'page'     => $page,
					'per_page' => 100,
					'orderby'  => 'id',
					'order'    => 'asc',
				)
			);
			foreach ( $res['items'] as $rule ) {
				$out .= implode( ',', array( self::cell( $rule->source_path ), self::cell( $rule->target_url ), (string) $rule->status_code, $rule->is_active ? '1' : '0' ) ) . "\r\n";
			}
			++$page;
		} while ( count( $res['items'] ) === 100 );
		return $out;
	}

	/**
	 * Imports CSV text. Columns: from, to, type (301 by default), active (1 by default). The first line may be a header.
	 *
	 * @return array{added: int, updated: int, skipped: int, errors: array<int, array{line: int, message: string}>}|\WP_Error
	 */
	public static function import( string $csv ) {
		if ( strlen( $csv ) > self::MAX_BYTES ) {
			return new \WP_Error( 'wpseokit_too_large', __( 'The CSV is larger than 1 MB.', 'wpseokit' ), array( 'status' => 400 ) );
		}
		if ( ! mb_check_encoding( $csv, 'UTF-8' ) ) {
			return new \WP_Error( 'wpseokit_bad_encoding', __( 'The CSV must be UTF-8.', 'wpseokit' ), array( 'status' => 400 ) );
		}
		$csv = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$fh  = fopen( 'php://temp', 'r+' );
		if ( false === $fh ) {
			return new \WP_Error( 'wpseokit_io', __( 'The CSV could not be read.', 'wpseokit' ), array( 'status' => 500 ) );
		}
		fwrite( $fh, $csv );
		rewind( $fh );

		$added   = 0;
		$skipped = 0;
		$errors  = array();
		$pending = 0;
		$line    = 0;
		while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			++$line;
			if ( array( null ) === $row ) {
				continue; // Blank line.
			}
			$row = array_map( static fn( $c ): string => self::uncell( trim( (string) $c ) ), $row );
			if ( 1 === $line && in_array( strtolower( $row[0] ), self::HEADERS, true ) ) {
				continue;
			}
			$rule = RedirectService::create(
				array(
					'source_path' => $row[0],
					'target_url'  => $row[1] ?? '',
					'status_code' => ( isset( $row[2] ) && '' !== $row[2] ) ? (int) $row[2] : 301,
					'is_active'   => ! isset( $row[3] ) || '' === $row[3] || '0' !== $row[3],
				),
				false
			);
			if ( is_wp_error( $rule ) ) {
				if ( 'wpseokit_conflict' === $rule->get_error_code() ) {
					++$skipped;
				} elseif ( count( $errors ) < self::MAX_ERRORS ) {
					$errors[] = array(
						'line'    => $line,
						'message' => $rule->get_error_message(),
					);
				}
				continue;
			}
			++$added;
			if ( 0 === ++$pending % self::BATCH ) {
				RedirectService::rebuild();
			}
		}
		fclose( $fh );
		if ( $pending > 0 ) {
			RedirectService::rebuild();
		}
		return array(
			'added'   => $added,
			'updated' => 0,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/redirects/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_import' ),
				'permission_callback' => Guard::rest(),
				'args'                => array(
					'csv' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/redirects/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_export' ),
				'permission_callback' => Guard::rest(),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function rest_import( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$csv   = '';
		if ( ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( (string) $files['file']['tmp_name'] ) ) {
			if ( (int) ( $files['file']['size'] ?? 0 ) > self::MAX_BYTES ) {
				return new \WP_Error( 'wpseokit_too_large', __( 'The CSV is larger than 1 MB.', 'wpseokit' ), array( 'status' => 400 ) );
			}
			$csv = (string) file_get_contents( (string) $files['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload, size checked above.
		} else {
			$json = $request->get_json_params();
			$csv  = is_array( $json ) && isset( $json['csv'] ) ? (string) $json['csv'] : (string) $request['csv'];
		}
		if ( '' === trim( $csv ) ) {
			return new \WP_Error( 'wpseokit_bad_request', __( 'Send a CSV file or a csv text.', 'wpseokit' ), array( 'status' => 400 ) );
		}
		$result = self::import( $csv );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	public static function rest_export(): \WP_REST_Response {
		$resp = new \WP_REST_Response( self::export() );
		$resp->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$resp->header( 'Content-Disposition', 'attachment; filename="redirects.csv"' );
		return $resp;
	}
}
