<?php
/**
 * Runtime gate: WPSeoKit needs the AdminKit core (cache, logs, RBN Kit menu). Without it the plugin adds no hook
 * and shows one admin notice. This class never touches an AdminKit class, so it works while AdminKit is missing.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

defined( 'ABSPATH' ) || exit;

final class Gate {

	/** Lowest AdminKit core API level this plugin works with (`ADMINKIT_CORE_API`). */
	public const REQUIRED_API = 1;

	private const BASENAME = 'adminkit/adminkit.php';

	private const RELEASES = 'https://github.com/RbnCore/wpadminkit/releases/latest';

	/** True when the AdminKit core is loaded and new enough; otherwise registers the notice and returns false. */
	public static function open(): bool {
		if ( defined( 'ADMINKIT_CORE_API' ) && (int) ADMINKIT_CORE_API >= self::REQUIRED_API && class_exists( 'AdminKit\Core\Core' ) ) {
			return true;
		}
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		return false;
	}

	/** `admin_notices`: says what is missing and offers the way out (activate, or get the release). */
	public static function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$installed = file_exists( WP_PLUGIN_DIR . '/' . self::BASENAME );
		$loaded    = defined( 'ADMINKIT_CORE_API' ) && class_exists( 'AdminKit\Core\Core' );
		if ( $installed && ! $loaded && ! is_plugin_active( self::BASENAME ) ) {
			$url    = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( self::BASENAME ) ), 'activate-plugin_' . self::BASENAME );
			$action = '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Activate AdminKit', 'wpseokit' ) . '</a>';
		} else {
			$action = '<a class="button" href="' . esc_url( self::RELEASES ) . '">' . esc_html__( 'Get AdminKit', 'wpseokit' ) . '</a>';
		}
		$text = $loaded
			? __( 'WPSeoKit is switched off: the installed AdminKit is too old. Update AdminKit to turn it back on.', 'wpseokit' )
			: __( 'WPSeoKit is switched off: it needs the AdminKit plugin (cache, logs and the RBN Kit menu).', 'wpseokit' );
		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p><p>' . $action . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the button is escaped above.
	}
}
