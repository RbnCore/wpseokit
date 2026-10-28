<?php
/**
 * Settings schema: defaults, type checks and cleaning. Pure logic, no storage (storage is SettingsRepository).
 *
 * Contract: docs/sozlesme.md §2.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'wpseokit_settings';

	/** Template row for a post type. */
	private const TPL_POST = array(
		'title'       => '%title% %page% %sep% %sitename%',
		'description' => '',
	);

	/** Template row for a taxonomy. */
	private const TPL_TERM = array(
		'title'       => '%term_title% archives %page% %sep% %sitename%',
		'description' => '',
	);

	/**
	 * Schema: section => key => array( type, default[, min, max | values] ).
	 * Types: bool, int, text, url, enum, list, tpl_map, url_map, type_map.
	 *
	 * @return array<string, array<string, array<int, mixed>>>
	 */
	public static function schema(): array {
		return array(
			'templates' => array(
				'sep'              => array( 'text', '-' ),
				'home_title'       => array( 'text', '%sitename% %sep% %sitedesc%' ),
				'home_description' => array( 'text', '' ),
				'search_title'     => array( 'text', 'You searched for %searchphrase% %sep% %sitename%' ),
				'notfound_title'   => array( 'text', 'Page not found %sep% %sitename%' ),
				'date_title'       => array( 'text', '%date% %page% %sep% %sitename%' ),
				'author_title'     => array( 'text', '%name% %page% %sep% %sitename%' ),
				'post_types'       => array(
					'tpl_map',
					array(
						'post' => self::TPL_POST,
						'page' => self::TPL_POST,
					),
				),
				'taxonomies'       => array(
					'tpl_map',
					array(
						'category' => self::TPL_TERM,
						'post_tag' => self::TPL_TERM,
					),
				),
			),
			'robots'    => array(
				'noindex_taxonomies'     => array( 'list', array( 'post_tag', 'post_format' ) ),
				'noindex_post_types'     => array( 'list', array() ),
				'noindex_date'           => array( 'bool', true ),
				'noindex_author_noposts' => array( 'bool', true ),
			),
			'social'    => array(
				'opengraph'         => array( 'bool', true ),
				'twitter'           => array( 'bool', true ),
				'twitter_card_type' => array( 'enum', 'summary_large_image', array( 'summary', 'summary_large_image' ) ),
				'default_image_id'  => array( 'int', 0, 0, PHP_INT_MAX ),
				'default_image_url' => array( 'url', '' ),
				'facebook_site'     => array( 'url', '' ),
				'twitter_site'      => array( 'text', '' ),
				'profiles'          => array( 'url_map', array(), array( 'facebook', 'instagram', 'linkedin', 'youtube', 'x', 'pinterest', 'wikipedia' ) ),
			),
			'schema'    => array(
				'site_represents'        => array( 'enum', 'organization', array( 'organization', 'person' ) ),
				'organization_name'      => array( 'text', '' ),
				'organization_logo_id'   => array( 'int', 0, 0, PHP_INT_MAX ),
				'organization_logo_url'  => array( 'url', '' ),
				'website_name'           => array( 'text', '' ),
				'alternate_website_name' => array( 'text', '' ),
				'person_name'            => array( 'text', '' ),
				'article_type'           => array( 'enum', 'BlogPosting', array( 'BlogPosting', 'Article', 'NewsArticle' ) ),
				'type_map'               => array( 'type_map', array() ),
			),
			'sitemap'   => array(
				'enabled'        => array( 'bool', true ),
				'per_page'       => array( 'int', 1000, 100, 2000 ),
				'include_images' => array( 'bool', true ),
				'post_types'     => array( 'list', array( 'post', 'page' ) ),
				'taxonomies'     => array( 'list', array( 'category' ) ),
				'cache_hours'    => array( 'int', 12, 1, 72 ),
			),
			'redirects' => array(
				'enabled'            => array( 'bool', true ),
				'log_404'            => array( 'bool', true ),
				'log_retention_days' => array( 'int', 30, 1, 365 ),
				'log_max_rows'       => array( 'int', 5000, 100, 50000 ),
				'ignore_bots'        => array( 'bool', true ),
			),
			'general'   => array(
				'disable_author_archive'   => array( 'bool', false ),
				'disable_attachment_pages' => array( 'bool', true ),
				'verify_google'            => array( 'text', '' ),
				'verify_bing'              => array( 'text', '' ),
				'verify_yandex'            => array( 'text', '' ),
				'verify_baidu'             => array( 'text', '' ),
				'delete_on_uninstall'      => array( 'bool', false ),
				'primary_category'         => array( 'bool', true ),
			),
			'llms'      => array(
				'enabled'    => array( 'bool', false ),
				'summary'    => array( 'text', '' ),
				'pages'      => array( 'list', array() ),
				'posts'      => array( 'list', array() ),
				'post_types' => array( 'list', array( 'post' ) ),
				'latest'     => array( 'int', 20, 0, 200 ),
				'exclude'    => array( 'list', array() ),
				'taxonomies' => array( 'list', array( 'category' ) ),
				'max_terms'  => array( 'int', 50, 0, 200 ),
			),
			'feed'      => array(
				'main_enabled'               => array( 'bool', true ),
				'content_mode'               => array( 'enum', 'inherit', array( 'inherit', 'full', 'summary' ) ),
				'items_count'                => array( 'int', 0, 0, 50 ),
				'source_link_enabled'        => array( 'bool', false ),
				'footer_text'                => array( 'text', 'The post %title% first appeared on %sitename%.' ),
				'copyright_enabled'          => array( 'bool', false ),
				'copyright_text'             => array( 'text', '' ),
				'featured_image_enabled'     => array( 'bool', false ),
				'disable_comments_feed'      => array( 'bool', false ),
				'disable_post_comments_feed' => array( 'bool', false ),
				'disable_author_feed'        => array( 'bool', false ),
				'disable_taxonomy_feed'      => array( 'bool', false ),
				'disable_search_feed'        => array( 'bool', false ),
				'disable_attachment_feed'    => array( 'bool', false ),
				'redirect_closed'            => array( 'bool', false ),
				'noindex_feed'               => array( 'bool', false ),
				'clean_head_links'           => array( 'bool', false ),
			),
		);
	}

	/** @return array<string, array<string, mixed>> */
	public static function defaults(): array {
		$out = array();
		foreach ( self::schema() as $section => $keys ) {
			foreach ( $keys as $key => $def ) {
				$out[ $section ][ $key ] = $def[1];
			}
		}
		return $out;
	}

	/**
	 * Type check of a partial update. Unknown sections and keys are not errors (sanitize() drops them).
	 *
	 * @param array<string, mixed> $partial Incoming data.
	 * @return array<string, string> Path => message; empty when valid.
	 */
	public static function validate( array $partial ): array {
		$errors = array();
		$schema = self::schema();
		foreach ( $partial as $section => $values ) {
			if ( ! isset( $schema[ $section ] ) ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				$errors[ (string) $section ] = 'A section must be an object.';
				continue;
			}
			foreach ( $values as $key => $value ) {
				if ( ! isset( $schema[ $section ][ $key ] ) ) {
					continue;
				}
				$msg = self::check_type( $schema[ $section ][ $key ], $value );
				if ( '' !== $msg ) {
					$errors[ $section . '.' . $key ] = $msg;
				}
			}
		}
		return $errors;
	}

	/**
	 * @param array<int, mixed> $def   Schema row.
	 * @param mixed             $value Incoming value.
	 */
	private static function check_type( array $def, $value ): string {
		switch ( $def[0] ) {
			case 'bool':
				return ( is_bool( $value ) || in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) ? '' : 'Expected a boolean.';
			case 'int':
				return ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,15}$/', $value ) ) ) ? '' : 'Expected an integer.';
			case 'text':
			case 'url':
				return is_string( $value ) ? '' : 'Expected a string.';
			case 'enum':
				return ( is_string( $value ) && in_array( $value, $def[2], true ) ) ? '' : 'Value is not one of the allowed options.';
			case 'list':
				return is_array( $value ) ? '' : 'Expected a list.';
			case 'tpl_map':
			case 'url_map':
			case 'type_map':
				return is_array( $value ) ? '' : 'Expected an object.';
		}
		return '';
	}

	/**
	 * Merges a partial update over a base value set and cleans every known key. Unknown keys are dropped.
	 *
	 * @param array<string, mixed>                $input Incoming partial data.
	 * @param array<string, array<string, mixed>> $base  Current full settings.
	 * @return array<string, array<string, mixed>> Full clean settings.
	 */
	public static function sanitize( array $input, array $base ): array {
		$out = array();
		foreach ( self::schema() as $section => $keys ) {
			foreach ( $keys as $key => $def ) {
				$has                     = isset( $input[ $section ] ) && is_array( $input[ $section ] ) && array_key_exists( $key, $input[ $section ] );
				$raw                     = $has ? $input[ $section ][ $key ] : ( $base[ $section ][ $key ] ?? $def[1] );
				$out[ $section ][ $key ] = self::clean( $def, $raw );
			}
		}
		return $out;
	}

	/**
	 * @param array<int, mixed> $def Schema row.
	 * @param mixed             $raw Value.
	 * @return mixed
	 */
	private static function clean( array $def, $raw ) {
		switch ( $def[0] ) {
			case 'bool':
				return (bool) filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
			case 'int':
				return max( (int) $def[2], min( (int) $def[3], (int) $raw ) );
			case 'text':
				return self::text( $raw );
			case 'url':
				return self::url( $raw );
			case 'enum':
				return ( is_string( $raw ) && in_array( $raw, $def[2], true ) ) ? $raw : $def[1];
			case 'list':
				$list = array();
				foreach ( is_array( $raw ) ? $raw : array() as $item ) {
					$slug = is_scalar( $item ) ? sanitize_key( (string) $item ) : '';
					if ( '' !== $slug ) {
						$list[ $slug ] = $slug;
					}
				}
				return array_slice( array_values( $list ), 0, 50 );
			case 'tpl_map':
				$map = array();
				foreach ( is_array( $raw ) ? $raw : array() as $slug => $row ) {
					$slug = sanitize_key( (string) $slug );
					if ( '' === $slug || ! is_array( $row ) || count( $map ) >= 50 ) {
						continue;
					}
					$map[ $slug ] = array(
						'title'       => self::text( $row['title'] ?? '' ),
						'description' => self::text( $row['description'] ?? '' ),
					);
				}
				return $map;
			case 'url_map':
				$map = array();
				foreach ( $def[2] as $name ) {
					$value = is_array( $raw ) && isset( $raw[ $name ] ) ? self::url( $raw[ $name ] ) : '';
					if ( '' !== $value ) {
						$map[ $name ] = $value;
					}
				}
				return $map;
			case 'type_map':
				$map = array();
				if ( is_array( $raw ) ) {
					foreach ( $raw as $term_id => $type ) {
						$term_id = (int) $term_id;
						if ( $term_id <= 0 ) {
							continue;
						}
						if ( ! is_string( $type ) || ! in_array( $type, array( 'Movie', 'TVSeries' ), true ) ) {
							continue;
						}
						if ( function_exists( 'term_exists' ) && ! term_exists( $term_id, 'category' ) ) {
							continue;
						}
						$map[ $term_id ] = $type;
					}
				}
				return $map;
		}
		return $def[1];
	}

	/**
	 * Plain single-line text. Deliberately NOT sanitize_text_field(): it strips percent-hex pairs (for example the
	 * "da" in %date%) and would corrupt template variables.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = wp_strip_all_tags( wp_check_invalid_utf8( (string) $value ) );
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
	}

	/**
	 * An http/https URL, or an empty string.
	 *
	 * @param mixed $value Value.
	 */
	public static function url( $value ): string {
		return is_string( $value ) ? esc_url_raw( trim( $value ), array( 'http', 'https' ) ) : '';
	}
}
