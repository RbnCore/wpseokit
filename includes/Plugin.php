<?php
/**
 * Bootstrap: Yoast guard, text domain, schema upgrade and service registration.
 *
 * Services of packages B–G are listed below and started only when their file exists; they never edit this file
 * (docs/sozlesme.md §1).
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Started on every request (front end, REST, cron). Each class exposes `public static function init(): void`. */
	public const SERVICES = array(
		'Services\\PrimaryTermService',
		'Services\\Variables',
		'Services\\MetaService',
		'Services\\RobotsService',
		'Services\\CanonicalService',
		'Services\\SocialService',
		'Services\\SitemapService',
		'Services\\SitemapView',
		'Services\\SchemaService',
		'Services\\BreadcrumbService',
		'Services\\RedirectService',
		'Services\\Log404Service',
		'Services\\CsvService',
		'Services\\YoastImportService',
		'Services\\LlmsTxtService',
		'Services\\FeedService',
		'Services\\ScoreService',
		'Services\\ScoreListService',
		'Services\\PreferredAddressService',
		'Services\\PreferredAddressApplyService',
		'Services\\ReportService',
	);

	/** Started in the admin only. */
	public const SCREENS = array(
		'Screens\\DashboardScreen',
		'Screens\\TemplatesScreen',
		'Screens\\SchemaScreen',
		'Screens\\SitemapScreen',
		'Screens\\FeedScreen',
		'Screens\\LlmsScreen',
		'Screens\\RedirectsScreen',
		'Screens\\Log404Screen',
		'Screens\\ImportScreen',
		'Screens\\SettingsScreen',
		'Screens\\MetaBox',
	);

	/** Started under WP-CLI only. */
	public const CLI = array();

	/** True when Yoast SEO is active: WPSeoKit then prints nothing and starts no service. */
	public static function yoast_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/** Whether the plugin may produce output (head tags, sitemap, redirects). False while Yoast is active. */
	public static function output_enabled(): bool {
		return ! self::yoast_active();
	}

	/** `plugins_loaded` (priority 5). */
	public static function boot(): void {
		load_plugin_textdomain( 'wpseokit', false, dirname( plugin_basename( WPSEOKIT_FILE ) ) . '/languages' );
		if ( ! Gate::open() ) {
			return;
		}
		Registry::init();
		Db::maybe_upgrade();
		Admin::init();
		Rest::init();
		// Updates are done by the AdminKit core (one central updater); the plugin only registers.
		\AdminKit\Core\Updater::register( 'wpseokit', WPSEOKIT_FILE, WPSEOKIT_VERSION );
		if ( ! self::output_enabled() ) {
			return;
		}
		add_action( 'plugins_loaded', array( self::class, 'start_services' ), 20 );
	}

	/** `plugins_loaded` (priority 20): starts the services (and WP-CLI commands). Screens wait for `init`: their labels are translated while registering. */
	public static function start_services(): void {
		self::start( self::SERVICES );
		if ( defined( 'WP_CLI' ) ) {
			self::start( self::CLI );
		}
		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'start_screens' ), 0 );
		}
	}

	/** `init` (priority 0, admin only): translations are available from here on, so screens may translate while registering. */
	public static function start_screens(): void {
		self::start( self::SCREENS );
	}

	/** @param array<int, string> $list Class names relative to `includes/`. */
	private static function start( array $list ): void {
		foreach ( $list as $name ) {
			if ( ! is_readable( WPSEOKIT_DIR . 'includes/' . str_replace( '\\', '/', $name ) . '.php' ) ) {
				continue;
			}
			$class = __NAMESPACE__ . '\\' . $name;
			if ( method_exists( $class, 'init' ) ) {
				$class::init();
			}
		}
	}
}
