<?php
/**
 * Preferred address: gathers http/https x www/non-www under ONE address.
 *
 * The main feature is an audit (read only) and a server rule generator (copy and paste text for Apache and nginx; the
 * plugin never writes `.htaccess` or a server file). A PHP redirect exists only as an optional fallback
 * (`redirects.php_fallback`, OFF by default): it answers with one 301 and leaves wp-cron, admin-ajax, REST, CLI and
 * `.well-known` alone. Nothing here changes a response until a setting is edited.
 *
 * Policy foundations (docs/google-arama/tercih-edilen-adres.md):
 * - Rules "Tek tercih edilen adres", "Kalici kod" (301/308), "Yontem - sunucu tarafi", "HTTPS zorunlu yon" (never
 *   HTTPS to HTTP), "Tek siçrama", "Ayni host her yerde".
 * - Uygulama talimati 2 (one source: home_url), 3 (server level, not PHP), 5 (no HTTPS to HTTP), 6 (no second rule on top
 *   of redirect_canonical), 7 (valid certificate), 8 (HSTS: information only).
 *
 * Contract: docs/sozlesme.md §2.1 and §4.5, behaviour: docs/kapsam-ve-mimari.md §3.11.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use AdminKit\Core\Security\Guard;
use AdminKit\Core\Security\Host;

use Wpseokit\Plugin;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class PreferredAddressService {

	/** Longest redirect chain that is followed (the verdict says "loop" beyond it). */
	public const MAX_HOPS = 5;

	/** Seconds for one request. */
	private const TIMEOUT = 5;

	/** Seconds for the whole audit; later requests give up with reason `timeout`. */
	private const BUDGET = 45;

	/** Bytes read from a page, sitemap or feed. */
	private const BODY_LIMIT = 524288;

	/** Status codes that are redirects. */
	private const REDIRECT_CODES = array( 301, 302, 303, 307, 308 );

	/** Permanent redirects (the document: "use permanent redirects"). */
	private const PERMANENT_CODES = array( 301, 308 );

	/** Paths the PHP fallback never touches. A request for them is not a page view. */
	private const FALLBACK_SKIP = array( '/wp-cron.php', '/wp-admin', '/wp-json', '/wp-login.php', '/xmlrpc.php', '/.well-known/' );

	/** @var float Time the audit started (budget). */
	private static float $started = 0.0;

	public static function init(): void {
		if ( ! Plugin::output_enabled() ) {
			return;
		}
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		// Off by default: with the fallback closed no hook does any work on a page view.
		if ( SettingsRepository::get( 'redirects.php_fallback', false ) ) {
			add_action( 'template_redirect', array( self::class, 'maybe_redirect' ), 0 );
		}
	}

	/* ------------------------------------------------------------------ the preferred address */

	/**
	 * The preferred address: the settings, with `auto` and an empty host following `home_url()` (document: one source).
	 *
	 * @param array<string, mixed>|null $override Unsaved `preferred_scheme` / `preferred_host` (audit and snippets preview).
	 * @return array{scheme: string, host: string, port: string, origin: string, bare: string, www: bool, auto: array{scheme: string, host: string}}
	 */
	public static function preferred( ?array $override = null ): array {
		$cfg   = SettingsRepository::section( 'redirects' );
		$cfg   = is_array( $override ) ? array_merge( $cfg, array_intersect_key( $override, array_flip( array( 'preferred_scheme', 'preferred_host' ) ) ) ) : $cfg;
		$home  = (array) wp_parse_url( home_url() );
		$h_sch = ( isset( $home['scheme'] ) && 'http' === strtolower( (string) $home['scheme'] ) ) ? 'http' : 'https';
		$h_hst = strtolower( (string) ( $home['host'] ?? '' ) );
		$h_prt = isset( $home['port'] ) ? (string) $home['port'] : '';

		$scheme = (string) ( $cfg['preferred_scheme'] ?? 'auto' );
		$scheme = in_array( $scheme, array( 'https', 'http' ), true ) ? $scheme : $h_sch;
		$host   = Settings::host( $cfg['preferred_host'] ?? '' );
		$port   = '' === $host ? $h_prt : '';
		if ( '' === $host ) {
			$host = $h_hst;
		} elseif ( $host !== $h_hst ) {
			$val = PreferredAddressApplyService::validate_target( $host, $h_hst );
			if ( ! $val['ok'] ) {
				$host = $h_hst;
			}
		}
		$bare = (string) preg_replace( '/^www\./', '', $host );
		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => self::clean_port( $scheme, $port ),
			'origin' => $scheme . '://' . $host . self::clean_port( $scheme, $port, true ),
			'bare'   => $bare,
			'www'    => $bare !== $host,
			'auto'   => array(
				'scheme' => $h_sch,
				'host'   => $h_hst,
			),
		);
	}

	/** Port without the default one; with `$suffix` as `:8080`. */
	private static function clean_port( string $scheme, string $port, bool $suffix = false ): string {
		if ( '' === $port || ( 'https' === $scheme && '443' === $port ) || ( 'http' === $scheme && '80' === $port ) ) {
			return '';
		}
		return $suffix ? ':' . $port : $port;
	}

	/** The host the preferred address is NOT on (`example.com` for `www.example.com` and the other way round). */
	private static function other_host( array $pref ): string {
		return $pref['www'] ? $pref['bare'] : 'www.' . $pref['bare'];
	}

	/**
	 * The four variants (scheme x host).
	 *
	 * @return array<int, array{scheme: string, host: string, url: string, is_preferred: bool}>
	 */
	public static function variants( array $pref ): array {
		$out = array();
		foreach ( array( 'http', 'https' ) as $scheme ) {
			foreach ( array( $pref['bare'], 'www.' . $pref['bare'] ) as $host ) {
				$out[] = array(
					'scheme'       => $scheme,
					'host'         => $host,
					'url'          => $scheme . '://' . $host . self::clean_port( $scheme, $pref['port'], true ) . '/',
					'is_preferred' => $scheme === $pref['scheme'] && $host === $pref['host'],
				);
			}
		}
		return $out;
	}

	/** `scheme://host[:port]` in lower case with the default port dropped, or an empty string. */
	public static function origin_of( string $url ): string {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $p['scheme'] );
		return $scheme . '://' . strtolower( (string) $p['host'] ) . self::clean_port( $scheme, isset( $p['port'] ) ? (string) $p['port'] : '', true );
	}

	/** Puts the preferred origin in front of the path (and query) of an address of this site. */
	private static function swap_origin( string $url, array $pref ): string {
		$p = (array) wp_parse_url( $url );
		return $pref['origin'] . ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	/* ------------------------------------------------------------------ requests (audit) */

	/**
	 * One request, no redirect followed. Result: `code` (0 when no answer), `location`, `by` (X-Redirect-By), `hsts`,
	 * `body`, `error`, `reason` (dns|certificate|connect|timeout|other when there was no answer).
	 *
	 * @return array{code: int, location: string, by: string, hsts: string, body: string, error: string, reason: string}
	 */
	public static function fetch( string $url, string $method = 'HEAD' ): array {
		/**
		 * Lets a test (or a caller with its own transport) answer instead of the network. Return the result array.
		 *
		 * @param array|null $result Null to use the network.
		 */
		$pre = apply_filters( 'wpseokit_preferred_fetch', null, $url, $method );
		if ( is_array( $pre ) ) {
			return array_merge( self::blank(), $pre );
		}
		if ( 0.0 !== self::$started && microtime( true ) - self::$started > self::BUDGET ) {
			return array_merge( self::blank(), array( 'error' => 'Time budget used up.', 'reason' => 'timeout' ) );
		}
		if ( false === wp_http_validate_url( $url ) ) {
			return array_merge( self::blank(), array( 'error' => __( 'A valid URL was not provided.', 'wpseokit' ), 'reason' => 'other' ) );
		}
		$args = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'user-agent'          => 'WPSeoKit/' . ( defined( 'WPSEOKIT_VERSION' ) ? WPSEOKIT_VERSION : '0' ) . ' address-audit',
			'limit_response_size' => self::BODY_LIMIT,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
		);
		/** Request arguments of the audit (a local site with a self-signed certificate turns `sslverify` off here). */
		$args = (array) apply_filters( 'wpseokit_preferred_probe_args', $args, $url );
		$res  = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_head( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array_merge( self::blank(), array( 'error' => $res->get_error_message(), 'reason' => self::reason( $res->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 'HEAD' === $method && in_array( $code, array( 405, 501 ), true ) ) {
			return self::fetch( $url, 'GET' );
		}
		return array_merge(
			self::blank(),
			array(
				'code'     => $code,
				'location' => (string) wp_remote_retrieve_header( $res, 'location' ),
				'by'       => (string) wp_remote_retrieve_header( $res, 'x-redirect-by' ),
				'hsts'     => (string) wp_remote_retrieve_header( $res, 'strict-transport-security' ),
				'body'     => 'GET' === $method ? (string) wp_remote_retrieve_body( $res ) : '',
			)
		);
	}

	/** @return array{code: int, location: string, by: string, hsts: string, body: string, error: string, reason: string} */
	private static function blank(): array {
		return array(
			'code'     => 0,
			'location' => '',
			'by'       => '',
			'hsts'     => '',
			'body'     => '',
			'error'    => '',
			'reason'   => '',
		);
	}

	/** Why there was no answer, from the transport message. */
	private static function reason( string $message ): string {
		$m = strtolower( $message );
		if ( false !== strpos( $m, 'ssl' ) || false !== strpos( $m, 'certificate' ) || preg_match( '/error (35|51|58|60|77|83)\b/', $m ) ) {
			return 'certificate';
		}
		if ( false !== strpos( $m, 'resolve' ) || preg_match( '/error 6\b/', $m ) ) {
			return 'dns';
		}
		if ( false !== strpos( $m, 'timed out' ) || preg_match( '/error 28\b/', $m ) ) {
			return 'timeout';
		}
		if ( false !== strpos( $m, 'connect' ) || preg_match( '/error 7\b/', $m ) ) {
			return 'connect';
		}
		return 'other';
	}

	/** A `Location` value as a full address (relative values are read against the address that sent them). */
	public static function absolutize( string $location, string $base ): string {
		$location = trim( $location );
		if ( '' === $location ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$p      = (array) wp_parse_url( $base );
		$scheme = (string) ( $p['scheme'] ?? 'https' );
		$origin = self::origin_of( $base );
		if ( 0 === strpos( $location, '//' ) ) {
			return $scheme . ':' . $location;
		}
		if ( '/' === $location[0] ) {
			return $origin . $location;
		}
		$dir = rtrim( dirname( (string) ( $p['path'] ?? '/' ) . 'x' ), '/' );
		return $origin . $dir . '/' . $location;
	}

	/**
	 * Follows an address by hand and counts the hops.
	 *
	 * @return array{hops: array<int, array{url: string, code: int, location: string, by: string}>, redirects: int, final_url: string, final_code: int, loop: bool, error: string, reason: string, hsts: string}
	 */
	public static function trace( string $url ): array {
		$hops = array();
		$seen = array();
		$out  = array(
			'hops'       => array(),
			'redirects'  => 0,
			'final_url'  => $url,
			'final_code' => 0,
			'loop'       => false,
			'error'      => '',
			'reason'     => '',
			'hsts'       => '',
		);
		for ( $i = 0; $i <= self::MAX_HOPS; $i++ ) {
			$seen[ $url ] = true;
			$r            = self::fetch( $url );
			$hops[]       = array(
				'url'      => $url,
				'code'     => $r['code'],
				'location' => $r['location'],
				'by'       => $r['by'],
			);
			if ( 0 === strpos( $url, 'https://' ) && '' !== $r['hsts'] && '' === $out['hsts'] ) {
				$out['hsts'] = $r['hsts'];
			}
			$out['final_url']  = $url;
			$out['final_code'] = $r['code'];
			if ( 0 === $r['code'] ) {
				$out['error']  = $r['error'];
				$out['reason'] = $r['reason'];
				break;
			}
			if ( ! in_array( $r['code'], self::REDIRECT_CODES, true ) || '' === $r['location'] ) {
				break;
			}
			++$out['redirects'];
			$next = self::absolutize( $r['location'], $url );
			if ( '' === $next || isset( $seen[ $next ] ) || $out['redirects'] > self::MAX_HOPS ) {
				$out['loop'] = true;
				break;
			}
			$url = $next;
		}
		$out['hops'] = $hops;
		return $out;
	}

	/**
	 * Verdict for one variant. Pure: needs no network.
	 *
	 * @param array $trace Result of trace().
	 * @param array $pref  Result of preferred().
	 * @return array{verdict: string, reason: string, message: string}
	 */
	public static function decide( bool $is_preferred, array $trace, array $pref ): array {
		$reason = (string) ( $trace['reason'] ?? '' );
		if ( ! empty( $trace['loop'] ) ) {
			return self::verdict( 'loop' );
		}
		// A step that leaves HTTPS for HTTP is never acceptable (document: "HTTPS zorunlu yon").
		foreach ( $trace['hops'] as $hop ) {
			if ( in_array( $hop['code'], self::REDIRECT_CODES, true ) && 0 === strpos( $hop['url'], 'https://' ) && 0 === stripos( self::absolutize( $hop['location'], $hop['url'] ), 'http://' ) ) {
				return self::verdict( 'https_downgrade' );
			}
		}
		if ( 0 === (int) $trace['final_code'] ) {
			return self::verdict( 'unreachable', $reason );
		}
		$redirects = (int) $trace['redirects'];
		$final_ok  = (int) $trace['final_code'] >= 200 && (int) $trace['final_code'] < 300;
		if ( 0 === $redirects ) {
			if ( $is_preferred ) {
				return self::verdict( $final_ok ? 'ok' : 'preferred_not_ok' );
			}
			return self::verdict( $final_ok ? 'duplicate' : 'wrong_target' );
		}
		if ( $is_preferred ) {
			return self::verdict( 'preferred_not_ok' );
		}
		if ( self::origin_of( (string) $trace['final_url'] ) !== self::origin_of( $pref['origin'] ) ) {
			return self::verdict( 'wrong_target' );
		}
		if ( $redirects >= 2 ) {
			return self::verdict( 'chain' );
		}
		if ( ! in_array( (int) $trace['hops'][0]['code'], self::PERMANENT_CODES, true ) ) {
			return self::verdict( 'temporary' );
		}
		return self::verdict( $final_ok ? 'ok' : 'preferred_not_ok' );
	}

	/** @return array{verdict: string, reason: string, message: string} */
	private static function verdict( string $code, string $reason = '' ): array {
		$messages = array(
			'ok'               => __( 'Correct: this address is the preferred address, or it sends visitors to it with one permanent redirect.', 'wpseokit' ),
			'chain'            => __( 'Redirect chain: visitors make two or more hops. Redirect straight to the preferred address in one hop.', 'wpseokit' ),
			'temporary'        => __( 'The redirect is temporary (302, 303 or 307). Use a permanent redirect (301 or 308).', 'wpseokit' ),
			'duplicate'        => __( 'This address answers with its own page. It is a copy of the preferred address; redirect it.', 'wpseokit' ),
			'wrong_target'     => __( 'This address does not lead to the preferred address.', 'wpseokit' ),
			'https_downgrade'  => __( 'A step leaves HTTPS for HTTP. Remove it; never redirect from HTTPS to HTTP.', 'wpseokit' ),
			'loop'             => __( 'Redirect loop, or more hops than are followed. Turn off the PHP fallback and check the server rule.', 'wpseokit' ),
			'preferred_not_ok' => __( 'The preferred address itself does not answer with a normal page (it redirects or returns an error).', 'wpseokit' ),
			'unreachable'      => __( 'Could not be reached (no DNS record, no connection or an invalid certificate).', 'wpseokit' ),
		);
		return array(
			'verdict' => $code,
			'reason'  => 'unreachable' === $code ? ( '' !== $reason ? $reason : 'other' ) : '',
			'message' => $messages[ $code ] ?? '',
		);
	}

	/* ------------------------------------------------------------------ audit */

	/**
	 * The audit: four variants, then the places that must agree with the preferred address.
	 *
	 * @param array<string, mixed>|null $override Unsaved `preferred_scheme` / `preferred_host`.
	 * @return array<string, mixed>
	 */
	public static function audit( ?array $override = null ): array {
		self::$started = microtime( true );
		$home          = (array) wp_parse_url( home_url() );
		$h_hst         = strtolower( (string) ( $home['host'] ?? '' ) );
		$cfg_host      = (string) ( ( is_array( $override ) && isset( $override['preferred_host'] ) ) ? $override['preferred_host'] : SettingsRepository::get( 'redirects.preferred_host', '' ) );
		if ( '' !== $cfg_host && $cfg_host !== $h_hst ) {
			$target_check = PreferredAddressApplyService::validate_target( $cfg_host, $h_hst );
			if ( ! $target_check['ok'] ) {
				return array(
					'preferred'       => self::preferred(),
					'canonical'       => array( 'status' => 'error', 'href' => '', 'host' => '', 'matches' => false ),
					'variants'        => array(),
					'counts'          => array(),
					'warnings'        => array( __( 'The preferred address is not a valid address for this site. Network audit aborted.', 'wpseokit' ) ),
					'hsts'            => array( 'present' => false, 'header' => '' ),
					'rules'           => array( 'has_rule' => false, 'has_chain' => false ),
					'runtime_seconds' => 0.0,
				);
			}
		}
		$pref          = self::preferred( $override );
		$variants      = array();
		$counts        = array();
		$warnings      = array();
		$hsts          = array(
			'present' => false,
			'header'  => '',
		);
		$server_rule   = false;
		$chain         = false;
		foreach ( self::variants( $pref ) as $v ) {
			$t       = self::trace( $v['url'] );
			$d       = self::decide( $v['is_preferred'], $t, $pref );
			$variants[] = array(
				'url'          => $v['url'],
				'scheme'       => $v['scheme'],
				'host'         => $v['host'],
				'is_preferred' => $v['is_preferred'],
				'hops'         => $t['hops'],
				'redirects'    => $t['redirects'],
				'final_url'    => $t['final_url'],
				'verdict'      => $d['verdict'],
				'reason'       => $d['reason'],
				'message'      => $d['message'],
			);
			$counts[ $d['verdict'] ] = ( $counts[ $d['verdict'] ] ?? 0 ) + 1;
			if ( 'chain' === $d['verdict'] ) {
				$chain = true;
			}
			// A redirect with no X-Redirect-By header was not sent by WordPress or this plugin: the server or a CDN did it.
			$first = $t['hops'][0];
			if ( ! $v['is_preferred'] && in_array( $first['code'], self::REDIRECT_CODES, true ) && '' === $first['by'] ) {
				$server_rule = true;
			}
			if ( $v['is_preferred'] && 'https' === $v['scheme'] && '' !== $t['hsts'] ) {
				$hsts = array(
					'present' => true,
					'header'  => $t['hsts'],
				);
			}
			if ( 'unreachable' === $d['verdict'] && 'certificate' === $d['reason'] ) {
				/* translators: %s: address */
				$warnings[] = sprintf( __( 'The certificate of %s is not valid. Do not make HTTPS the preferred address until it is.', 'wpseokit' ), $v['url'] );
			}
		}
		$consistency = self::consistency( $pref );
		$fallback_on = (bool) SettingsRepository::get( 'redirects.php_fallback', false );
		if ( $server_rule ) {
			$warnings[] = __( 'The server (or a CDN) already redirects some variants. The PHP fallback is not needed; leave it off.', 'wpseokit' );
		}
		if ( $fallback_on && ( $chain || $server_rule ) ) {
			$warnings[] = __( 'The PHP fallback is on while the server also redirects: this can make a chain. Turn the fallback off.', 'wpseokit' );
		}
		if ( 'http' === $pref['scheme'] ) {
			$warnings[] = __( 'The preferred address is HTTP. Google prefers HTTPS pages as the canonical address.', 'wpseokit' );
		}
		return array(
			'preferred'      => $pref,
			'variants'       => $variants,
			'consistency'    => array_merge( $consistency, array( 'hsts' => $hsts ) ),
			'warnings'       => array_values( array_unique( $warnings ) ),
			'verdict_counts' => $counts,
		);
	}

	/**
	 * The places that must carry the preferred host: `home`, `siteurl`, the canonical tag, the sitemap and the feed.
	 *
	 * @return array<string, mixed>
	 */
	private static function consistency( array $pref ): array {
		$norm   = self::origin_of( $pref['origin'] );
		$home   = (string) get_option( 'home' );
		$site   = (string) get_option( 'siteurl' );
		$h_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$out    = array(
			'home'                     => $home,
			'siteurl'                  => $site,
			'home_matches_preferred'   => self::origin_of( $home ) === $norm,
			'siteurl_matches_preferred' => self::origin_of( $site ) === $norm,
		);

		// Canonical tag of the home page, read from the preferred address.
		$page    = $pref['origin'] . $h_path . '/';
		$res     = self::fetch( $page, 'GET' );
		$canon   = array(
			'url'     => $page,
			'status'  => 'skipped',
			'href'    => '',
			'host'    => '',
			'matches' => null,
		);
		if ( $res['code'] >= 200 && $res['code'] < 300 ) {
			$href            = self::canonical_of( $res['body'] );
			$canon['status'] = '' === $href ? 'missing' : 'checked';
			$canon['href']   = $href;
			$canon['host']   = '' === $href ? '' : Host::host( $href );
			$canon['matches'] = '' === $href ? false : self::origin_of( $href ) === $norm;
		}
		$out['canonical'] = $canon;

		$out['sitemap'] = self::listed( SitemapService::enabled() ? self::swap_origin( SitemapService::index_url(), $pref ) : '', '~<loc>\s*([^<\s][^<]*?)\s*</loc>~i', $norm );
		$out['feed']    = self::listed( self::swap_origin( get_feed_link(), $pref ), '~<link>\s*([^<\s][^<]*?)\s*</link>|<atom:link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']~i', $norm );
		return $out;
	}

	/**
	 * Fetches an XML address and compares the origins of the addresses in it.
	 *
	 * @return array{url: string, status: string, hosts: array<int, string>, mismatches: int}
	 */
	private static function listed( string $url, string $pattern, string $norm ): array {
		$out = array(
			'url'        => $url,
			'status'     => 'skipped',
			'hosts'      => array(),
			'mismatches' => 0,
		);
		if ( '' === $url ) {
			return $out;
		}
		$res = self::fetch( $url, 'GET' );
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			$out['status'] = 0 === $res['code'] ? 'error' : 'skipped';
			return $out;
		}
		$origins = array();
		if ( preg_match_all( $pattern, $res['body'], $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$u = html_entity_decode( '' !== ( $row[1] ?? '' ) ? $row[1] : ( $row[2] ?? '' ), ENT_QUOTES | ENT_XML1 );
				$o = self::origin_of( $u );
				if ( '' === $o ) {
					continue;
				}
				$origins[ $o ] = true;
				if ( $o !== $norm ) {
					++$out['mismatches'];
				}
			}
		}
		$out['hosts']  = array_keys( $origins );
		$out['status'] = array() === $origins ? 'empty' : ( 0 === $out['mismatches'] ? 'checked' : 'mismatch' );
		return $out;
	}

	/** Address in the first `<link rel="canonical">` of a page, or an empty string. */
	public static function canonical_of( string $html ): string {
		if ( preg_match_all( '/<link\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\brel\s*=\s*(["\'])canonical\1/i', $tag ) && preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $h ) ) {
					return html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5 );
				}
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ server rule generator */

	/**
	 * Parts both server rules and the logic table are built from (one place, so they cannot drift apart).
	 *
	 * @return array{https: bool, other: string, both_re: string, other_re: string}
	 */
	private static function rule_parts( array $pref ): array {
		$bare = str_replace( '.', '\.', $pref['bare'] );
		$port = '' === $pref['port'] ? '' : ':' . $pref['port'];
		return array(
			'https'    => 'https' === $pref['scheme'],
			'other'    => self::other_host( $pref ),
			// The two host names of this site (a port is allowed). Nothing else is touched.
			'both_re'  => '^(www\.)?' . $bare . '(:\d+)?$',
			// The host name that is NOT the preferred one.
			'other_re' => $pref['www'] ? '^' . $bare . '(:\d+)?$' : '^www\.' . $bare . '(:\d+)?$',
		);
	}

	/** Apache `.htaccess` text for the preferred address. */
	public static function apache_rule( array $pref ): string {
		$p      = self::rule_parts( $pref );
		$target = $p['https'] ? $pref['origin'] : '%{REQUEST_SCHEME}://' . $pref['host'] . ( '' === $pref['port'] ? '' : ':' . $pref['port'] );
		$lines  = array(
			'# WPSeoKit: preferred address ' . $pref['origin'],
			'# Paste ABOVE the "# BEGIN WordPress" block of .htaccess. The plugin never edits this file.',
			'# ONE rule, ONE hop (301): every other variant goes straight to ' . $pref['origin'] . ', path and query string kept.',
			'# WARNING: a server rule plus the PHP fallback (or a second redirect plugin) can make a redirect chain. Use one of them.',
			'# Check first that the TLS certificate is valid for both ' . $pref['bare'] . ' and www.' . $pref['bare'] . '.',
		);
		if ( $p['https'] ) {
			$lines[] = '# Behind a proxy or CDN that ends TLS, Apache sees plain HTTP for every visitor: replace';
			$lines[] = '# "%{HTTPS} !=on" with "%{HTTP:X-Forwarded-Proto} !=https", or the rule loops.';
		} else {
			$lines[] = '# HTTP is the preferred scheme: the rule only fixes the host name and keeps the scheme of the request,';
			$lines[] = '# so an HTTPS visitor is never sent back to HTTP.';
		}
		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = 'RewriteEngine On';
		if ( $p['https'] ) {
			$lines[] = 'RewriteCond %{HTTPS} !=on [OR]';
		}
		$lines[] = 'RewriteCond %{HTTP_HOST} ' . $p['other_re'] . ' [NC]';
		$lines[] = 'RewriteCond %{HTTP_HOST} ' . $p['both_re'] . ' [NC]';
		$lines[] = 'RewriteRule ^ ' . $target . '%{REQUEST_URI} [R=301,L]';
		$lines[] = '</IfModule>';
		return implode( "\n", $lines ) . "\n";
	}

	/** nginx `server` blocks for the preferred address. */
	public static function nginx_rule( array $pref ): string {
		$p      = self::rule_parts( $pref );
		$other  = $p['other'];
		$pref_h = $pref['host'];
		$port   = '' === $pref['port'] ? '' : ':' . $pref['port'];
		$lines  = array(
			'# WPSeoKit: preferred address ' . $pref['origin'],
			'# The plugin never edits nginx files. ONE redirect per variant, ONE hop (301), path and query string kept ($request_uri).',
			'# WARNING: a server rule plus the PHP fallback (or a second redirect plugin) can make a redirect chain. Use one of them.',
			'# Check first that the TLS certificate is valid for both ' . $pref['bare'] . ' and www.' . $pref['bare'] . '.',
		);
		if ( $p['https'] ) {
			$lines[] = '# Block 1 REPLACES your existing port-80 server block for this site; do not keep both.';
			$lines[] = 'server {';
			$lines[] = '    listen 80;';
			$lines[] = '    listen [::]:80;';
			$lines[] = '    server_name ' . $pref['bare'] . ' www.' . $pref['bare'] . ';';
			$lines[] = '    return 301 ' . $pref['origin'] . '$request_uri;';
			$lines[] = '}';
			$lines[] = '';
			$lines[] = '# Block 2: HTTPS on the other host name. Use the SAME certificate files as your main block (it must cover ' . $other . ').';
			$lines[] = 'server {';
			$lines[] = '    listen 443 ssl;';
			$lines[] = '    listen [::]:443 ssl;';
			$lines[] = '    server_name ' . $other . ';';
			$lines[] = '    ssl_certificate     /path/to/fullchain.pem;';
			$lines[] = '    ssl_certificate_key /path/to/privkey.pem;';
			$lines[] = '    return 301 ' . $pref['origin'] . '$request_uri;';
			$lines[] = '}';
			$lines[] = '';
			$lines[] = '# Your existing HTTPS server block for ' . $pref_h . ' (server_name ' . $pref_h . ';) stays as it is.';
		} else {
			$lines[] = '# HTTP is the preferred scheme: only the host name of plain HTTP is fixed. Nothing here redirects HTTPS to HTTP.';
			$lines[] = 'server {';
			$lines[] = '    listen 80;';
			$lines[] = '    listen [::]:80;';
			$lines[] = '    server_name ' . $other . ';';
			$lines[] = '    return 301 http://' . $pref_h . $port . '$request_uri;';
			$lines[] = '}';
		}
		return implode( "\n", $lines ) . "\n";
	}

	/** Note for a hosting panel (no server file access). */
	public static function panel_note( array $pref ): string {
		/* translators: 1: preferred address, 2: bare host name */
		return sprintf(
			__( 'Hosting panel without file access: create ONE permanent (301) redirect for the domain, with the "redirect with or without www" option set to %1$s, and switch on "force HTTPS" if your host offers it. Do not stack two panel redirects, and do not turn on the plugin PHP fallback next to them. Make sure the certificate covers %2$s and www.%2$s.', 'wpseokit' ),
			$pref['origin'],
			$pref['bare']
		);
	}

	/**
	 * What the server rule does to a request. A model of the generated text (same parts), used for the logic table.
	 *
	 * @param string $server `apache` or `nginx`.
	 * @return string|null Target address, or null when the rule lets the request through.
	 */
	public static function rule_target( string $server, array $pref, string $scheme, string $host ): ?string {
		$p     = self::rule_parts( $pref );
		$port  = '' === $pref['port'] ? '' : ':' . $pref['port'];
		$tgt   = $p['https'] ? $pref['origin'] : $scheme . '://' . $pref['host'] . $port;
		$other = 1 === preg_match( '~' . $p['other_re'] . '~i', $host );
		$both  = 1 === preg_match( '~' . $p['both_re'] . '~i', $host );
		if ( 'apache' === $server ) {
			$hit = $both && ( $other || ( $p['https'] && 'http' === $scheme ) );
		} else {
			// nginx: block 1 answers plain HTTP for both names, block 2 answers HTTPS for the other name.
			$hit = $p['https'] ? ( ( 'http' === $scheme && $both ) || ( 'https' === $scheme && $other ) ) : ( 'http' === $scheme && $other );
		}
		return $hit ? $tgt : null;
	}

	/**
	 * Generated rules and the logic table for the four variants.
	 *
	 * @param array<string, mixed>|null $override Unsaved `preferred_scheme` / `preferred_host`.
	 * @return array<string, mixed>
	 */
	public static function snippets( ?array $override = null ): array {
		$home  = (array) wp_parse_url( home_url() );
		$h_hst = strtolower( (string) ( $home['host'] ?? '' ) );
		$cfg_host = (string) ( ( is_array( $override ) && isset( $override['preferred_host'] ) ) ? $override['preferred_host'] : SettingsRepository::get( 'redirects.preferred_host', '' ) );
		if ( '' !== $cfg_host && $cfg_host !== $h_hst ) {
			$target_check = PreferredAddressApplyService::validate_target( $cfg_host, $h_hst );
			if ( ! $target_check['ok'] ) {
				return array(
					'preferred' => self::preferred(),
					'apache'    => '',
					'nginx'     => '',
					'panel'     => '',
					'warnings'  => array( __( 'The preferred address is not a valid address for this site. Server rules cannot be generated for an external domain.', 'wpseokit' ) ),
					'table'     => array(),
				);
			}
		}
		$pref  = self::preferred( $override );
		$table = array();
		foreach ( self::variants( $pref ) as $v ) {
			$row = array(
				'from' => $v['url'],
			);
			foreach ( array( 'apache', 'nginx' ) as $server ) {
				$to  = self::rule_target( $server, $pref, $v['scheme'], $v['host'] );
				$end = null === $to ? null : self::rule_target( $server, $pref, (string) wp_parse_url( $to, PHP_URL_SCHEME ), (string) wp_parse_url( $to, PHP_URL_HOST ) . ( wp_parse_url( $to, PHP_URL_PORT ) ? ':' . wp_parse_url( $to, PHP_URL_PORT ) : '' ) );
				$row[ $server ] = array(
					'hops' => null === $to ? 0 : ( null === $end ? 1 : 2 ),
					'to'   => null === $to ? $v['url'] : $to . '/',
				);
			}
			$row['is_preferred'] = $v['is_preferred'];
			$table[]             = $row;
		}
		$warnings = array(
			__( 'A server rule and the PHP fallback (or another redirect plugin) on top of each other make a redirect chain. Use one of them.', 'wpseokit' ),
			__( 'Check that the certificate is valid for both host names before you make HTTPS the preferred address.', 'wpseokit' ),
		);
		if ( 'http' === $pref['scheme'] ) {
			$warnings[] = __( 'The preferred address is HTTP. Google prefers HTTPS pages as the canonical address.', 'wpseokit' );
		}
		return array(
			'preferred' => $pref,
			'apache'    => self::apache_rule( $pref ),
			'nginx'     => self::nginx_rule( $pref ),
			'panel'     => self::panel_note( $pref ),
			'warnings'  => $warnings,
			'table'     => $table,
		);
	}

	/* ------------------------------------------------------------------ PHP fallback */

	/** `template_redirect` (priority 0): one 301 to the preferred address when the setting is on. */
	public static function maybe_redirect(): void {
		if ( ! SettingsRepository::get( 'redirects.php_fallback', false ) || is_multisite() || self::is_non_page_request() ) {
			return;
		}
		$target = self::fallback_target( self::request_context(), self::preferred(), (bool) SettingsRepository::get( 'redirects.trust_proxy_headers', false ) );
		if ( null === $target ) {
			return;
		}
		wp_redirect( $target, 301, 'WPSeoKit' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the target is built from the saved preferred address, never from the request host.
		exit;
	}

	/** Cron, AJAX, REST, XML-RPC, CLI and admin requests are not page views. */
	private static function is_non_page_request(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI || is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
	}

	/**
	 * What the fallback needs to know about the request.
	 *
	 * @return array{method: string, host: string, uri: string, ssl: bool, xf_proto: string, xf_host: string, other_https_claim: bool}
	 */
	public static function request_context(): array {
		$get = static function ( string $key ): string {
			return isset( $_SERVER[ $key ] ) ? trim( (string) wp_unslash( $_SERVER[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read only, each value is checked in fallback_target().
		};
		$first = static function ( string $v ): string {
			return strtolower( trim( (string) strtok( $v, ',' ) ) );
		};
		return array(
			'method'            => strtoupper( $get( 'REQUEST_METHOD' ) ),
			'host'              => strtolower( $get( 'HTTP_HOST' ) ),
			'uri'               => $get( 'REQUEST_URI' ),
			'ssl'               => is_ssl(),
			'xf_proto'          => $first( $get( 'HTTP_X_FORWARDED_PROTO' ) ),
			'xf_host'           => $first( $get( 'HTTP_X_FORWARDED_HOST' ) ),
			'other_https_claim' => 'on' === strtolower( $get( 'HTTP_X_FORWARDED_SSL' ) ) || 'on' === strtolower( $get( 'HTTP_FRONT_END_HTTPS' ) ) || false !== stripos( $get( 'HTTP_CF_VISITOR' ), '"https"' ),
		);
	}

	/**
	 * The address the fallback sends the request to, or null for "do nothing". Pure: reads only its arguments.
	 *
	 * Never redirects: other methods than GET/HEAD; WordPress housekeeping paths; hosts other than the two host names
	 * of this site; a request whose scheme cannot be told (a proxy header says HTTPS but the setting that trusts it is
	 * off). Never produces HTTPS to HTTP: with an HTTP preferred address the request keeps its own scheme.
	 *
	 * @param array $ctx   Result of request_context().
	 * @param array $pref  Result of preferred().
	 * @param bool  $trust Read X-Forwarded-Proto / X-Forwarded-Host.
	 */
	public static function fallback_target( array $ctx, array $pref, bool $trust ): ?string {
		if ( ! in_array( $ctx['method'], array( 'GET', 'HEAD' ), true ) ) {
			return null;
		}
		$uri = (string) $ctx['uri'];
		if ( '' === $uri || '/' !== $uri[0] || preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) ) {
			return null;
		}
		$raw_path = strtolower( (string) strtok( $uri, '?#' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = rawurldecode( $raw_path );
			if ( $decoded === $raw_path ) {
				break;
			}
			$raw_path = $decoded;
		}
		// D-2: Normalize consecutive slashes
		$path = (string) preg_replace( '#/{2,}#', '/', $raw_path );

		foreach ( self::FALLBACK_SKIP as $skip ) {
			if ( 0 === strpos( $path, $skip ) ) {
				return null;
			}
		}

		// D-2: Subdirectory install and segment-based skip
		$site_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$site_path = '/' . trim( $site_path, '/' );
		if ( '/' !== $site_path && str_starts_with( $path, $site_path . '/' ) ) {
			$sub_path = substr( $path, strlen( $site_path ) );
			foreach ( self::FALLBACK_SKIP as $skip ) {
				if ( 0 === strpos( $sub_path, $skip ) ) {
					return null;
				}
			}
		}

		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$skip_names = array( 'wp-admin', 'wp-json', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php', '.well-known' );
		foreach ( $segments as $segment ) {
			if ( in_array( $segment, $skip_names, true ) ) {
				return null;
			}
		}
		if ( '/wp-admin/admin-ajax.php' === $path || false !== strpos( $uri, 'rest_route=' ) || false !== strpos( $uri, 'doing_wp_cron' ) ) {
			return null;
		}
		$host = ( $trust && '' !== $ctx['xf_host'] ) ? $ctx['xf_host'] : $ctx['host'];
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		if ( $host !== $pref['bare'] && $host !== 'www.' . $pref['bare'] ) {
			return null;
		}
		if ( $trust && in_array( $ctx['xf_proto'], array( 'http', 'https' ), true ) ) {
			$scheme = $ctx['xf_proto'];
		} elseif ( $ctx['ssl'] ) {
			$scheme = 'https';
		} elseif ( ! $trust && ( 'https' === $ctx['xf_proto'] || $ctx['other_https_claim'] ) ) {
			return null;
		} else {
			$scheme = 'http';
		}
		$target_scheme = 'https' === $pref['scheme'] ? 'https' : $scheme;
		if ( $scheme === $target_scheme && $host === $pref['host'] ) {
			return null;
		}
		return $target_scheme . '://' . $pref['host'] . self::clean_port( $target_scheme, $pref['port'], true ) . $uri;
	}

	/* ------------------------------------------------------------------ REST */

	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/preferred-address/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_status' ),
					'permission_callback' => Guard::rest(),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/preferred-address/audit',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_audit' ),
					'permission_callback' => Guard::rest(),
					'args'                => array( 'redirects' => array( 'type' => 'object' ) ),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/preferred-address/snippets',
			array(
				array(
					'methods'             => array( \WP_REST_Server::READABLE, \WP_REST_Server::CREATABLE ),
					'callback'            => array( self::class, 'rest_snippets' ),
					'permission_callback' => Guard::rest(),
					'args'                => array( 'redirects' => array( 'type' => 'object' ) ),
				),
			)
		);
	}

	/**
	 * Unsaved `preferred_scheme` / `preferred_host` from the body, cleaned; null when none was sent.
	 *
	 * @return array<string, string>|null
	 */
	private static function override( \WP_REST_Request $request ): ?array {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();
		$in   = ( is_array( $body ) && isset( $body['redirects'] ) && is_array( $body['redirects'] ) ) ? $body['redirects'] : array();
		$out  = array();
		if ( isset( $in['preferred_scheme'] ) && in_array( $in['preferred_scheme'], array( 'auto', 'https', 'http' ), true ) ) {
			$out['preferred_scheme'] = (string) $in['preferred_scheme'];
		}
		if ( array_key_exists( 'preferred_host', $in ) ) {
			$host_cand = Settings::host( $in['preferred_host'] );
			$home_host = strtolower( Host::host( home_url() ) );
			if ( '' !== $host_cand && $host_cand !== $home_host ) {
				$val = PreferredAddressApplyService::validate_target( $host_cand, $home_host );
				if ( ! $val['ok'] ) {
					$host_cand = '';
				}
			}
			$out['preferred_host'] = $host_cand;
		}
		return array() === $out ? null : $out;
	}

	/** No network. */
	public static function rest_status(): \WP_REST_Response {
		$pref = self::preferred();
		$norm = self::origin_of( $pref['origin'] );
		$warn = array();
		if ( is_multisite() ) {
			$warn[] = __( 'A multisite network is outside the scope of the PHP fallback; it stays inactive. Use a server rule.', 'wpseokit' );
		}
		if ( 'http' === $pref['scheme'] ) {
			$warn[] = __( 'The preferred address is HTTP. Google prefers HTTPS pages as the canonical address.', 'wpseokit' );
		}
		if ( self::origin_of( (string) get_option( 'home' ) ) !== $norm || self::origin_of( (string) get_option( 'siteurl' ) ) !== $norm ) {
			$warn[] = __( 'WordPress Address and Site Address do not match the preferred address. Set both to the preferred address in Settings > General.', 'wpseokit' );
		}
		return new \WP_REST_Response(
			array(
				'preferred'                 => $pref,
				'home'                      => (string) get_option( 'home' ),
				'siteurl'                   => (string) get_option( 'siteurl' ),
				'home_matches_preferred'    => self::origin_of( (string) get_option( 'home' ) ) === $norm,
				'siteurl_matches_preferred' => self::origin_of( (string) get_option( 'siteurl' ) ) === $norm,
				'php_fallback'              => (bool) SettingsRepository::get( 'redirects.php_fallback', false ),
				'trust_proxy_headers'       => (bool) SettingsRepository::get( 'redirects.trust_proxy_headers', false ),
				'multisite'                 => is_multisite(),
				'warnings'                  => $warn,
			)
		);
	}

	/** Sends HTTP requests to this site's own addresses (POST: it is an action, not a cacheable read). */
	public static function rest_audit( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( self::audit( self::override( $request ) ) );
	}

	/** No network, no file written. */
	public static function rest_snippets( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( self::snippets( self::override( $request ) ) );
	}
}
