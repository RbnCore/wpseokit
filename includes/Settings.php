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
use Wpseokit\Services\Variables;

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
	 * Free-text fields whose value can carry THIS site's own identity (its name, host or person).
	 *
	 * The value decides, not the field (WPRJ-719): a template written with variables (`%sitename% %sep% %title%`) is
	 * portable — the variables are filled in on the target site — while a plain-text value is the site's own prose and
	 * is marked `site_specific`, so the settings file neither exports nor imports it (AdminKit `Settings::travel()`:
	 * `site_specific` wins over the section's `portable`). Without this, a description such as “Film Makinesi'nde en
	 * yeni filmler …” travelled to the next site.
	 *
	 * Known limit: a `map` field (post-type/taxonomy templates) is ONE travel row, so an admin-written prose row
	 * inside it travels with the rest; the value-level rule cannot split a map.
	 *
	 * @var string[]
	 */
	private const SITE_TEXT = array(
		'templates.home_title',
		'templates.home_description',
		'templates.search_title',
		'templates.notfound_title',
		'templates.date_title',
		'templates.author_title',
		'schema.organization_name',
		'schema.website_name',
		'schema.alternate_website_name',
		'schema.person_name',
		'social.facebook_site',
		'social.twitter_site',
		'llms.summary',
		'feed.footer_text',
		'feed.copyright_text',
	);

	/**
	 * Schema: section => key => row (engine format). Every default is the value the plugin shipped, unchanged.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function schema(): array {
		// Bölüm bayrağı altındaki alanlara miras kalır (ayar dosyasıyla taşıma, AdminKit): `portable` taşınır,
		// `site_specific` (site adresi, medya/sayfa kimliği) ve `risky` (kilitlenme riski) alan istisnalarıdır.
		// Serbest metin alanlarının bayrağı DEĞERE bakar: `site_text()` aşağıda tamamlar.
		$schema = array(
			'templates' => array(
				'portable' => true,
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
				'portable' => true,
				'noindex_taxonomies'     => array( 'type' => 'list', 'default' => array( 'post_tag', 'post_format' ) ),
				'noindex_post_types'     => array( 'type' => 'list', 'default' => array() ),
				'noindex_date'           => array( 'type' => 'bool', 'default' => true ),
				'noindex_author_noposts' => array( 'type' => 'bool', 'default' => true ),
			),
			'social'    => array(
				'portable' => true,
				'opengraph'         => array( 'type' => 'bool', 'default' => true ),
				'twitter'           => array( 'type' => 'bool', 'default' => true ),
				'twitter_card_type' => array( 'type' => 'enum', 'default' => 'summary_large_image', 'values' => array( 'summary', 'summary_large_image' ) ),
				'default_image_id'  => array( 'type' => 'int', 'site_specific' => true, 'default' => 0, 'min' => 0, 'max' => PHP_INT_MAX ),
				'default_image_url' => array( 'type' => 'url', 'site_specific' => true, 'default' => '' ),
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
				'portable' => true,
				'site_represents'        => array( 'type' => 'enum', 'default' => 'organization', 'values' => array( 'organization', 'person' ) ),
				'organization_name'      => array( 'type' => 'text', 'default' => '' ),
				'organization_logo_id'   => array( 'type' => 'int', 'site_specific' => true, 'default' => 0, 'min' => 0, 'max' => PHP_INT_MAX ),
				'organization_logo_url'  => array( 'type' => 'url', 'site_specific' => true, 'default' => '' ),
				'website_name'           => array( 'type' => 'text', 'default' => '' ),
				'alternate_website_name' => array( 'type' => 'text', 'default' => '' ),
				'person_name'            => array( 'type' => 'text', 'default' => '' ),
				'article_type'           => array( 'type' => 'enum', 'default' => 'BlogPosting', 'values' => array( 'BlogPosting', 'Article', 'NewsArticle' ) ),
				'type_map'               => array(
					'type'          => 'map',
					'site_specific' => true,
					'key'           => 'int',
					'value'         => array( 'type' => 'enum', 'default' => '', 'values' => array( 'Movie', 'TVSeries' ) ),
					'filter'        => array( self::class, 'term_exists' ),
					'default' => array(),
				),
			),
			'sitemap'   => array(
				'portable' => true,
				'enabled'        => array( 'type' => 'bool', 'default' => true ),
				'per_page'       => array( 'type' => 'int', 'default' => 1000, 'min' => 100, 'max' => 2000 ),
				'include_images' => array( 'type' => 'bool', 'default' => true ),
				'post_types'     => array( 'type' => 'list', 'default' => array( 'post', 'page' ) ),
				'taxonomies'     => array( 'type' => 'list', 'default' => array( 'category' ) ),
				'cache_hours'    => array( 'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 72 ),
			),
			'redirects' => array(
				'portable' => true,
				'enabled'             => array( 'type' => 'bool', 'default' => true ),
				'log_404'             => array( 'type' => 'bool', 'default' => true ),
				'log_retention_days'  => array( 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365 ),
				'log_max_rows'        => array( 'type' => 'int', 'default' => 5000, 'min' => 100, 'max' => 50000 ),
				'ignore_bots'         => array( 'type' => 'bool', 'default' => true ),
				// Preferred address (docs/kapsam-ve-mimari.md §3.11). 'auto' / '' follow home_url(); nothing changes until edited.
				'preferred_scheme'    => array( 'type' => 'enum', 'default' => 'auto', 'values' => array( 'auto', 'https', 'http' ) ),
				'preferred_host'      => array( 'type' => 'host', 'site_specific' => true, 'default' => '' ),
				'php_fallback'        => array( 'type' => 'bool', 'risky' => true, 'default' => false ),
				'trust_proxy_headers' => array( 'type' => 'bool', 'risky' => true, 'default' => false ),
				// The forced redirect of the applied preferred address (docs/kapsam-ve-mimari.md §3.11). Off until the
				// address is applied; the dead-man switch brings the previous address back when it is not confirmed.
				'enforce_address'     => array( 'type' => 'bool', 'risky' => true, 'default' => false ),
			),
			'general'   => array(
				'portable' => true,
				'disable_author_archive'   => array( 'type' => 'bool', 'default' => false ),
				'disable_attachment_pages' => array( 'type' => 'bool', 'default' => true ),
				'verify_google'            => array( 'type' => 'text', 'site_specific' => true, 'default' => '' ),
				'verify_bing'              => array( 'type' => 'text', 'site_specific' => true, 'default' => '' ),
				'verify_yandex'            => array( 'type' => 'text', 'site_specific' => true, 'default' => '' ),
				'verify_baidu'             => array( 'type' => 'text', 'site_specific' => true, 'default' => '' ),
				'delete_on_uninstall'      => array( 'type' => 'bool', 'default' => false ),
				'primary_category'         => array( 'type' => 'bool', 'default' => true ),
			),
			'llms'      => array(
				'portable' => true,
				'enabled'    => array( 'type' => 'bool', 'default' => false ),
				'summary'    => array( 'type' => 'text', 'default' => '' ),
				'pages'      => array( 'type' => 'list', 'site_specific' => true, 'default' => array() ),
				'posts'      => array( 'type' => 'list', 'site_specific' => true, 'default' => array() ),
				'post_types' => array( 'type' => 'list', 'default' => array( 'post' ) ),
				'latest'     => array( 'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 200 ),
				'exclude'    => array( 'type' => 'list', 'site_specific' => true, 'default' => array() ),
				'taxonomies' => array( 'type' => 'list', 'default' => array( 'category' ) ),
				'max_terms'  => array( 'type' => 'int', 'default' => 50, 'min' => 0, 'max' => 200 ),
			),
			'feed'      => array(
				'portable' => true,
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
				'portable' => true,
				'daily_recalc'  => array( 'type' => 'bool', 'default' => true ),
				'cache_hours'   => array( 'type' => 'int', 'default' => 6, 'min' => 1, 'max' => 48 ),
				'http_timeout'  => array( 'type' => 'int', 'default' => 5, 'min' => 2, 'max' => 15 ),
				'batch_seconds' => array( 'type' => 'int', 'default' => 20, 'min' => 5, 'max' => 60 ),
			),
			'reports'   => array(
				'portable' => true,
				'daily_enabled'  => array( 'type' => 'bool', 'default' => true ),
				'retention_days' => array( 'type' => 'int', 'default' => 400, 'min' => 30, 'max' => 1095 ),
			),
		);
		return self::site_text( $schema );
	}

	/**
	 * Marks every free-text field in `SITE_TEXT` whose CURRENT value is plain prose (no `%variable%`) as
	 * `site_specific`. A template keeps travelling: the target site fills in its own name through the variables.
	 *
	 * The decision is taken when the engine resolves the schema (once per request) from the stored value, falling back
	 * to the declared default. A save and an export never share one request (the settings screen posts to
	 * `options.php`, the download is its own request), so the flag always reflects the value on disk at export time.
	 *
	 * @param array<string, array<string, mixed>> $schema Declared schema.
	 * @return array<string, array<string, mixed>>
	 */
	private static function site_text( array $schema ): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( self::SITE_TEXT as $path ) {
			$value = self::walk( $stored, $path );
			if ( null === $value ) {
				$value = self::walk( $schema, $path . '.default' );
			}
			if ( self::is_template( (string) $value ) ) {
				continue;
			}
			$schema = self::mark( $schema, $path, 'site_specific' );
		}
		// Social profile links are the site's own identities too (a map is one travel row; see SITE_TEXT's limit).
		return self::mark( $schema, 'social.profiles', 'site_specific' );
	}

	/**
	 * Reads one dotted path out of a nested array.
	 *
	 * @param array<string, mixed> $data Nested data.
	 * @return mixed Value, or null when the path does not exist.
	 */
	private static function walk( array $data, string $path ) {
		$node = $data;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return null;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * Adds one travel flag to the schema node at a dotted path (a missing path is left alone).
	 *
	 * @param array<string, array<string, mixed>> $schema Declared schema.
	 * @return array<string, array<string, mixed>>
	 */
	private static function mark( array $schema, string $path, string $flag ): array {
		$parts = explode( '.', $path );
		$last  = (string) array_pop( $parts );
		$node  = &$schema;
		foreach ( $parts as $part ) {
			if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
				unset( $node );
				return $schema;
			}
			$node = &$node[ $part ];
		}
		if ( isset( $node[ $last ] ) && is_array( $node[ $last ] ) ) {
			$node[ $last ][ $flag ] = true;
		}
		unset( $node );
		return $schema;
	}

	/** True when the text carries at least one variable WPSeoKit knows (`%sitename%`, `%title%` …): a template, not prose. */
	private static function is_template( string $text ): bool {
		if ( '' === $text || false === strpos( $text, '%' ) ) {
			return false;
		}
		if ( 0 === preg_match_all( '/%([a-z_]+)%/', $text, $matches ) ) {
			return false;
		}
		foreach ( $matches[1] as $name ) {
			if ( isset( Variables::VAR_MAP[ $name ] ) ) {
				return true;
			}
		}
		return false;
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
