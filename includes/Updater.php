<?php
/**
 * Updates from GitHub Releases: feeds WordPress's own update system.
 *
 * - The network call is made only during WordPress's own update check (`update_plugins_github.com`); never on the front end.
 * - No token, account or site information is sent: only a token-less GET with the User-Agent `WPSeoKit/<version>`.
 * - The SHA-256 digest and the Ed25519 signature are verified before install; this check cannot be turned off.
 * - When the central network setting is off, no network call is made.
 *
 * Standalone class: when copied to another plugin only the constants (REPO, PLUGIN, SLUG, prefix) change.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

use AdminKit\Core\Cache;
use AdminKit\Core\Network;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const REPO         = 'RbnCore/wpseokit';
	public const PLUGIN       = 'wpseokit/wpseokit.php';
	public const SLUG         = 'wpseokit';
	public const OPT_CHECKED  = 'wpseokit_update_checked';
	public const CACHE_KEY    = 'release';
	public const MAX_ZIP      = 8388608; // 8 MB.
	private const TTL_OK      = 43200;   // 12 hours (GitHub: 60 token-less requests per hour).
	private const TTL_ERR     = 3600;
	private const PKG_PREFIX  = 'https://github.com/' . self::REPO . '/releases/download/';
	private const NOTES_LIMIT = 2000;

	/** Version of the package verified and downloaded in this request; source selection compares against it. */
	private static ?string $verified = null;

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'pre_download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'source_selection' ), 10, 4 );
		add_action( 'admin_post_wpseokit_update_check', array( self::class, 'action_check' ) );
	}

	public static function enabled(): bool {
		if ( ! defined( 'ADMINKIT_CORE_API' ) || ADMINKIT_CORE_API < 2 || ! class_exists( 'AdminKit\Core\Network', false ) ) {
			return false;
		}
		return Network::github_allowed( 'updates:wpseokit' );
	}

	// ───────────────────────── Pure helpers ─────────────────────────

	/** `0.1.2` or `v0.1.2` becomes `0.1.2`; any other form is null. */
	public static function normalize_version( string $tag ): ?string {
		return 1 === preg_match( '/^v?(\d{1,4}\.\d{1,4}\.\d{1,4})$/', trim( $tag ), $m ) ? $m[1] : null;
	}

	/** The message the signature binds to: package name to version, version to digest. */
	public static function signed_message( string $version, string $sha256 ): string {
		return 'wpseokit|' . $version . '|' . strtolower( $sha256 );
	}

	/**
	 * Validates and simplifies a GitHub "latest release" response. Null when missing or malformed.
	 *
	 * @param mixed $data json_decode output.
	 * @return array<string,string>|null
	 */
	public static function parse_release( $data ): ?array {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = self::normalize_version( (string) ( $data['tag_name'] ?? '' ) );
		if ( null === $version || ! isset( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return null;
		}
		$want  = array(
			'package' => 'wpseokit-' . $version . '.zip',
			'sha'     => 'wpseokit-' . $version . '.zip.sha256',
			'sig'     => 'wpseokit-' . $version . '.zip.sig',
		);
		$found = array();
		foreach ( $data['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
				continue;
			}
			$key = array_search( (string) $asset['name'], $want, true );
			$url = (string) $asset['browser_download_url'];
			if ( false !== $key && $url === self::PKG_PREFIX . rawurlencode( $version ) . '/' . $want[ $key ] ) {
				$found[ $key ] = $url;
			}
		}
		if ( 3 !== count( $found ) ) {
			return null;
		}
		$notes = (string) ( $data['body'] ?? '' );
		$notes = function_exists( 'mb_substr' ) ? mb_substr( $notes, 0, self::NOTES_LIMIT ) : substr( $notes, 0, self::NOTES_LIMIT );
		return array(
			'version' => $version,
			'package' => $found['package'],
			'sha'     => $found['sha'],
			'sig'     => $found['sig'],
			'notes'   => $notes,
			'url'     => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( (string) $data['tag_name'] ),
		);
	}

	/** Public key: the 32-byte raw value, or empty. */
	private static function public_key(): string {
		$file = WPSEOKIT_DIR . 'includes/update-pubkey.php';
		$b64  = is_readable( $file ) ? include $file : '';
		$raw  = is_string( $b64 ) ? base64_decode( $b64, true ) : false;
		return ( is_string( $raw ) && 32 === strlen( $raw ) ) ? $raw : '';
	}

	/**
	 * @param string $key_override Raw public key for tests only; empty means the embedded key.
	 * @return true|\WP_Error
	 */
	public static function verify_signature( string $message, string $sig_b64, string $key_override = '' ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new \WP_Error( 'wpseokit_no_sodium', __( 'Update rejected: signature verification (sodium) is missing on the server. The old version was kept.', 'wpseokit' ) );
		}
		$key = '' !== $key_override ? $key_override : self::public_key();
		$sig = base64_decode( trim( $sig_b64 ), true );
		if ( '' === $key ) {
			return new \WP_Error( 'wpseokit_no_key', __( 'Update rejected: the plugin has no valid signature key. The old version was kept.', 'wpseokit' ) );
		}
		if ( ! is_string( $sig ) || 64 !== strlen( $sig ) || ! sodium_crypto_sign_verify_detached( $sig, $message, $key ) ) {
			return new \WP_Error( 'wpseokit_bad_sig', __( 'Update rejected: the package signature is invalid. The old version was kept.', 'wpseokit' ) );
		}
		return true;
	}

	/**
	 * Downloaded file: size, SHA-256 and signature.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_package( string $file, string $version, string $sha_body, string $sig_b64, string $key_override = '' ) {
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( $size < 1 || $size > self::MAX_ZIP ) {
			return new \WP_Error( 'wpseokit_bad_size', __( 'Update rejected: the package size is not in the expected range. The old version was kept.', 'wpseokit' ) );
		}
		if ( 1 !== preg_match( '/^\s*([0-9a-fA-F]{64})(?:\s|$)/', $sha_body, $m ) ) {
			return new \WP_Error( 'wpseokit_bad_sha_file', __( 'Update rejected: the digest (SHA-256) file could not be read. The old version was kept.', 'wpseokit' ) );
		}
		$actual = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( strtolower( $m[1] ), $actual ) ) {
			return new \WP_Error( 'wpseokit_sha_mismatch', __( 'Update rejected: the SHA-256 digest of the package does not match. The old version was kept.', 'wpseokit' ) );
		}
		return self::verify_signature( self::signed_message( $version, $actual ), $sig_b64, $key_override );
	}

	/**
	 * Root folder and main file of the unpacked package.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_source( string $source, string $version ) {
		$dir  = rtrim( str_replace( '\\', '/', $source ), '/' );
		$main = $dir . '/wpseokit.php';
		if ( self::SLUG !== basename( $dir ) || ! is_file( $main ) ) {
			return new \WP_Error( 'wpseokit_bad_layout', __( 'Update rejected: the root folder of the package is not “wpseokit” or wpseokit.php is missing. The old version was kept.', 'wpseokit' ) );
		}
		$head = get_file_data( $main, array( 'v' => 'Version', 'n' => 'Plugin Name' ) );
		if ( '' === $head['n'] || $head['v'] !== $version ) {
			return new \WP_Error( 'wpseokit_bad_version', __( 'Update rejected: the version inside the package does not match the published version. The old version was kept.', 'wpseokit' ) );
		}
		return true;
	}

	// ───────────────────────── Release information ─────────────────────────

	/**
	 * Latest release. No network call is made when the check is off.
	 *
	 * @return array{status:string,release:?array<string,string>,time:int}|null Cache record; null when off.
	 */
	public static function lookup( bool $force = false ): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		if ( ! $force ) {
			$cached = Cache::get( Registry::UPDATES, self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return $cached;
			}
		}
		$res    = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'limit_response_size' => 262144,
				'user-agent'          => 'WPSeoKit/' . WPSEOKIT_VERSION,
				'headers'             => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$status = 'error';
		$rel    = null;
		$ttl    = self::TTL_ERR;
		if ( ! is_wp_error( $res ) ) {
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 404 === $code ) {
				$status = 'none';
				$ttl    = self::TTL_OK;
			} elseif ( 200 === $code ) {
				$rel = self::parse_release( json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
				if ( null !== $rel ) {
					$status = 'ok';
					$ttl    = self::TTL_OK;
				}
			}
		}
		$record = array(
			'status'  => $status,
			'release' => $rel,
			'time'    => time(),
		);
		Cache::set( Registry::UPDATES, self::CACHE_KEY, $record, $ttl );
		update_option( self::OPT_CHECKED, $record['time'], false );
		$found = 'ok' === $status && is_array( $rel ) && version_compare( $rel['version'], WPSEOKIT_VERSION, '>' );
		Logs::update_check( 'error' === $status ? 'failed' : ( $found ? 'update_found' : 'up_to_date' ), $found ? $rel['version'] : WPSEOKIT_VERSION );
		if ( 'error' === $status ) {
			Logs::error( 'update_check', 'The release check failed.' );
		}
		return $record;
	}

	/** The release when a newer version exists, otherwise null. @return array<string,string>|null */
	public static function newer(): ?array {
		$rec = self::lookup();
		if ( null === $rec || 'ok' !== $rec['status'] || ! is_array( $rec['release'] ) ) {
			return null;
		}
		return version_compare( $rec['release']['version'], WPSEOKIT_VERSION, '>' ) ? $rec['release'] : null;
	}

	/** @return array{requires:string,requires_php:string,tested:string} */
	private static function local_meta(): array {
		$h      = get_file_data( WPSEOKIT_FILE, array( 'r' => 'Requires at least', 'p' => 'Requires PHP' ) );
		$readme = is_readable( WPSEOKIT_DIR . 'readme.txt' ) ? (string) file_get_contents( WPSEOKIT_DIR . 'readme.txt' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- yerel dosya.
		$tested = 1 === preg_match( '/^Tested up to:\s*([0-9.]+)/mi', $readme, $m ) ? $m[1] : '';
		return array(
			'requires'     => $h['r'],
			'requires_php' => $h['p'],
			'tested'       => $tested,
		);
	}

	// ───────────────────────── WordPress hooks ─────────────────────────

	/**
	 * `update_plugins_github.com`: called during WordPress's own update check.
	 * WordPress does the version comparison and the "up to date" list.
	 *
	 * @param mixed                $update      Previous value.
	 * @param array<string,string> $plugin_data Plugin headers.
	 * @param string               $plugin_file Plugin file.
	 * @return mixed
	 */
	public static function offer( $update, $plugin_data, $plugin_file ) {
		if ( self::PLUGIN !== $plugin_file || ! self::enabled() ) {
			return $update;
		}
		$rel  = self::newer();
		$meta = self::local_meta();
		return array_merge(
			array(
				'id'           => 'https://github.com/' . self::REPO,
				'slug'         => self::SLUG,
				'version'      => $rel ? $rel['version'] : WPSEOKIT_VERSION,
				'url'          => $rel ? $rel['url'] : 'https://github.com/' . self::REPO,
				'package'      => $rel ? $rel['package'] : '',
				'icons'        => array(),
				'requires'     => $meta['requires'],
				'requires_php' => $meta['requires_php'],
			),
			'' !== $meta['tested'] ? array( 'tested' => $meta['tested'] ) : array()
		);
	}

	/**
	 * "View details" popup. Makes no network call; uses only the cached release.
	 *
	 * @param mixed $result Previous result.
	 * @param mixed $action Request type.
	 * @param mixed $args   Request arguments.
	 * @return mixed
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) || ! self::enabled() ) {
			return $result;
		}
		$cached = Cache::get( Registry::UPDATES, self::CACHE_KEY );
		$rel    = ( is_array( $cached ) && 'ok' === ( $cached['status'] ?? '' ) && is_array( $cached['release'] ?? null ) ) ? $cached['release'] : null;
		$new    = $rel && version_compare( $rel['version'], WPSEOKIT_VERSION, '>' );
		$meta   = self::local_meta();
		$notes  = $rel ? $rel['notes'] : '';
		return (object) array(
			'name'          => 'WPSeoKit — ' . __( 'SEO Toolkit', 'wpseokit' ),
			'slug'          => self::SLUG,
			'version'       => $new ? $rel['version'] : WPSEOKIT_VERSION,
			'author'        => '<a href="https://github.com/RbnCore">RBN Bilişim</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $meta['requires'],
			'requires_php'  => $meta['requires_php'],
			'tested'        => $meta['tested'],
			'download_link' => $new ? $rel['package'] : '',
			'sections'      => array(
				'description' => esc_html__( 'SEO titles and meta descriptions, canonical and robots, Open Graph, XML sitemap, schema graph, redirects and a 404 log.', 'wpseokit' ),
				'changelog'   => '' !== $notes ? wp_kses_post( nl2br( esc_html( $notes ) ) ) : esc_html__( 'There are no release notes for this version.', 'wpseokit' ),
			),
		);
	}

	/**
	 * Downloads and verifies the package; returns the temporary file path when valid (WordPress unpacks it).
	 *
	 * @param mixed  $reply   Previous reply.
	 * @param string $package Paket adresi.
	 * @return mixed
	 */
	public static function pre_download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::PKG_PREFIX ) ) {
			return $reply;
		}
		self::$verified = null;
		if ( 1 !== preg_match( '#^' . preg_quote( self::PKG_PREFIX, '#' ) . '(\d{1,4}\.\d{1,4}\.\d{1,4})/wpseokit-\1\.zip$#', $package, $m ) ) {
			return new \WP_Error( 'wpseokit_bad_url', __( 'Update rejected: the package address is not in the expected format. The old version was kept.', 'wpseokit' ) );
		}
		$version = $m[1];
		$sha     = self::fetch_text( $package . '.sha256' );
		$sig     = is_wp_error( $sha ) ? $sha : self::fetch_text( $package . '.sig' );
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$ok = self::check_package( $tmp, $version, $sha, $sig );
		if ( is_wp_error( $ok ) ) {
			wp_delete_file( $tmp );
			return $ok;
		}
		self::$verified = $version;
		return $tmp;
	}

	/** Downloads a small text asset (digest or signature). @return string|\WP_Error */
	private static function fetch_text( string $url ) {
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'limit_response_size' => 4096,
				'user-agent'          => 'WPSeoKit/' . WPSEOKIT_VERSION,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'wpseokit_fetch_fail', __( 'Update stopped: the digest or signature file could not be downloaded. The old version was kept.', 'wpseokit' ) );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Unpacked package: root folder and main file check. Runs only for a WPSeoKit update.
	 *
	 * @param mixed                $source        Unpacked folder.
	 * @param string               $remote_source Unpack location.
	 * @param mixed                $upgrader      Upgrader.
	 * @param array<string,mixed>  $hook_extra    Ek bilgi.
	 * @return mixed
	 */
	public static function source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || self::PLUGIN !== ( $hook_extra['plugin'] ?? '' ) ) {
			return $source;
		}
		$version        = self::$verified;
		self::$verified = null;
		if ( null === $version ) {
			return new \WP_Error( 'wpseokit_unverified', __( 'Update rejected: the package did not pass the WPSeoKit verification. The old version was kept.', 'wpseokit' ) );
		}
		$ok = self::check_source( (string) $source, $version );
		return is_wp_error( $ok ) ? $ok : $source;
	}

	// ───────────────────────── Settings screen ─────────────────────────

	public static function action_check(): void {
		self::guard( 'wpseokit_update_check' );
		if ( self::enabled() ) {
			Cache::delete( Registry::UPDATES, self::CACHE_KEY );
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			self::redirect( 'update_checked' );
		}
		self::redirect( 'update_is_off' );
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'wpseokit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( string $note ): void {
		wp_safe_redirect( Admin::page_url( array( 'tab' => 'status', 'wk_note' => $note ) ) );
		exit;
	}

	/** Body of the "Updates" card in the Status tab. */
	public static function render_card(): string {
		$on      = self::enabled();
		$checked = (int) get_option( self::OPT_CHECKED, 0 );
		$cached  = $on ? Cache::get( Registry::UPDATES, self::CACHE_KEY ) : false;
		$state   = '';
		if ( ! $on ) {
			$state = esc_html__( 'The update check is off; GitHub is never contacted.', 'wpseokit' );
		} elseif ( ! is_array( $cached ) ) {
			$state = esc_html__( 'Not checked yet.', 'wpseokit' );
		} elseif ( 'none' === $cached['status'] ) {
			$state = esc_html__( 'There is no published version yet.', 'wpseokit' );
		} elseif ( 'ok' !== $cached['status'] || ! is_array( $cached['release'] ) ) {
			$state = esc_html__( 'Could not check; it will be tried again later. The plugin works normally.', 'wpseokit' );
		} elseif ( version_compare( $cached['release']['version'], WPSEOKIT_VERSION, '>' ) ) {
			/* translators: %s: version number */
			$state = sprintf( esc_html__( 'A new version is available: %s. Click “Update” on the Plugins page.', 'wpseokit' ), '<strong>' . esc_html( $cached['release']['version'] ) . '</strong>' );
		} else {
			$state = esc_html__( 'The plugin is up to date.', 'wpseokit' );
		}
		$last = $checked > 0 ? esc_html( wp_date( 'd.m.Y H:i', $checked ) ) : esc_html__( 'never', 'wpseokit' );
		$post = esc_url( admin_url( 'admin-post.php' ) );

		$html  = '<dl class="wpseokit-dl"><dt>' . esc_html__( 'Installed version', 'wpseokit' ) . '</dt><dd>' . esc_html( WPSEOKIT_VERSION ) . '</dd>'
			. '<dt>' . esc_html__( 'Status', 'wpseokit' ) . '</dt><dd>' . $state . '</dd>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $state is escaped above.
			. '<dt>' . esc_html__( 'Last check', 'wpseokit' ) . '</dt><dd>' . $last . '</dd>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '<dt>' . esc_html__( 'Automatic updates', 'wpseokit' ) . '</dt><dd>' . esc_html__( 'Turned on or off with the “Enable auto-updates” link on the WPSeoKit row in the Plugins page.', 'wpseokit' ) . '</dd></dl>'
			. '<p class="wpseokit-help">' . esc_html__( 'The update package is verified with its SHA-256 digest and Ed25519 signature before installation; a package that cannot be verified is not installed. Only a standard GET request is sent; your site address or user information is not sent.', 'wpseokit' ) . '</p>'
			. '<p class="wpseokit-help"><a href="' . esc_url( admin_url( 'admin.php?page=adminkit#adminkit-network' ) ) . '">' . esc_html__( 'Update checks follow the RBN Kit network setting.', 'wpseokit' ) . '</a></p>';
		if ( $on ) {
			$html .= '<div class="wpseokit-actions">'
				. '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="wpseokit_update_check">' . wp_nonce_field( 'wpseokit_update_check', '_wpnonce', true, false ) . '<button type="submit" class="button">' . esc_html__( 'Check now', 'wpseokit' ) . '</button></form>'
				. '</div>';
		}
		return $html;
	}
}
