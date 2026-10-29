<?php
/**
 * REST frame: namespace, permission helpers and the A package routes (/status, /settings).
 * Packages B–G add their routes through the `wpseokit_register_rest_routes` action (docs/sozlesme.md §4).
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

use Wpseokit\Repositories\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NAMESPACE = 'wpseokit/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/** Permission check for settings, redirects and import routes. */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/** Permission check for a route that carries `post_id`. */
	public static function can_edit_post( \WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * Builds a `permission_callback` for a capability.
	 *
	 * @return callable(): bool
	 */
	public static function permission( string $cap = 'manage_options' ): callable {
		return static fn(): bool => current_user_can( $cap );
	}

	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => self::permission(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => self::permission(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save_settings' ),
					'permission_callback' => self::permission(),
					'args'                => array(),
				),
			)
		);
		/**
		 * Packages B–G register their routes here.
		 *
		 * @param string $namespace REST namespace (wpseokit/v1).
		 */
		do_action( 'wpseokit_register_rest_routes', self::NAMESPACE );
	}

	/** @return \WP_REST_Response */
	public static function status(): \WP_REST_Response {
		$update = \AdminKit\Core\Updater::status( 'wpseokit' );
		$status = array(
			'version'        => WPSEOKIT_VERSION,
			'db_version'     => (int) get_option( Db::OPTION, 0 ),
			'yoast_active'   => Plugin::yoast_active(),
			'output_enabled' => Plugin::output_enabled(),
			'update'         => array(
				'enabled'    => $update['enabled'],
				'checked_at' => $update['checked_at'],
				'available'  => $update['available'],
			),
		);
		$filtered = apply_filters( 'wpseokit_status', $status );
		return new \WP_REST_Response( is_array( $filtered ) ? $filtered : $status );
	}

	public static function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( SettingsRepository::all() );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function save_settings( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) || array() === $body ) {
			return new \WP_Error( 'wpseokit_bad_request', __( 'Send the settings to change as a JSON object.', 'wpseokit' ), array( 'status' => 400 ) );
		}
		$errors = Settings::validate( $body );
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
		return new \WP_REST_Response( SettingsRepository::update( $body ) );
	}
}
