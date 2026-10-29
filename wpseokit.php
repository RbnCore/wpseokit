<?php
/**
 * Plugin Name:       WPSeoKit — SEO Toolkit
 * Plugin URI:        https://github.com/RbnCore/wpseokit
 * Description:       SEO titles and meta descriptions, canonical and robots, Open Graph, XML sitemap, schema graph, redirects and 404 log. Connects to GitHub only to check for updates (can be turned off in the settings).
 * Version:           0.1.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  adminkit
 * Author:            RBN Bilişim
 * Author URI:        https://github.com/RbnCore
 * Update URI:        https://github.com/RbnCore/wpseokit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpseokit
 * Domain Path:       /languages
 *
 * @package Wpseokit
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'WPSEOKIT_VERSION', '0.1.2' );
define( 'WPSEOKIT_FILE', __FILE__ );
define( 'WPSEOKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSEOKIT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Wpseokit\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$path = WPSEOKIT_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( \Wpseokit\Db::class, 'activate' ) );

add_action( 'plugins_loaded', array( \Wpseokit\Plugin::class, 'boot' ), 5 );
