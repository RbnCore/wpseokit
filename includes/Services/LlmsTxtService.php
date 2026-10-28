<?php
/**
 * `/llms.txt`: a plain-text site summary with links for AI systems (format: https://llmstxt.org).
 *
 * Off by default; while off the request is not touched and WordPress answers 404. Generated on the fly (no file is
 * written), cached in one transient, and cleared whenever content, terms or settings change. Noindex and
 * password-protected content never appears. REST: `/llms/preview` (GET saved settings, POST unsaved ones) and
 * `/llms/flush`. Contract: docs/sozlesme.md §2.1 and §4.5, behaviour: docs/kapsam-ve-mimari.md §3.7.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class LlmsTxtService {

	public const REQUEST   = 'llms.txt';
	public const TRANSIENT = 'wpseokit_llms';

	/** Cache lifetime in seconds (changes clear it earlier). */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/** Longest link note, in characters. */
	private const NOTE_MAX = 160;

	/** Rows read from the database per round while filtering candidates. */
	private const BATCH = 50;

	/** Rounds per list, so a site where almost everything is noindex cannot loop for long. */
	private const MAX_ROUNDS = 20;

	/** Yoast `wpseo_llmstxt` keys that each hold one page ID, in the order Yoast lists them. */
	private const YOAST_PAGE_KEYS = array( 'about_us_page', 'contact_page', 'terms_page', 'privacy_policy_page', 'shop_page' );

	public static function init(): void {
		add_action( 'parse_request', array( self::class, 'serve' ), 1 );
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		add_filter( 'wpseokit_status', array( self::class, 'filter_status' ) );

		foreach ( array( 'save_post', 'deleted_post', 'transition_post_status', 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
		foreach ( array( 'blogname', 'blogdescription', 'permalink_structure', 'category_base', 'wpseokit_settings' ) as $option ) {
			add_action( 'update_option_' . $option, array( self::class, 'flush' ) );
		}
		add_action( 'add_option_wpseokit_settings', array( self::class, 'flush' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_for_meta' ), 10, 3 );
		}
	}

	/** Public address of the file (the front page path is respected, so a sub-folder install works). */
	public static function url(): string {
		return home_url( '/' . self::REQUEST );
	}

	/* ------------------------------------------------------------------ request */

	/**
	 * `parse_request`: answers `/llms.txt` when the tool is on. When it is off nothing happens here.
	 *
	 * @param \WP $wp Request object.
	 */
	public static function serve( $wp ): void {
		if ( ! $wp instanceof \WP || self::REQUEST !== $wp->request ) {
			return;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! (bool) SettingsRepository::get( 'llms.enabled', false ) || ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$body = self::content();
		$etag = '"' . md5( $body ) . '"';
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'ETag: ' . $etag );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			status_header( 304 );
			exit;
		}
		header( 'Content-Length: ' . strlen( $body ) );
		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text, not HTML.
		}
		exit;
	}

	/* ------------------------------------------------------------------ content */

	/** The file body for the saved settings, from the cache when possible. */
	public static function content(): string {
		$cached = get_transient( self::TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$body = self::generate( SettingsRepository::section( 'llms' ) )['content'];
		set_transient( self::TRANSIENT, $body, self::TTL );
		return $body;
	}

	/** Clears the cache. Hooked to every change that can alter the file. */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Meta hook: only our own and the SEO description/robots keys matter.
	 *
	 * @param int|string $meta_id Meta ID (unused).
	 * @param int|string $object_id Object ID (unused).
	 * @param string     $meta_key Meta key.
	 */
	public static function flush_for_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id, $object_id );
		if ( is_string( $meta_key ) && ( 0 === strpos( $meta_key, '_wpseokit_' ) || 0 === strpos( $meta_key, 'wpseokit_' ) ) ) {
			self::flush();
		}
	}

	/**
	 * Builds the file from a settings section. Never reads or writes the cache.
	 *
	 * @param array<string, mixed> $cfg The `llms` section (clean, every key present).
	 * @return array{content: string, sections: array<int, array{key: string, title: string, count: int}>, skipped: array{noindex: int, password: int, other: int}}
	 */
	public static function generate( array $cfg ): array {
		$skipped = array(
			'noindex'  => 0,
			'password' => 0,
			'other'    => 0,
		);
		$exclude = self::ids( $cfg['exclude'] ?? array() );
		$manual  = self::ids( $cfg['posts'] ?? array() );

		$pages = array();
		foreach ( self::ids( $cfg['pages'] ?? array() ) as $id ) {
			$entry = in_array( $id, $exclude, true ) ? null : self::post_entry( get_post( $id ), $skipped );
			if ( $entry ) {
				$pages[] = $entry;
			}
		}

		$posts = array();
		foreach ( $manual as $id ) {
			$entry = in_array( $id, $exclude, true ) ? null : self::post_entry( get_post( $id ), $skipped );
			if ( $entry ) {
				$posts[ $id ] = $entry;
			}
		}
		foreach ( self::latest( $cfg, array_merge( $exclude, $manual, self::ids( $cfg['pages'] ?? array() ) ), $skipped ) as $id => $entry ) {
			$posts[ $id ] = $entry;
		}

		$terms     = self::term_entries( $cfg, $skipped );
		$term_only = array() !== $terms && array( 'category' ) === array_values( (array) ( $cfg['taxonomies'] ?? array() ) );

		$sections = array(
			array(
				'key'   => 'pages',
				'title' => __( 'Pages', 'wpseokit' ),
				'items' => $pages,
			),
			array(
				'key'   => 'posts',
				'title' => __( 'Posts', 'wpseokit' ),
				'items' => array_values( $posts ),
			),
			array(
				'key'   => 'categories',
				'title' => $term_only ? __( 'Categories', 'wpseokit' ) : __( 'Topics', 'wpseokit' ),
				'items' => $terms,
			),
		);

		$name    = Variables::plain( (string) get_option( 'blogname' ) );
		$name    = '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$summary = self::one_line( Variables::plain( (string) ( $cfg['summary'] ?? '' ) ) );
		if ( '' === $summary ) {
			$summary = self::one_line( Variables::plain( (string) get_option( 'blogdescription' ) ) );
		}

		$lines = array( '# ' . self::one_line( $name ) );
		if ( '' !== $summary ) {
			$lines[] = '';
			$lines[] = '> ' . $summary;
		}
		$counts = array();
		foreach ( $sections as $section ) {
			if ( array() === $section['items'] ) {
				continue;
			}
			$lines[]  = '';
			$lines[]  = '## ' . $section['title'];
			$lines[]  = '';
			$counts[] = array(
				'key'   => $section['key'],
				'title' => $section['title'],
				'count' => count( $section['items'] ),
			);
			foreach ( $section['items'] as $item ) {
				$lines[] = '- [' . self::escape_text( $item['name'] ) . '](' . self::escape_url( $item['url'] ) . ')' . ( '' !== $item['note'] ? ': ' . $item['note'] : '' );
			}
		}

		return array(
			'content'  => implode( "\n", $lines ) . "\n",
			'sections' => $counts,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Yoast `wpseo_llmstxt` page IDs as the WPSeoKit `llms.pages` list (pure function, see docs §3.7).
	 *
	 * @param array<string, mixed> $yoast Value of the Yoast `wpseo_llmstxt` option.
	 * @return int[]
	 */
	public static function map_yoast( array $yoast ): array {
		$ids = array();
		foreach ( self::YOAST_PAGE_KEYS as $key ) {
			$ids[] = $yoast[ $key ] ?? 0;
		}
		$other = $yoast['other_included_pages'] ?? array();
		return self::ids( array_merge( $ids, is_array( $other ) ? $other : array() ) );
	}

	/* ------------------------------------------------------------------ candidates */

	/**
	 * Newest content of the chosen post types, filtered, up to `latest` items.
	 *
	 * @param array<string, mixed> $cfg     The `llms` section.
	 * @param int[]                $not_in  Never returned.
	 * @param array<string, int>   $skipped Counters (by reference).
	 * @return array<int, array{name: string, url: string, note: string}> Post ID => entry.
	 */
	private static function latest( array $cfg, array $not_in, array &$skipped ): array {
		$limit = (int) ( $cfg['latest'] ?? 0 );
		$types = array();
		foreach ( (array) ( $cfg['post_types'] ?? array() ) as $type ) {
			if ( is_string( $type ) && post_type_exists( $type ) && 'attachment' !== $type && is_post_type_viewable( $type ) ) {
				$types[] = $type;
			}
		}
		$out = array();
		if ( $limit <= 0 || array() === $types ) {
			return $out;
		}
		for ( $round = 1; $round <= self::MAX_ROUNDS && count( $out ) < $limit; $round++ ) {
			$query = new \WP_Query(
				array(
					'post_type'              => $types,
					'post_status'            => 'publish',
					'has_password'           => false,
					'post__not_in'           => $not_in,
					'orderby'                => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'posts_per_page'         => self::BATCH,
					'paged'                  => $round,
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => true,
				)
			);
			foreach ( $query->posts as $post ) {
				$entry = self::post_entry( $post, $skipped );
				if ( $entry && count( $out ) < $limit ) {
					$out[ $post->ID ] = $entry;
				}
			}
			if ( count( $query->posts ) < self::BATCH ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * One post as a link entry, or null when it must stay out of the file.
	 *
	 * @param mixed              $post    Post or null.
	 * @param array<string, int> $skipped Counters (by reference).
	 * @return array{name: string, url: string, note: string}|null
	 */
	private static function post_entry( $post, array &$skipped ): ?array {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			++$skipped['other'];
			return null;
		}
		if ( '' !== (string) $post->post_password ) {
			++$skipped['password'];
			return null;
		}
		$ctx = array(
			'type'      => 'post',
			'front'     => false,
			'post'      => $post,
			'paged'     => 1,
			'max_pages' => 1,
		);
		if ( RobotsService::is_noindex( $ctx ) ) {
			++$skipped['noindex'];
			return null;
		}
		$name = self::one_line( Variables::plain( (string) get_the_title( $post ) ) );
		$url  = (string) get_permalink( $post );
		if ( '' === $name || '' === $url ) {
			++$skipped['other'];
			return null;
		}
		return array(
			'name' => $name,
			'url'  => $url,
			'note' => self::note( MetaService::description( $ctx ) ),
		);
	}

	/**
	 * Terms of the chosen taxonomies that have content and are not noindex.
	 *
	 * @param array<string, mixed> $cfg     The `llms` section.
	 * @param array<string, int>   $skipped Counters (by reference).
	 * @return array<int, array{name: string, url: string, note: string}>
	 */
	private static function term_entries( array $cfg, array &$skipped ): array {
		$limit = (int) ( $cfg['max_terms'] ?? 0 );
		$out   = array();
		foreach ( (array) ( $cfg['taxonomies'] ?? array() ) as $taxonomy ) {
			if ( $limit <= 0 || count( $out ) >= $limit ) {
				break;
			}
			if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}
			for ( $round = 0; $round < self::MAX_ROUNDS && count( $out ) < $limit; $round++ ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => true,
						'orderby'    => 'count',
						'order'      => 'DESC',
						'number'     => self::BATCH,
						'offset'     => $round * self::BATCH,
					)
				);
				if ( ! is_array( $terms ) || array() === $terms ) {
					break;
				}
				foreach ( $terms as $term ) {
					if ( ! $term instanceof \WP_Term || count( $out ) >= $limit ) {
						continue;
					}
					$ctx = array(
						'type'      => 'term',
						'term'      => $term,
						'paged'     => 1,
						'max_pages' => 1,
					);
					if ( RobotsService::is_noindex( $ctx ) ) {
						++$skipped['noindex'];
						continue;
					}
					$url  = get_term_link( $term );
					$name = self::one_line( Variables::plain( $term->name ) );
					if ( is_wp_error( $url ) || '' === $name ) {
						++$skipped['other'];
						continue;
					}
					$out[] = array(
						'name' => $name,
						'url'  => (string) $url,
						'note' => self::note( MetaService::description( $ctx ) ),
					);
				}
				if ( count( $terms ) < self::BATCH ) {
					break;
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ text helpers */

	/**
	 * Positive, unique integers from a list of IDs (strings or numbers).
	 *
	 * @param mixed $list Raw list.
	 * @return int[]
	 */
	private static function ids( $list ): array {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $value ) {
			$id = is_scalar( $value ) ? (int) $value : 0;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/** Collapses every whitespace run (newlines included) to one space. */
	private static function one_line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Link note: one line, at most NOTE_MAX characters. */
	private static function note( string $text ): string {
		$text = self::one_line( $text );
		return mb_strlen( $text ) > self::NOTE_MAX ? rtrim( mb_substr( $text, 0, self::NOTE_MAX - 1 ) ) . "\u{2026}" : $text;
	}

	/** Link text: Markdown brackets and backslashes are escaped. */
	private static function escape_text( string $text ): string {
		return str_replace( array( '\\', '[', ']' ), array( '\\\\', '\\[', '\\]' ), $text );
	}

	/** Link target: characters that would end the Markdown link early are percent-encoded. */
	private static function escape_url( string $url ): string {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $url );
	}

	/* ------------------------------------------------------------------ status and REST */

	/**
	 * @param mixed $status Status payload.
	 * @return mixed
	 */
	public static function filter_status( $status ) {
		if ( is_array( $status ) ) {
			$status['llms'] = array(
				'enabled' => (bool) SettingsRepository::get( 'llms.enabled', false ),
				'url'     => self::url(),
			);
		}
		return $status;
	}

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/llms/preview',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_preview' ),
					'permission_callback' => Rest::permission(),
					'args'                => array(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_preview' ),
					'permission_callback' => Rest::permission(),
					'args'                => array(
						'llms' => array(
							'type'     => 'object',
							'required' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/llms/flush',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function (): \WP_REST_Response {
					self::flush();
					return new \WP_REST_Response( array( 'flushed' => true ) );
				},
				'permission_callback' => Rest::permission(),
				'args'                => array(),
			)
		);
	}

	/**
	 * Preview. GET uses the saved settings; POST may carry `{ "llms": { … } }` to preview unsaved changes.
	 * Never saves and never touches the cache.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_preview( \WP_REST_Request $request ) {
		$cfg  = SettingsRepository::section( 'llms' );
		$body = $request->get_json_params();
		if ( is_array( $body ) && isset( $body['llms'] ) ) {
			if ( ! is_array( $body['llms'] ) ) {
				return new \WP_Error( 'wpseokit_bad_request', __( 'Send the llms settings as an object.', 'wpseokit' ), array( 'status' => 400 ) );
			}
			$errors = Settings::validate( array( 'llms' => $body['llms'] ) );
			if ( $errors ) {
				return new \WP_Error(
					'wpseokit_invalid_settings',
					__( 'Some settings have the wrong type.', 'wpseokit' ),
					array(
						'status' => 400,
						'errors' => $errors,
					)
				);
			}
			$cfg = SettingsRepository::sanitize( array( 'llms' => $body['llms'] ) )['llms'];
		}

		$result   = self::generate( $cfg );
		$warnings = array();
		if ( empty( $cfg['enabled'] ) ) {
			$warnings[] = __( 'The tool is off, so the address answers 404 until you turn it on.', 'wpseokit' );
		}
		if ( ! get_option( 'blog_public' ) ) {
			$warnings[] = __( 'This site asks search engines not to index it (Settings > Reading).', 'wpseokit' );
		}
		if ( '' === trim( (string) $cfg['summary'] ) && '' === trim( (string) get_option( 'blogdescription' ) ) ) {
			$warnings[] = __( 'There is no summary: write one, or set the site tagline.', 'wpseokit' );
		}
		if ( array() === $result['sections'] ) {
			$warnings[] = __( 'No page or post is listed yet.', 'wpseokit' );
		}
		return new \WP_REST_Response(
			array(
				'enabled'  => ! empty( $cfg['enabled'] ),
				'url'      => self::url(),
				'content'  => $result['content'],
				'bytes'    => strlen( $result['content'] ),
				'lines'    => substr_count( $result['content'], "\n" ),
				'sections' => $result['sections'],
				'skipped'  => $result['skipped'],
				'warnings' => $warnings,
			)
		);
	}
}
