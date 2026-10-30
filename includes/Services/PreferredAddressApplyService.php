<?php
/**
 * "Apply this address": writes the preferred address into WordPress (`home` + `siteurl`) and sends the other version
 * of the address there with ONE permanent redirect (301).
 *
 * The flow has four steps (docs/kapsam-ve-mimari.md §3.11):
 *   1. pre-check  - the target must be the www sibling of the site's own host; `WP_HOME`/`WP_SITEURL` must not be
 *                   defined in wp-config.php; the certificate of the target is asked for (a warning, never a blocker);
 *                   the redirect chain of the target is measured with PreferredAddressService (no second measurement).
 *   2. apply      - `home` + `siteurl` are written, the preferred address is saved, the forced redirect is turned on,
 *                   the caches are flushed and `wpseokit_preferred_address_applied` is fired.
 *   3. dead-man   - for CONFIRM_WINDOW seconds the change waits for a confirmation. If it does not come, the first
 *                   request after the window restores the previous address by itself (no cron, no cron dependency).
 *   4. undo       - restores the previous address at any time.
 *
 * Safety rules:
 * - The redirect target never comes from the Host header; it is read from the saved address and is re-checked on EVERY
 *   request: it must be the www sibling of the site's own home host. A poisoned setting cannot send visitors away.
 * - `WP_HOME`/`WP_SITEURL` win over the options in WordPress, so the apply is refused when they are defined and the
 *   address really has to change (no partial state): the screen shows the two lines that have to change.
 * - `WPSEOKIT_PA_DISABLE` in wp-config.php switches the whole feature off.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use Wpseokit\Admin;
use Wpseokit\Logs;
use Wpseokit\Plugin;
use Wpseokit\Registry;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class PreferredAddressApplyService {

	/** The undo record: the previous address, the previous settings and the state. Not a secret. */
	public const UNDO_OPTION = 'wpseokit_pa_undo';

	/** The open confirmation window: `until`, `confirmed`, `token`, `origin`, `host`. */
	public const PENDING_OPTION = 'wpseokit_pa_pending';

	/** The last pre-check of the site owner (per user): the checks, the token and the moment it was made. */
	public const PRECHECK_META = 'wpseokit_pa_precheck';

	/** Seconds the site owner has to confirm the new address (10 minutes). */
	public const CONFIRM_WINDOW = 600;

	/** A pre-check is valid for 15 minutes. */
	private const PRECHECK_TTL = 900;

	public static function init(): void {
		if ( ! Plugin::output_enabled() ) {
			return;
		}
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		add_action( 'admin_post_wpseokit_preferred_address', array( self::class, 'handle_admin_post' ) );
		// `init` (priority 0): after this point the admin, cron, REST and CLI requests are known, and before
		// `template_redirect` — so before WordPress' own `redirect_canonical()` could add a second hop.
		add_action( 'init', array( self::class, 'boot' ), 0 );
	}

	/* ------------------------------------------------------------------ the emergency switch */

	/** `define( 'WPSEOKIT_PA_DISABLE', true );` in wp-config.php switches the whole feature off. */
	public static function disabled(): bool {
		if ( defined( 'WPSEOKIT_PA_DISABLE' ) && WPSEOKIT_PA_DISABLE ) {
			return true;
		}
		/**
		 * Lets an integration switch the feature off without touching wp-config.php. It can only switch it OFF.
		 *
		 * @param bool $off False by default.
		 */
		return (bool) apply_filters( 'wpseokit_pa_disabled', false );
	}

	/* ------------------------------------------------------------------ every request */

	/**
	 * The work of every request: first the dead-man switch, then the forced redirect.
	 */
	public static function boot(): void {
		self::maybe_rollback();
		self::forced_redirect();
	}

	/**
	 * The dead-man switch: when the confirmation window closed without a confirmation, the previous address comes back
	 * on the first request. No cron is involved: the check runs on every request.
	 *
	 * @return bool True when the previous address was written back.
	 */
	public static function maybe_rollback(): bool {
		$pending = self::pending();
		if ( array() === $pending || ! empty( $pending['confirmed'] ) ) {
			return false;
		}
		if ( time() <= (int) ( $pending['until'] ?? 0 ) ) {
			return false;
		}
		$result = self::restore( 'rolled_back' );
		return ! empty( $result['ok'] );
	}

	/** The forced redirect: one 301 to the preferred address, only when it is switched on and safe. */
	public static function forced_redirect(): void {
		if ( self::disabled() || self::is_cli() ) {
			return;
		}
		if ( ! SettingsRepository::get( 'redirects.enforce_address', false ) ) {
			return;
		}
		$target = self::redirect_target( PreferredAddressService::request_context() );
		if ( null === $target ) {
			return;
		}
		wp_redirect( $target, 301, 'WPSeoKit' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the target is built from the saved preferred address, never from the request host.
		exit;
	}

	/**
	 * The address a request is sent to, or null for "do nothing". Pure: reads only its arguments.
	 *
	 * Reuses the decision function of the PHP fallback (PreferredAddressService::fallback_target), so the matrix is
	 * the same one: GET/HEAD only; cron, admin, admin-ajax, REST, CLI, XML-RPC and `/.well-known/` are never touched;
	 * the path and the query string are kept; HTTPS is never sent to HTTP.
	 *
	 * @param array $ctx Result of PreferredAddressService::request_context().
	 */
	public static function redirect_target( array $ctx ): ?string {
		if ( self::disabled() ) {
			return null;
		}
		if ( ! SettingsRepository::get( 'redirects.enforce_address', false ) ) {
			return null;
		}
		$pref = PreferredAddressService::preferred();
		// Fail closed: the saved address must still be the other version of this site's own address.
		if ( ! self::validate_target( (string) $pref['host'], self::home_host() )['ok'] ) {
			return null;
		}
		return PreferredAddressService::fallback_target( $ctx, $pref, (bool) SettingsRepository::get( 'redirects.trust_proxy_headers', false ) );
	}

	/* ------------------------------------------------------------------ the target */

	/** The host of this site's own address, in lower case. */
	public static function home_host(): string {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return self::normalize_host( $host );
	}

	/** The scheme of this site's own address. */
	public static function home_scheme(): string {
		return 'http' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ? 'http' : 'https';
	}

	/** The two host names of this site: the bare one and the www one. */
	public static function candidates(): array {
		$bare = self::bare_of( self::home_host() );
		if ( '' === $bare ) {
			return array();
		}
		return array( $bare, 'www.' . $bare );
	}

	/**
	 * A host name in its normal form: lower case, without a trailing dot and without a port.
	 *
	 * @param mixed $host Raw host.
	 */
	public static function normalize_host( $host ): string {
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '/^.*\//', '', $host );
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		return rtrim( $host, '.' );
	}

	/** The host without its `www.` prefix. */
	public static function bare_of( string $host ): string {
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Checks a target host: it must be the www sibling of this site's own host. Any other name (another domain, a
	 * sub-domain, an IP address, a name with a scheme, a port or a path) is refused, so a target can never point
	 * somewhere else. The name is normalized (lower case, no trailing dot) before it is compared.
	 *
	 * @param string $host      Target host as typed.
	 * @param string $home_host This site's own host.
	 * @return array{ok: bool, host: string, message: string}
	 */
	public static function validate_target( string $host, string $home_host ): array {
		// Normalization before the syntax check: the host is written in lower case and without the trailing dot, so
		// `WWW.Example.COM.` and `www.example.com` are the same name. The syntax itself (no scheme, no port, no
		// path) is still checked by Settings::host().
		$clean     = Settings::host( rtrim( strtolower( trim( $host ) ), '.' ) );
		$home_host = self::normalize_host( $home_host );
		if ( '' === $clean ) {
			return array(
				'ok'      => false,
				'host'    => '',
				'message' => __( 'Enter the host name only, such as example.com. Leave out the scheme, the port and the path.', 'wpseokit' ),
			);
		}
		if ( self::looks_like_ip( $clean ) ) {
			return array(
				'ok'      => false,
				'host'    => $clean,
				'message' => __( 'An IP address is not an address of a site. Enter the host name of this site.', 'wpseokit' ),
			);
		}
		if ( '' === $home_host ) {
			return array(
				'ok'      => false,
				'host'    => $clean,
				'message' => __( 'The address of this site could not be read, so the target cannot be checked.', 'wpseokit' ),
			);
		}
		if ( self::bare_of( $clean ) !== self::bare_of( $home_host ) ) {
			return array(
				'ok'      => false,
				'host'    => $clean,
				'message' => __( 'Only the other version of this site\'s own address may be applied: the name with www and the name without it. Another domain, a sub-domain or an IP address is refused.', 'wpseokit' ),
			);
		}
		return array(
			'ok'      => true,
			'host'    => $clean,
			'message' => '',
		);
	}

	/** Whether a host name is an IPv4 or IPv6 address. */
	public static function looks_like_ip( string $host ): bool {
		if ( '' === $host ) {
			return false;
		}
		if ( 1 === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		return 1 === preg_match( '/^[0-9.]+$/', $host );
	}

	/**
	 * Checks the scheme of the target. A site that answers on https is never moved to http (no HTTPS to HTTP).
	 *
	 * @param string $scheme  Target scheme (auto, http or https).
	 * @param string $current This site's own scheme.
	 * @return array{ok: bool, scheme: string, message: string}
	 */
	public static function validate_scheme( string $scheme, string $current ): array {
		$scheme = strtolower( trim( $scheme ) );
		if ( '' === $scheme || 'auto' === $scheme ) {
			$scheme = $current;
		}
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return array(
				'ok'      => false,
				'scheme'  => $current,
				'message' => __( 'The scheme must be http or https.', 'wpseokit' ),
			);
		}
		if ( 'https' === $current && 'http' === $scheme ) {
			return array(
				'ok'      => false,
				'scheme'  => $current,
				'message' => __( 'This site answers on https. Moving it to http would send every visitor from a secure address to an insecure one, so it is not allowed.', 'wpseokit' ),
			);
		}
		return array(
			'ok'      => true,
			'scheme'  => $scheme,
			'message' => '',
		);
	}

	/** The wp-config.php constants that win over the database, when they are defined. */
	public static function constant_names(): array {
		/**
		 * Lets a test pretend the constants are not defined (the apply then runs against the database).
		 *
		 * @param bool $none False by default.
		 */
		if ( apply_filters( 'wpseokit_pa_test_no_constants', false ) ) {
			return array();
		}
		$out = array();
		if ( defined( 'WP_HOME' ) ) {
			$out[] = 'WP_HOME';
		}
		if ( defined( 'WP_SITEURL' ) ) {
			$out[] = 'WP_SITEURL';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ pre-check */

	/**
	 * The pre-check: everything that must be known BEFORE the apply may run. It changes nothing.
	 *
	 * @param string $host   Target host as typed.
	 * @param string $scheme Target scheme (auto, http or https).
	 * @return array<string, mixed>
	 */
	public static function precheck( string $host, string $scheme ): array {
		$home     = self::home_host();
		$target   = self::validate_target( $host, $home );
		$sch      = self::validate_scheme( $scheme, self::home_scheme() );
		$origin   = ( $target['ok'] && $sch['ok'] ) ? $sch['scheme'] . '://' . $target['host'] : '';
		$checks   = array();
		$blockers = array();
		$warnings = array();

		$checks[] = array(
			'id'    => 'host',
			'state' => $target['ok'] ? 'ok' : 'fail',
			'title' => __( 'Host name', 'wpseokit' ),
			'text'  => $target['ok']
				? sprintf(
					/* translators: 1: target host, 2: this site's own host */
					__( '%1$s is the other version of this site\'s own address (%2$s). Only these two names are ever touched.', 'wpseokit' ),
					$target['host'],
					$home
				)
				: $target['message'],
		);
		if ( ! $target['ok'] ) {
			$blockers[] = 'host';
		}

		$checks[] = array(
			'id'    => 'scheme',
			'state' => $sch['ok'] ? 'ok' : 'fail',
			'title' => __( 'Scheme', 'wpseokit' ),
			'text'  => $sch['ok']
				? sprintf( /* translators: %s: scheme */ __( 'The new address answers on %s.', 'wpseokit' ), $sch['scheme'] )
				: $sch['message'],
		);
		if ( ! $sch['ok'] ) {
			$blockers[] = 'scheme';
		}

		// The address only has to change when it really differs from the current one.
		$needs_change = '' !== $origin && self::origin_of( $origin ) !== self::origin_of( home_url() );
		$constants    = $needs_change ? self::constant_names() : array();
		$lines        = '' !== $origin ? array(
			"define( 'WP_HOME', '" . $origin . "' );",
			"define( 'WP_SITEURL', '" . $origin . "' );",
		) : array();
		$checks[]     = array(
			'id'    => 'constants',
			'state' => array() === $constants ? 'ok' : 'fail',
			'title' => __( 'wp-config.php', 'wpseokit' ),
			'text'  => array() === $constants
				? __( 'The address of the site is stored in the database, so the plugin can change it.', 'wpseokit' )
				: sprintf(
					/* translators: %s: constant names */
					__( '%s is defined in wp-config.php. That constant wins over the database, so the plugin refuses to change the address (nothing is changed). Change these two lines yourself and come back.', 'wpseokit' ),
					implode( ' and ', $constants )
				),
			'lines' => $lines,
		);
		if ( array() !== $constants ) {
			$blockers[] = 'constants';
		}

		$checks[] = array(
			'id'    => 'multisite',
			'state' => is_multisite() ? 'fail' : 'ok',
			'title' => __( 'Multisite', 'wpseokit' ),
			'text'  => is_multisite()
				? __( 'A multisite network is outside the scope of this feature. Use a server rule for the address instead.', 'wpseokit' )
				: __( 'This is a single site.', 'wpseokit' ),
		);
		if ( is_multisite() ) {
			$blockers[] = 'multisite';
		}

		if ( $target['ok'] && $sch['ok'] ) {
			$audit    = PreferredAddressService::audit( array( 'preferred_scheme' => $sch['scheme'], 'preferred_host' => $target['host'] ) );
			$checks[] = self::audit_check( $audit, $target['host'], $sch['scheme'], $warnings );
			foreach ( (array) ( $audit['warnings'] ?? array() ) as $w ) {
				$warnings[] = (string) $w;
			}
		}

		$out = array(
			'host'        => $target['host'],
			'scheme'      => $sch['scheme'],
			'origin'      => $origin,
			'needs_change' => $needs_change,
			'ok'          => array() === $blockers,
			'blockers'    => $blockers,
			'checks'      => $checks,
			'warnings'    => array_values( array_unique( $warnings ) ),
			'at'          => time(),
			'token'       => '',
		);
		if ( $out['ok'] ) {
			$out['token'] = self::token();
		}
		self::save_precheck( $out );
		return $out;
	}

	/**
	 * The part of the pre-check that needs the network: the certificate of the target, the redirect chain of the
	 * target and the rule that already sends the old address somewhere else.
	 *
	 * @param array  $audit    Result of PreferredAddressService::audit().
	 * @param string $host     Target host.
	 * @param string $scheme   Target scheme.
	 * @param array  $warnings Collected warnings (by reference).
	 * @return array<string, mixed>
	 */
	private static function audit_check( array $audit, string $host, string $scheme, array &$warnings ): array {
		$target_verdict = '';
		$target_reason  = '';
		$old_verdict    = '';
		$old_redirected = false;
		$bare           = self::bare_of( $host );
		foreach ( (array) ( $audit['variants'] ?? array() ) as $v ) {
			$is_target = ( $v['host'] ?? '' ) === $host && ( $v['scheme'] ?? '' ) === $scheme;
			if ( $is_target ) {
				$target_verdict = (string) ( $v['verdict'] ?? '' );
				$target_reason  = (string) ( $v['reason'] ?? '' );
				continue;
			}
			if ( self::bare_of( (string) ( $v['host'] ?? '' ) ) !== $bare ) {
				continue;
			}
			$old_verdict = (string) ( $v['verdict'] ?? '' );
			$first       = (array) ( $v['hops'][0] ?? array() );
			$code        = (int) ( $first['code'] ?? 0 );
			$by          = (string) ( $first['by'] ?? '' );
			// A redirect without X-Redirect-By did not come from WordPress or this plugin: the server did it.
			if ( $code >= 300 && $code < 400 && '' === $by ) {
				$old_redirected = true;
			}
		}

		$text = '';
		$warn = false;
		if ( 'unreachable' === $target_verdict && 'certificate' === $target_reason ) {
			$warn = true;
			$text = __( 'The certificate of the new address could not be verified. Check it yourself before you go on; a browser must show no warning.', 'wpseokit' );
		} elseif ( 'unreachable' === $target_verdict ) {
			$warn = true;
			$text = __( 'The new address could not be reached from the server. It may still work for visitors; check it yourself.', 'wpseokit' );
		} elseif ( 'loop' === $target_verdict ) {
			$text = __( 'The redirects of the new address lead back to themselves. Something on the server has to be fixed first.', 'wpseokit' );
		} elseif ( 'ok' === $target_verdict ) {
			$text = __( 'The new address answers and the other version reaches it in one step.', 'wpseokit' );
		} else {
			$warn = true;
			$text = __( 'The new address does not answer cleanly yet. The check shows the details; you can still apply it and confirm afterwards.', 'wpseokit' );
		}
		if ( $old_redirected ) {
			$warn = true;
			$warnings[] = __( 'The server already redirects the old address somewhere else. Turn that rule off first, otherwise the visitors make two hops.', 'wpseokit' );
		}
		if ( 'chain' === $old_verdict ) {
			$warn = true;
			$warnings[] = __( 'The old address reaches the new one in more than one hop. The existing rule should be replaced, not added to.', 'wpseokit' );
		}

		return array(
			'id'    => 'network',
			'state' => ( 'ok' === $target_verdict && ! $warn ) ? 'ok' : ( $warn ? 'warn' : 'fail' ),
			'title' => __( 'Reachability', 'wpseokit' ),
			'text'  => $text,
		);
	}

	/* ------------------------------------------------------------------ apply */

	/**
	 * Applies the address: writes `home` + `siteurl`, saves the preferred address, turns the forced redirect on,
	 * flushes the caches and opens the confirmation window.
	 *
	 * @param string $host   Target host.
	 * @param string $scheme Target scheme.
	 * @return array<string, mixed> The result of the action.
	 */
	public static function apply( string $host, string $scheme ): array {
		if ( self::disabled() ) {
			return self::fail( 'disabled', __( 'The feature is switched off in wp-config.php (WPSEOKIT_PA_DISABLE).', 'wpseokit' ) );
		}
		if ( is_multisite() ) {
			return self::fail( 'multisite', __( 'A multisite network is outside the scope of this feature. Use a server rule for the address instead.', 'wpseokit' ) );
		}
		$target = self::validate_target( $host, self::home_host() );
		if ( ! $target['ok'] ) {
			return self::fail( 'host', $target['message'] );
		}
		$sch = self::validate_scheme( $scheme, self::home_scheme() );
		if ( ! $sch['ok'] ) {
			return self::fail( 'scheme', $sch['message'] );
		}
		$origin = $sch['scheme'] . '://' . $target['host'];
		$before = array(
			'home'    => (string) get_option( 'home' ),
			'siteurl' => (string) get_option( 'siteurl' ),
		);
		$needs_change = self::origin_of( $origin ) !== self::origin_of( home_url() )
			|| self::origin_of( $origin ) !== self::origin_of( (string) get_option( 'siteurl' ) );

		if ( $needs_change && array() !== self::constant_names() ) {
			return self::fail(
				'constants',
				sprintf(
					/* translators: %s: constant names */
					__( '%s is defined in wp-config.php and wins over the database, so the address cannot be changed by the plugin. Nothing was changed. Change these two lines in wp-config.php and come back.', 'wpseokit' ),
					implode( ' and ', self::constant_names() )
				)
			);
		}

		$settings_before = array(
			'preferred_scheme'    => (string) SettingsRepository::get( 'redirects.preferred_scheme', 'auto' ),
			'preferred_host'      => (string) SettingsRepository::get( 'redirects.preferred_host', '' ),
			'enforce_address'     => (bool) SettingsRepository::get( 'redirects.enforce_address', false ),
		);

		if ( $needs_change ) {
			update_option( 'home', self::with_path( $origin, (string) get_option( 'home' ) ), true );
			update_option( 'siteurl', self::with_path( $origin, (string) get_option( 'siteurl' ) ), true );
		}

		SettingsRepository::update(
			array(
				'redirects' => array(
					'preferred_scheme' => $sch['scheme'],
					'preferred_host'   => $target['host'],
					'enforce_address'  => true,
				),
			)
		);

		update_option(
			self::UNDO_OPTION,
			array(
				'home'    => $before['home'],
				'siteurl' => $before['siteurl'],
				'settings' => $settings_before,
				'origin'  => $origin,
				'at'      => time(),
				'state'   => 'applied',
			),
			false
		);

		if ( $needs_change ) {
			$token   = self::token();
			$pending = array(
				'until'     => time() + self::CONFIRM_WINDOW,
				'confirmed' => false,
				'token'     => $token,
				'origin'    => $origin,
				'host'      => $target['host'],
			);
			update_option( self::PENDING_OPTION, $pending, false );
		} else {
			$pending = array(
				'until'     => 0,
				'confirmed' => true,
				'token'     => '',
				'origin'    => $origin,
				'host'      => $target['host'],
			);
			update_option( self::PENDING_OPTION, $pending, false );
		}

		self::flush_caches();
		Logs::activity( 'redirects', 'preferred_address_applied', $origin, 'redirects' );
		/**
		 * Fires after the preferred address was applied: the new address is written, the forced redirect is on and
		 * the confirmation window is open.
		 *
		 * @param string $origin The new address (scheme://host).
		 */
		do_action( 'wpseokit_preferred_address_applied', $origin );

		return array(
			'ok'      => true,
			'code'    => 'applied',
			'message' => __( 'The new address was applied. The old address now sends visitors to it with one 301.', 'wpseokit' ),
			'origin'  => $origin,
			'changed' => $needs_change,
			'pending' => self::pending_state(),
		);
	}

	/**
	 * Confirms the new address: the change becomes permanent.
	 *
	 * @param string $token One-time token of the open window (empty: the token of the open window is used).
	 * @return array<string, mixed>
	 */
	public static function confirm( string $token = '' ): array {
		if ( self::disabled() ) {
			return self::fail( 'disabled', __( 'The feature is switched off in wp-config.php (WPSEOKIT_PA_DISABLE).', 'wpseokit' ) );
		}
		$pending = self::pending();
		if ( array() === $pending ) {
			return self::fail( 'no_pending', __( 'There is no open confirmation window. The address was already confirmed or it was never applied.', 'wpseokit' ) );
		}
		if ( ! empty( $pending['confirmed'] ) ) {
			return array(
				'ok'      => true,
				'code'    => 'already_confirmed',
				'message' => __( 'The new address was already confirmed.', 'wpseokit' ),
				'pending' => self::pending_state(),
			);
		}
		if ( '' !== $token && ! hash_equals( (string) ( $pending['token'] ?? '' ), $token ) ) {
			return self::fail( 'token', __( 'The confirmation link is not valid any more. Open the Preferred address page again.', 'wpseokit' ) );
		}
		if ( time() > (int) ( $pending['until'] ?? 0 ) ) {
			self::restore( 'rolled_back' );
			return self::fail( 'expired', __( 'The confirmation window has closed, so the previous address was restored.', 'wpseokit' ) );
		}
		$pending['confirmed'] = true;
		$pending['confirmed_at'] = time();
		update_option( self::PENDING_OPTION, $pending, false );
		$undo               = self::undo_record();
		if ( is_array( $undo ) ) {
			$undo['state'] = 'applied';
			update_option( self::UNDO_OPTION, $undo, false );
		}
		Logs::activity( 'redirects', 'preferred_address_confirmed', (string) ( $pending['origin'] ?? '' ), 'redirects' );
		return array(
			'ok'      => true,
			'code'    => 'confirmed',
			'message' => __( 'The new address was confirmed. The old address now sends visitors to it for good.', 'wpseokit' ),
			'pending' => self::pending_state(),
		);
	}

	/**
	 * Undoes the change: the previous address comes back and the forced redirect is switched off.
	 *
	 * @return array<string, mixed>
	 */
	public static function undo(): array {
		if ( self::disabled() ) {
			return self::fail( 'disabled', __( 'The feature is switched off in wp-config.php (WPSEOKIT_PA_DISABLE).', 'wpseokit' ) );
		}
		return self::restore( 'undone' );
	}

	/**
	 * Writes the previous address back and switches the forced redirect off.
	 *
	 * @param string $state `undone` (the site owner asked for it) or `rolled_back` (the window closed).
	 * @return array<string, mixed>
	 */
	private static function restore( string $state ): array {
		$undo = self::undo_record();
		if ( ! is_array( $undo ) || ! isset( $undo['home'], $undo['siteurl'] ) ) {
			return self::fail( 'no_undo', __( 'There is nothing to undo: no previous address was saved.', 'wpseokit' ) );
		}
		update_option( 'home', (string) $undo['home'], true );
		update_option( 'siteurl', (string) $undo['siteurl'], true );
		$settings = (array) ( $undo['settings'] ?? array() );
		SettingsRepository::update(
			array(
				'redirects' => array(
					'preferred_scheme' => (string) ( $settings['preferred_scheme'] ?? 'auto' ),
					'preferred_host'   => (string) ( $settings['preferred_host'] ?? '' ),
					'enforce_address'  => (bool) ( $settings['enforce_address'] ?? false ),
				),
			)
		);
		update_option( self::PENDING_OPTION, array(), false );
		$undo['state'] = $state;
		$undo['at']    = time();
		update_option( self::UNDO_OPTION, $undo, false );
		self::flush_caches();
		Logs::activity( 'redirects', 'preferred_address_' . $state, (string) ( $undo['origin'] ?? '' ), 'redirects' );
		/**
		 * Fires after the preferred address was undone (or the confirmation window closed without a confirmation).
		 *
		 * @param string $state `undone` or `rolled_back`.
		 */
		do_action( 'wpseokit_preferred_address_restored', $state );
		return array(
			'ok'      => true,
			'code'    => $state,
			'message' => 'rolled_back' === $state
				? __( 'The confirmation window closed without a confirmation, so the previous address was restored.', 'wpseokit' )
				: __( 'The previous address was restored and the forced redirect was switched off.', 'wpseokit' ),
			'pending' => self::pending_state(),
		);
	}

	/* ------------------------------------------------------------------ state */

	/** The open confirmation window, or an empty array. */
	public static function pending(): array {
		$pending = get_option( self::PENDING_OPTION, array() );
		return is_array( $pending ) ? $pending : array();
	}

	/** The undo record, or an empty array. */
	public static function undo_record(): array {
		$undo = get_option( self::UNDO_OPTION, array() );
		return is_array( $undo ) ? $undo : array();
	}

	/**
	 * The state of the feature for the screen and the REST answer.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$pending = self::pending();
		$undo    = self::undo_record();
		$status  = 'not_applied';
		if ( array() !== $pending && empty( $pending['confirmed'] ) && time() <= (int) ( $pending['until'] ?? 0 ) ) {
			$status = 'awaiting';
		} elseif ( ! empty( $pending['confirmed'] ) && SettingsRepository::get( 'redirects.enforce_address', false ) ) {
			$status = 'applied';
		} elseif ( ! empty( $undo['state'] ) && in_array( (string) $undo['state'], array( 'undone', 'rolled_back' ), true ) ) {
			$status = (string) $undo['state'];
		} elseif ( SettingsRepository::get( 'redirects.enforce_address', false ) ) {
			$status = 'applied';
		}
		return array(
			'status'       => $status,
			'enabled'      => (bool) SettingsRepository::get( 'redirects.enforce_address', false ),
			'disabled'     => self::disabled(),
			'home'         => (string) get_option( 'home' ),
			'siteurl'      => (string) get_option( 'siteurl' ),
			'preferred'    => PreferredAddressService::preferred(),
			'constants'    => self::constant_names(),
			'pending'      => self::pending_state(),
			'undo'         => array(
				'exists' => array() !== $undo,
				'origin' => (string) ( $undo['origin'] ?? '' ),
				'state'  => (string) ( $undo['state'] ?? '' ),
				'at'     => (int) ( $undo['at'] ?? 0 ),
			),
		);
	}

	/** The confirmation window as the screen shows it. */
	public static function pending_state(): array {
		$pending = self::pending();
		if ( array() === $pending ) {
			return array(
				'open'      => false,
				'confirmed' => false,
				'remaining' => 0,
				'until'     => 0,
				'token'     => '',
				'origin'    => '',
			);
		}
		$until = (int) ( $pending['until'] ?? 0 );
		return array(
			'open'      => empty( $pending['confirmed'] ) && $until > time(),
			'confirmed' => ! empty( $pending['confirmed'] ),
			'remaining' => max( 0, $until - time() ),
			'until'     => $until,
			'token'     => (string) ( $pending['token'] ?? '' ),
			'origin'    => (string) ( $pending['origin'] ?? '' ),
		);
	}

	/* ------------------------------------------------------------------ helpers */

	/** A one-time token for the pre-check and the confirmation window. */
	public static function token(): string {
		return wp_generate_password( 32, false, false );
	}

	/** The scheme://host part of a URL, in lower case; the default port is dropped. */
	public static function origin_of( string $url ): string {
		return PreferredAddressService::origin_of( $url );
	}

	/** The new origin with the path of the old URL (a site in a sub-directory keeps its path). */
	private static function with_path( string $origin, string $old ): string {
		$path = (string) wp_parse_url( $old, PHP_URL_PATH );
		$path = rtrim( (string) $path, '/' );
		return $origin . $path;
	}

	/** Flushes the caches that hold the old address (sitemap, robots, probes). */
	public static function flush_caches(): void {
		\AdminKit\Core\Cache::flush_group( Registry::SITEMAP );
		\AdminKit\Core\Cache::flush_group( Registry::PROBE );
	}

	/** CLI requests are never redirected. */
	private static function is_cli(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI;
	}

	/** A failed result. */
	private static function fail( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'pending' => self::pending_state(),
		);
	}

	/* ------------------------------------------------------------------ the pre-check of the site owner */

	/** Saves the pre-check of the site owner (per user). */
	private static function save_precheck( array $data ): void {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return;
		}
		update_user_meta( $user, self::PRECHECK_META, $data );
	}

	/** The pre-check of the site owner (per user), or an empty array. */
	public static function precheck_of( int $user = 0 ): array {
		$user = $user > 0 ? $user : get_current_user_id();
		if ( $user <= 0 ) {
			return array();
		}
		$data = get_user_meta( $user, self::PRECHECK_META, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Whether a pre-check may be used for an apply: it must exist, be recent and carry the right token.
	 *
	 * @param array $precheck The pre-check of the site owner.
	 * @param string $token   The token of the form.
	 */
	public static function precheck_valid( array $precheck, string $token ): bool {
		if ( array() === $precheck || empty( $precheck['token'] ) || '' === $token ) {
			return false;
		}
		if ( ! hash_equals( (string) $precheck['token'], $token ) ) {
			return false;
		}
		return ( time() - (int) ( $precheck['at'] ?? 0 ) ) <= self::PRECHECK_TTL;
	}

	/* ------------------------------------------------------------------ the screen (admin-post.php) */

	/** The host the site is leaving (the one before the change), or an empty string. */
	public static function old_host(): string {
		$undo = self::undo_record();
		if ( is_array( $undo ) && ! empty( $undo['home'] ) ) {
			$host = self::normalize_host( (string) wp_parse_url( (string) $undo['home'], PHP_URL_HOST ) );
			if ( '' !== $host ) {
				return $host;
			}
		}
		return self::home_host();
	}

	/**
	 * How many posts still carry the old host name in their content (a read-only count; no heavy scan).
	 *
	 * @param string $host The old host name.
	 */
	public static function old_link_posts( string $host ): int {
		global $wpdb;
		$host = self::normalize_host( $host );
		if ( '' === $host ) {
			return 0;
		}
		$like = '%' . $wpdb->esc_like( $host ) . '%';
		$sql  = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND (post_content LIKE %s OR post_excerpt LIKE %s)";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $like, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->posts, placeholders only.
	}

	/**
	 * The form of the screen goes to admin-post.php: the action is a nonce (`wpseokit_pa`) and the capability
	 * (`manage_options`) are checked here. The screen itself is drawn by the Redirects screen.
	 */
	public static function handle_admin_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpseokit' ), '', array( 'response' => 403 ) );
		}
		$action = isset( $_POST['pa_action'] ) ? sanitize_key( wp_unslash( $_POST['pa_action'] ) ) : '';
		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpseokit_pa' ) ) {
			wp_die( esc_html__( 'This link has expired. Open the page again and try once more.', 'wpseokit' ), '', array( 'response' => 403 ) );
		}
		$host   = isset( $_POST['pa-host'] ) ? sanitize_text_field( wp_unslash( $_POST['pa-host'] ) ) : '';
		$scheme = isset( $_POST['pa-scheme'] ) ? sanitize_key( wp_unslash( $_POST['pa-scheme'] ) ) : 'auto';
		$token  = isset( $_POST['pa-token'] ) ? sanitize_text_field( wp_unslash( $_POST['pa-token'] ) ) : '';

		switch ( $action ) {
			case 'precheck':
				$result = self::precheck( $host, $scheme );
				self::redirect_back( 'check', $result['ok'] ? 'ok' : 'fail' );
				break;
			case 'apply':
				$precheck = self::precheck_of();
				if ( ! self::precheck_valid( $precheck, $token ) ) {
					$result = self::fail( 'no_precheck', __( 'Run the check first; the address cannot be applied before it.', 'wpseokit' ) );
					self::redirect_back( 'apply', 'fail' );
					break;
				}
				$result = self::apply( $host, $scheme );
				if ( $result['ok'] && ! empty( $result['changed'] ) ) {
					self::redirect_to_new_address();
					break;
				}
				self::redirect_back( 'apply', $result['ok'] ? 'ok' : 'fail' );
				break;
			case 'confirm':
				self::confirm( $token );
				self::redirect_back( 'confirm', 'ok' );
				break;
			case 'undo':
				self::undo();
				self::redirect_back( 'undo', 'ok' );
				break;
			default:
				self::redirect_back( '', 'fail' );
		}
	}

	/** Back to the Preferred address tab with a note key. */
	private static function redirect_back( string $step, string $result ): void {
		$url = add_query_arg(
			array(
				'page'    => Admin::SLUG,
				'tool'    => 'redirects',
				'tab'     => 'pref',
				'pa_step' => $step,
				'pa_res'  => $result,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** To the new address: the site owner has to log in again there (the cookie domain changes). */
	private static function redirect_to_new_address(): void {
		$url = home_url( '/wp-admin/admin.php?page=' . Admin::SLUG . '&tool=redirects&tab=pref&pa_step=confirm' );
		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------------ REST */

	public static function register_routes( string $namespace ): void {
		$routes = array(
			'precheck' => array( \WP_REST_Server::CREATABLE, 'rest_precheck' ),
			'apply'    => array( \WP_REST_Server::CREATABLE, 'rest_apply' ),
			'confirm'  => array( \WP_REST_Server::CREATABLE, 'rest_confirm' ),
			'undo'     => array( \WP_REST_Server::CREATABLE, 'rest_undo' ),
			'state'    => array( \WP_REST_Server::READABLE, 'rest_state' ),
		);
		foreach ( $routes as $path => $def ) {
			register_rest_route(
				$namespace,
				'/preferred-address/' . $path,
				array(
					array(
						'methods'             => $def[0],
						'callback'            => array( self::class, $def[1] ),
						'permission_callback' => Rest::permission(),
						'args'                => array(
							'host'   => array( 'type' => 'string', 'default' => '' ),
							'scheme' => array( 'type' => 'string', 'default' => 'auto' ),
							'token'  => array( 'type' => 'string', 'default' => '' ),
						),
					),
				)
			);
		}
	}

	/** The pre-check: reads the body, changes nothing. */
	public static function rest_precheck( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();
		return new \WP_REST_Response( self::precheck( (string) ( $body['host'] ?? '' ), (string) ( $body['scheme'] ?? 'auto' ) ) );
	}

	/** Applies the address. */
	public static function rest_apply( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();
		$result = self::apply( (string) ( $body['host'] ?? '' ), (string) ( $body['scheme'] ?? 'auto' ) );
		return new \WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/** Confirms the new address. */
	public static function rest_confirm( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();
		$result = self::confirm( (string) ( $body['token'] ?? '' ) );
		return new \WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/** Undoes the change. */
	public static function rest_undo(): \WP_REST_Response {
		$result = self::undo();
		return new \WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/** The state of the feature. */
	public static function rest_state(): \WP_REST_Response {
		return new \WP_REST_Response( self::state() );
	}
}
