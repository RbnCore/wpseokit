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
		'Services\\SchemaService',
		'Services\\BreadcrumbService',
		'Services\\RedirectService',
		'Services\\Log404Service',
		'Services\\CsvService',
		'Services\\YoastImportService',
		'Services\\LlmsTxtService',
	);

	/** Started in the admin only. */
	public const SCREENS = array(
		'Screens\\DashboardScreen',
		'Screens\\TemplatesScreen',
		'Screens\\SchemaScreen',
		'Screens\\SitemapScreen',
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
		Db::maybe_upgrade();
		Admin::init();
		Rest::init();
		// Update code runs only in the admin, cron and WP-CLI; never on the front end.
		if ( is_admin() || wp_doing_cron() || defined( 'WP_CLI' ) ) {
			Updater::init();
		}
		if ( ! self::output_enabled() ) {
			return;
		}
		add_action( 'plugins_loaded', array( self::class, 'start_services' ), 20 );
	}

	/** `plugins_loaded` (priority 20): starts the listed classes whose files exist. */
	public static function start_services(): void {
		$lists = array( self::SERVICES );
		if ( is_admin() ) {
			$lists[] = self::SCREENS;
		}
		if ( defined( 'WP_CLI' ) ) {
			$lists[] = self::CLI;
		}
		foreach ( $lists as $list ) {
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
}
