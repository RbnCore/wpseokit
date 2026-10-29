<?php
/**
 * The one place that declares the AdminKit cache groups and log channels of WPSeoKit (AdminKit core,
 * adminkit/docs/cekirdek.md). Retention limits are constants here; there is no setting for them.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

use AdminKit\Core\Cache;
use AdminKit\Core\Log;

defined( 'ABSPATH' ) || exit;

final class Registry {

	public const PLUGIN = 'wpseokit';

	/** Cache groups (`plugin.name`). */
	public const PROBE       = 'wpseokit.probe';
	public const SITEMAP     = 'wpseokit.sitemap';
	public const LLMS        = 'wpseokit.llms';
	public const SCORE       = 'wpseokit.score';
	public const UPDATES     = 'wpseokit.updates';
	public const MAINTENANCE = 'wpseokit.maintenance';

	/** Log channels (`plugin.name`). */
	public const ACTIVITY   = 'wpseokit.activity';
	public const ERRORS     = 'wpseokit.errors';
	public const UPDATE_LOG = 'wpseokit.updates';
	public const YOAST      = 'wpseokit.yoast';

	/** Group name => default lifetime in seconds (callers pass their own lifetime where a setting decides). */
	private const GROUPS = array(
		'probe'       => 6 * HOUR_IN_SECONDS,
		'sitemap'     => 12 * HOUR_IN_SECONDS,
		'llms'        => 12 * HOUR_IN_SECONDS,
		'score'       => 5 * MINUTE_IN_SECONDS,
		'updates'     => 12 * HOUR_IN_SECONDS,
		'maintenance' => DAY_IN_SECONDS,
	);

	/** Channel name => array( retention days, newest rows kept, levels ). */
	private const CHANNELS = array(
		'activity' => array( 90, 500, array( 'info' ) ),
		'errors'   => array( 90, 100, array( 'error' ) ),
		'updates'  => array( 90, 100, array( 'info', 'warning' ) ),
		'yoast'    => array( 90, 100, array( 'info' ) ),
	);

	/** Called after the gate opened: registers now when the core is ready, otherwise on `rbnkit_core_ready`. */
	public static function init(): void {
		if ( did_action( 'rbnkit_core_ready' ) ) {
			self::register();
			return;
		}
		add_action( 'rbnkit_core_ready', array( self::class, 'register' ) );
	}

	public static function register(): void {
		foreach ( self::GROUPS as $name => $ttl ) {
			Cache::register_group( self::PLUGIN, $name, $ttl );
		}
		foreach ( self::CHANNELS as $name => $def ) {
			Log::register_channel( self::PLUGIN, $name, $def[0], $def[1], $def[2] );
		}
	}
}
