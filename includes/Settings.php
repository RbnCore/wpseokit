<?php
/**
 * Settings schema: the ONE typed declaration of `wpseokit_settings`. Cleaning, paths and storage live in the
 * AdminKit settings engine (one engine for every plugin); this class only declares the rows and keeps the thin
 * adapters the rest of the plugin and the tests already call.
 *
 * Contract: docs/sozlesme.md §2, adminkit/src/docs/ayar-motoru.md §10.3.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

use AdminKit\Core\Settings as Engine;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'wpseokit_settings';

	/** Engine slug (the plugin text domain). */
	public const SLUG = 'wpseokit';

	/** Longest single-line text value, in characters (engine default). */
	public const TEXT_MAX = Engine::TEXT_MAX;

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
	 * Schema: section => key => row (engine format). Every default is the value the plugin shipped, unchanged.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function schema(): array {
		return array(
			'templates' => array(
				'sep'              => array( 'type' => 'text', 'default' => '-' ),
				'home_title'       => array( 'type' => 'text', 'default' => '%sitename% %sep% %sitedesc%' ),
				'home_description' => array( 'type' => 'text', 'default' => '' ),
				'search_title'     => array( 'type' => 'text', 'default' => 'You searched for %searchphrase% %sep% %sitename%' ),
				'notfound_title'   => array( 'type' => 'text', 'default' => 'Page not found %sep% %sitename%' ),
				'date_title'       => array( 'type' => 'text', 'default' => '%date% %page% %sep% %sitename%' ),
				'author_title'     => array( 'type' => 'text', 'default' => '%name% %page% %sep% %sitename%' ),
				'post_types'       => array(
					'type'    => 'map',
					'key'     => 'slug',
					'default' => array(
						'post' => self::TPL_POST,
						'page' => self::TPL_POST,
					),
					'fields'  => array(
						'title'       => array( 'type' => 'text', 'default' => '' ),
						'description' => array( 'type' => 'text', 'default' => '' ),
					),
				),
				'taxonomies'       => array(
					'type'    => 'map',
					'key'     => 'slug',
					'default' => array(
						'category' => self::TPL_TERM,
						'post_tag' => self::TPL_TERM,
					),
					'fields'  => array(
						'title'       => array( 'type' => 'text', 'default' => '' ),
						'description' => array( 'type' => 'text', 'default' => '' ),
					),
				),
			),
			'robots'    => array(
				'noindex_taxonomies'     => array( 'type' => 'list', 'default' => array( 'post_tag', 'post_format' ) ),
				'noindex_post_types'     => array( 'type' => 'list', 'default' => array() ),
				'noindex_date'           => array( 'type' => 'bool', 'default' => true ),
				'noindex_author_noposts' => array( 'type' => 'bool', 'default' => true ),
			),
			'social'    => array(
				'opengraph'         => array( 'type' => 'bool', 'default' => true ),
				'twitter'           => array( 'type' => 'bool', 'default' => true ),
				'twitter_card_type' => array( 'type' => 'enum', 'default' => 'summary_large_image', 'values' => array( 'summary', 'summary_large_image' ) ),
				'default_image_id'  => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => PHP_INT_MAX ),
				'default_image_url' => array( 'type' => 'url', 'default' => '' ),
				'facebook_site'     => array( 'type' => 'url', 'default' => '' ),
				'twitter_site'      => array( 'type' => 'text', 'default' => '' ),
				'profiles'          => array(
					'type'    => 'map',
					'keys'    => array( 'facebook', 'instagram', 'linkedin', 'youtube', 'x', 'pinterest', 'wikipedia' ),
					'value'   => array( 'type' => 'url', 'default' => '' ),
					'default' => array(),
				),
			),
			'schema'    => array(
				'site_represents'        => array( 'type' => 'enum', 'default' => 'organization', 'values' => array( 'organization', 'person' ) ),
				'organization_name'      => array( 'type' => 'text', 'default' => '' ),
				'organization_logo_id'   => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => PHP_INT_MAX ),
				'organization_logo_url'  => array( 'type' => 'url', 'default' => '' ),
				'website_name'           => array( 'type' => 'text', 'default' => '' ),
				'alternate_website_name' => array( 'type' => 'text', 'default' => '' ),
				'person_name'            => array( 'type' => 'text', 'default' => '' ),
				'article_type'           => array( 'type' => 'enum', 'default' => 'BlogPosting', 'values' => array( 'BlogPosting', 'Article', 'NewsArticle' ) ),
				'type_map'               => array(
					'type'    => 'map',
					'key'     => 'int',
					'value'   => array( 'type' => 'enum', 'default' => '', 'values' => array( 'Movie', 'TVSeries' ) ),
					'filter'  => array( self::class, 'term_exists' ),
					'default' => array(),
				),
			),
			'sitemap'   => array(
				'enabled'        => array( 'type' => 'bool', 'default' => true ),
				'per_page'       => array( 'type' => 'int', 'default' => 1000, 'min' => 100, 'max' => 2000 ),
				'include_images' => array( 'type' => 'bool', 'default' => true ),
				'post_types'     => array( 'type' => 'list', 'default' => array( 'post', 'page' ) ),
				'taxonomies'     => array( 'type' => 'list', 'default' => array( 'category' ) ),
				'cache_hours'    => array( 'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 72 ),
			),
			'redirects' => array(
				'enabled'             => array( 'type' => 'bool', 'default' => true ),
				'log_404'             => array( 'type' => 'bool', 'default' => true ),
				'log_retention_days'  => array( 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365 ),
				'log_max_rows'        => array( 'type' => 'int', 'default' => 5000, 'min' => 100, 'max' => 50000 ),
				'ignore_bots'         => array( 'type' => 'bool', 'default' => true ),
				// Preferred address (docs/kapsam-ve-mimari.md §3.11). 'auto' / '' follow home_url(); nothing changes until edited.
				'preferred_scheme'    => array( 'type' => 'enum', 'default' => 'auto', 'values' => array( 'auto', 'https', 'http' ) ),
				'preferred_host'      => array( 'type' => 'host', 'default' => '' ),
				'php_fallback'        => array( 'type' => 'bool', 'default' => false ),
				'trust_proxy_headers' => array( 'type' => 'bool', 'default' => false ),
				// The forced redirect of the applied preferred address (docs/kapsam-ve-mimari.md §3.11). Off until the
				// address is applied; the dead-man switch brings the previous address back when it is not confirmed.
				'enforce_address'     => array( 'type' => 'bool', 'default' => false ),
			),
			'general'   => array(
				'disable_author_archive'   => array( 'type' => 'bool', 'default' => false ),
				'disable_attachment_pages' => array( 'type' => 'bool', 'default' => true ),
				'verify_google'            => array( 'type' => 'text', 'default' => '' ),
				'verify_bing'              => array( 'type' => 'text', 'default' => '' ),
				'verify_yandex'            => array( 'type' => 'text', 'default' => '' ),
				'verify_baidu'             => array( 'type' => 'text', 'default' => '' ),
				'delete_on_uninstall'      => array( 'type' => 'bool', 'default' => false ),
				'primary_category'         => array( 'type' => 'bool', 'default' => true ),
			),
			'llms'      => array(
				'enabled'    => array( 'type' => 'bool', 'default' => false ),
				'summary'    => array( 'type' => 'text', 'default' => '' ),
				'pages'      => array( 'type' => 'list', 'default' => array() ),
				'posts'      => array( 'type' => 'list', 'default' => array() ),
				'post_types' => array( 'type' => 'list', 'default' => array( 'post' ) ),
				'latest'     => array( 'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 200 ),
				'exclude'    => array( 'type' => 'list', 'default' => array() ),
				'taxonomies' => array( 'type' => 'list', 'default' => array( 'category' ) ),
				'max_terms'  => array( 'type' => 'int', 'default' => 50, 'min' => 0, 'max' => 200 ),
			),
			'feed'      => array(
				'main_enabled'               => array( 'type' => 'bool', 'default' => true ),
				'content_mode'               => array( 'type' => 'enum', 'default' => 'inherit', 'values' => array( 'inherit', 'full', 'summary' ) ),
				'items_count'                => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 50 ),
				'source_link_enabled'        => array( 'type' => 'bool', 'default' => false ),
				'footer_text'                => array( 'type' => 'text', 'default' => 'The post %title% first appeared on %sitename%.' ),
				'copyright_enabled'          => array( 'type' => 'bool', 'default' => false ),
				'copyright_text'             => array( 'type' => 'text', 'default' => '' ),
				'featured_image_enabled'     => array( 'type' => 'bool', 'default' => false ),
				'disable_comments_feed'      => array( 'type' => 'bool', 'default' => false ),
				'disable_post_comments_feed' => array( 'type' => 'bool', 'default' => false ),
				'disable_author_feed'        => array( 'type' => 'bool', 'default' => false ),
				'disable_taxonomy_feed'      => array( 'type' => 'bool', 'default' => false ),
				'disable_search_feed'        => array( 'type' => 'bool', 'default' => false ),
				'disable_attachment_feed'    => array( 'type' => 'bool', 'default' => false ),
				'redirect_closed'            => array( 'type' => 'bool', 'default' => false ),
				'noindex_feed'               => array( 'type' => 'bool', 'default' => false ),
				'clean_head_links'           => array( 'type' => 'bool', 'default' => false ),
			),
			'score'     => array(
				'daily_recalc'  => array( 'type' => 'bool', 'default' => true ),
				'cache_hours'   => array( 'type' => 'int', 'default' => 6, 'min' => 1, 'max' => 48 ),
				'http_timeout'  => array( 'type' => 'int', 'default' => 5, 'min' => 2, 'max' => 15 ),
				'batch_seconds' => array( 'type' => 'int', 'default' => 20, 'min' => 5, 'max' => 60 ),
			),
			'reports'   => array(
				'daily_enabled'  => array( 'type' => 'bool', 'default' => true ),
				'retention_days' => array( 'type' => 'int', 'default' => 400, 'min' => 30, 'max' => 1095 ),
			),
		);
	}

	/** Registers the schema with the AdminKit settings engine (called once from Plugin::boot()). */
	public static function register(): void {
		// boot() can legitimately run twice in one process (a test harness, WP-CLI, a second plugins_loaded tick);
		// the first declaration wins and re-registering would only put a notice in debug.log.
		if ( Engine::registered( self::SLUG ) ) {
			return;
		}
		Engine::register(
			self::SLUG,
			array(
				'option'     => self::OPTION,
				'schema'     => array( self::class, 'schema' ),
				'autoload'   => true,
				// The engine cleans every known field on read and drops unknown keys: the contract this plugin always had.
				'read_clean' => true,
				// No capability here: the REST route's permission_callback and the admin-post guard already decide. Cron
				// and CLI writes (preferred-address dead-man switch, Yoast import) must keep working without a user.
				'capability' => '',
				'log'        => array( Logs::class, 'settings_changed' ),
			)
		);
	}

	private static function ensure(): void {
		self::register();
	}

	/** @return array<string, array<string, mixed>> */
	public static function defaults(): array {
		self::ensure();
		return Engine::defaults( self::SLUG );
	}

	/**
	 * Type check of a partial update. Unknown sections and keys are not errors (they are dropped on save).
	 *
	 * @param array<string, mixed> $partial Incoming data.
	 * @return array<string, string> Path => message; empty when valid.
	 */
	public static function validate( array $partial ): array {
		self::ensure();
		return Engine::validate( self::SLUG, $partial );
	}

	/**
	 * Merges a partial update over a base value set and cleans every known key (dry run, nothing is written).
	 *
	 * @param array<string, mixed>                $input Incoming partial data.
	 * @param array<string, array<string, mixed>> $base  Base settings (defaults or the current values).
	 * @return array<string, array<string, mixed>> Full clean settings.
	 */
	public static function sanitize( array $input, array $base ): array {
		self::ensure();
		return (array) Engine::preview( self::SLUG, $input, array( 'base' => $base ) )['settings'];
	}

	/** Passive `map` filter: a term must exist in the category taxonomy. */
	public static function term_exists( $key, $value ): bool {
		return ! function_exists( 'term_exists' ) || (bool) term_exists( (int) $key, 'category' );
	}

	/**
	 * Plain single-line text. Deliberately NOT sanitize_text_field(): it strips percent-hex pairs (for example the
	 * "da" in %date%) and would corrupt template variables.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( $value ): string {
		return Engine::clean_text( $value, self::TEXT_MAX );
	}

	/**
	 * A bare host name (letters, digits, dots, hyphens; lower case; no scheme, path, port or user), or an empty string.
	 *
	 * @param mixed $value Value.
	 */
	public static function host( $value ): string {
		return Engine::clean_host( $value );
	}

	/**
	 * An http/https URL, or an empty string.
	 *
	 * @param mixed $value Value.
	 */
	public static function url( $value ): string {
		return Engine::clean_url( $value );
	}
}
