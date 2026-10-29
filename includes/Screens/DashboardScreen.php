<?php
/**
 * Dashboard: the front page of the plugin, two levels of tabs (`?tab=` and `?sub=`): Overview, SEO score, Reports, Logs, Yoast import, Settings.
 * Panels are printed here; the score and the reports are drawn by the script from the REST routes. The tools keep their own pages.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use Wpseokit\Plugin;
use Wpseokit\Registry;
use Wpseokit\Schema\Organization;
use Wpseokit\Services\ScoreService;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class DashboardScreen {

	public static function init(): void {
		Ui::boot();
	}

	/** Tabs: id => label, icon, sub-tabs (id => label). @return array<string, array{0:string,1:string,2:array<string,string>}> */
	private static function tabs(): array {
		return array(
			'overview' => array( __( 'Overview', 'wpseokit' ), 'home', array() ),
			'score'    => array(
				__( 'SEO score', 'wpseokit' ),
				'seo',
				array(
					'overview'        => __( 'Overview', 'wpseokit' ),
					'recommendations' => __( 'Recommendations', 'wpseokit' ),
					'posts'           => __( 'Posts', 'wpseokit' ),
					'how'             => __( 'How it is calculated', 'wpseokit' ),
				),
			),
			'reports'  => array( __( 'Reports', 'wpseokit' ), 'chart', array() ),
			'logs'     => array(
				__( 'Logs', 'wpseokit' ),
				'list',
				array(
					'activity' => __( 'Activity', 'wpseokit' ),
					'yoast'    => __( 'Yoast import log', 'wpseokit' ),
					'updates'  => __( 'Update checks', 'wpseokit' ),
					'errors'   => __( 'Errors', 'wpseokit' ),
				),
			),
			'yoast'    => array( __( 'Yoast import', 'wpseokit' ), 'down', array() ),
			'settings' => array( __( 'Settings', 'wpseokit' ), 'gear', array() ),
		);
	}

	/** Published posts and pages that carry their own meta description. @return array{0:int,1:int} */
	private static function described(): array {
		global $wpdb;
		$types = array_keys( Ui::post_types() );
		if ( ! $types ) {
			return array( 0, 0 );
		}
		$marks = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- read-only counts; placeholders are generated above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($marks)", $types ) );
		$done  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wpseokit_description' AND m.meta_value <> '' WHERE p.post_status = 'publish' AND p.post_type IN ($marks)",
				$types
			)
		);
		// phpcs:enable
		return array( $done, $total );
	}

	private static function badge( string $type, string $icon, string $text ): string {
		return '<span class="rbn-badge rbn-badge--' . esc_attr( $type ) . '">' . Ui::icon( $icon ) . esc_html( $text ) . '</span>';
	}

	/**
	 * Badge for the publisher status; the same four states on the card and in the checklist.
	 *
	 * @param array{key:string,logo:array<string,mixed>,small:bool} $pub Organization::publisher_status().
	 */
	private static function publisher_badge( array $pub, bool $card = false ): string {
		switch ( $pub['key'] ) {
			case 'set':
				return self::badge( 'ok', 'check', $card ? __( 'Publisher set', 'wpseokit' ) : __( 'Added', 'wpseokit' ) );
			case 'icon':
				return self::badge( 'warn', 'alert', $pub['small'] ? __( 'Logo: site icon too small', 'wpseokit' ) : __( 'Logo: site icon in use', 'wpseokit' ) );
			case 'logo_missing':
				return self::badge( 'warn', 'alert', __( 'Logo missing', 'wpseokit' ) );
			default:
				return self::badge( 'warn', 'alert', $card ? __( 'Publisher missing', 'wpseokit' ) : __( 'Missing', 'wpseokit' ) );
		}
	}

	/** @return array<int, array{0:string,1:string,2:string,3:string,4:bool,5?:string}> check, badge html, action label, action url, primary, extra link attributes */
	public static function checks(): array {
		$defaults  = Settings::defaults();
		$templates = (array) Ui::val( 'templates', array() ) === (array) ( $defaults['templates'] ?? array() );
		$pub       = Organization::publisher_status();
		$sitemap   = (bool) Ui::val( 'sitemap.enabled', true );
		$public    = (bool) get_option( 'blog_public', 1 );
		$primary   = (bool) Ui::val( 'general.primary_category', true );
		return array(
			array(
				__( 'Title and description templates', 'wpseokit' ),
				$templates ? self::badge( 'warn', 'alert', __( 'Using defaults', 'wpseokit' ) ) : self::badge( 'ok', 'check', __( 'Configured', 'wpseokit' ) ),
				__( 'Edit templates', 'wpseokit' ),
				Shell::url( 'templates' ),
				$templates,
			),
			array(
				__( 'Organization name and logo', 'wpseokit' ),
				self::publisher_badge( $pub ),
				'set' === $pub['key'] ? __( 'Edit schema', 'wpseokit' ) : ( 'missing' === $pub['key'] ? __( 'Add details', 'wpseokit' ) : __( 'Add organization logo', 'wpseokit' ) ),
				Shell::url( 'schema', 'organization' ),
				'set' !== $pub['key'],
			),
			array(
				__( 'XML sitemap', 'wpseokit' ),
				$sitemap ? self::badge( 'ok', 'check', __( 'On', 'wpseokit' ) ) : self::badge( 'warn', 'alert', __( 'Off', 'wpseokit' ) ),
				__( 'Open sitemap', 'wpseokit' ),
				Shell::url( 'sitemap' ),
				! $sitemap,
			),
			array(
				__( 'Search engine visibility', 'wpseokit' ),
				$public ? self::badge( 'ok', 'check', __( 'Visible', 'wpseokit' ) ) : self::badge( 'warn', 'alert', __( 'Search engines are discouraged', 'wpseokit' ) ),
				__( 'Open Reading settings', 'wpseokit' ),
				admin_url( 'options-reading.php' ),
				! $public,
			),
			array(
				__( 'Primary categories', 'wpseokit' ),
				$primary ? self::badge( 'ok', 'check', __( 'On', 'wpseokit' ) ) : self::badge( 'warn', 'alert', __( 'Off', 'wpseokit' ) ),
				__( 'Open settings', 'wpseokit' ),
				Shell::url( 'settings', 'general' ),
				false,
				' data-goto="settings:general"',
			),
			array(
				__( 'Preferred address', 'wpseokit' ),
				self::badge( 'info', 'info', __( 'Not checked yet', 'wpseokit' ) ),
				__( 'Check addresses', 'wpseokit' ),
				Shell::url( 'redirects', 'pref' ),
				false,
			),
			array(
				__( 'Yoast SEO data', 'wpseokit' ),
				'<span data-import-state>' . self::badge( 'info', 'info', __( 'Not checked', 'wpseokit' ) ) . '</span>',
				__( 'Review import', 'wpseokit' ),
				Shell::url( 'import' ),
				false,
				' data-goto="yoast"',
			),
		);
	}

	/** Badge, live line and second action of a tool card. @return array{0:string,1:string,2:string} */
	private static function card_state( string $id ): array {
		$ok   = static fn( string $t ): string => self::badge( 'ok', 'check', $t );
		$warn = static fn( string $t ): string => self::badge( 'warn', 'alert', $t );
		$mute = static fn( string $t ): string => self::badge( 'mute', 'pause', $t );
		$link = static fn( string $tool, string $tab, string $text ): string => '<a class="rbn-btn" href="' . esc_url( Shell::url( $tool, $tab ) ) . '">' . esc_html( $text ) . '</a>';
		switch ( $id ) {
			case 'templates':
				[ $done, $total ] = self::described();
				/* translators: 1: posts with a description, 2: all posts. */
				$line = sprintf( __( '%1$d of %2$d posts have a description', 'wpseokit' ), $done, $total );
				return array( $ok( __( 'Configured', 'wpseokit' ) ), $line, $link( 'templates', 'posts', __( 'Edit post template', 'wpseokit' ) ) );
			case 'schema':
				$pub   = Organization::publisher_status();
				$named = 'missing' !== $pub['key'];
				$badge = self::publisher_badge( $pub, true );
				$label = '' !== trim( (string) Ui::val( 'schema.organization_name', '' ) ) ? __( 'Edit organization', 'wpseokit' ) : ( $named ? __( 'Edit publisher', 'wpseokit' ) : __( 'Add organization', 'wpseokit' ) );
				if ( in_array( $pub['key'], array( 'icon', 'logo_missing' ), true ) ) {
					$label = __( 'Add organization logo', 'wpseokit' );
				}
				return array( $badge, __( 'Article, WebSite and breadcrumb schema', 'wpseokit' ), $link( 'schema', 'organization', $label ) );
			case 'sitemap':
				$on = (bool) Ui::val( 'sitemap.enabled', true );
				return array( $on ? $ok( __( 'Reachable', 'wpseokit' ) ) : $mute( __( 'Off', 'wpseokit' ) ), $on ? __( 'Address: /sitemap_index.xml', 'wpseokit' ) : __( 'Not published', 'wpseokit' ), '<button type="button" class="rbn-btn" data-sitemap-flush>' . esc_html__( 'Rebuild now', 'wpseokit' ) . '</button>' );
			case 'feed':
				$on   = (bool) Ui::val( 'feed.main_enabled', true );
				$mode = 'full' === (string) Ui::val( 'feed.content_mode', 'inherit' ) ? __( 'Full text', 'wpseokit' ) : ( 'summary' === (string) Ui::val( 'feed.content_mode', 'inherit' ) ? __( 'Summary only', 'wpseokit' ) : __( 'WordPress default', 'wpseokit' ) );
				/* translators: 1: Summary only / Full text, 2: number of items. */
				$line = sprintf( __( '%1$s · %2$d items', 'wpseokit' ), $mode, ( (int) Ui::val( 'feed.items_count', 0 ) ?: (int) get_option( 'posts_per_rss', 10 ) ) );
				return array( $on ? $ok( __( 'Main feed on', 'wpseokit' ) ) : $mute( __( 'Off', 'wpseokit' ) ), $line, $link( 'feed', 'cleanup', __( 'Close extra feeds', 'wpseokit' ) ) );
			case 'llms':
				$on = (bool) Ui::val( 'llms.enabled', false );
				return array( $on ? $ok( __( 'Published', 'wpseokit' ) ) : $mute( __( 'Off', 'wpseokit' ) ), $on ? __( 'Address: /llms.txt', 'wpseokit' ) : __( 'Not published yet', 'wpseokit' ), $on ? $link( 'llms', 'preview', __( 'Preview', 'wpseokit' ) ) : '<button type="button" class="rbn-btn" data-llms-on>' . esc_html__( 'Turn on', 'wpseokit' ) . '</button>' );
			case 'redirects':
				return array( '<span data-card-badge="redirects">' . $ok( __( 'Active', 'wpseokit' ) ) . '</span>', __( 'Loading…', 'wpseokit' ), $link( 'redirects', 'pref', __( 'Check address', 'wpseokit' ) ) );
			case 'log404':
				return array( '<span data-card-badge="log404">' . $mute( __( 'Checking', 'wpseokit' ) ) . '</span>', __( 'Loading…', 'wpseokit' ), $link( 'log404', '', __( 'Fix top errors', 'wpseokit' ) ) );
			default:
				return array( '', '', '' );
		}
	}

	private static function tool_cards(): string {
		$out   = '<h2 class="wps-sect">' . esc_html__( 'SEO tools', 'wpseokit' ) . '</h2><ul class="wps-tools">';
		$yoast = Plugin::yoast_active();
		foreach ( Shell::tools() as $id => $t ) {
			[ $badge, $line, $more ] = self::card_state( $id );
			if ( $yoast && ! in_array( $id, array( 'redirects', 'log404' ), true ) ) {
				$badge = self::badge( 'warn', 'pause', __( 'Paused (Yoast active)', 'wpseokit' ) );
			}
			/* translators: %s: tool name. */
			$open = sprintf( __( 'Open %s', 'wpseokit' ), $t['label'] );
			$out .= '<li><article class="rbn-card wps-tool"><div class="rbn-card__b"><div class="wps-tool__top"><span class="wps-tool__i">' . Ui::icon( $t['icon'] ) . '</span><h3 class="wps-tool__n"><a href="' . esc_url( Shell::url( $id ) ) . '">' . esc_html( $t['label'] ) . '</a></h3></div>'
				. '<p class="wps-tool__d">' . esc_html( $t['desc'] ) . '</p><p class="wps-tool__s">' . $badge . '</p><p class="wps-tool__m" data-card-line="' . esc_attr( $id ) . '">' . esc_html( $line ) . '</p></div>'
				. '<div class="rbn-card__foot"><a class="rbn-btn rbn-btn--pri" href="' . esc_url( Shell::url( $id ) ) . '" aria-label="' . esc_attr( $open ) . '">' . esc_html__( 'Open', 'wpseokit' ) . '</a>' . $more . '</div></article></li>';
		}
		return $out . '</ul>';
	}

	/** Number of setup checks that still ask for attention. */
	private static function missing(): int {
		return count( array_filter( self::checks(), static fn( array $c ): bool => $c[4] ) );
	}

	/** Banner above the overview when setup items are missing. */
	private static function band(): string {
		$n = self::missing();
		if ( $n < 1 ) {
			return '';
		}
		/* translators: %d: number of setup items. */
		$text = sprintf( _n( '%d setup item is missing.', '%d setup items are missing.', $n, 'wpseokit' ), $n );
		return '<div class="wps-band" role="status"><p>' . Ui::icon( 'alert' ) . ' ' . esc_html( $text ) . '</p><a class="rbn-btn rbn-btn--pri" href="' . esc_url( Shell::dash_url( 'settings', 'setup' ) ) . '" data-goto="settings:setup">' . esc_html__( 'Go to Setup', 'wpseokit' ) . Ui::icon( 'chevr' ) . '</a></div>';
	}

	/** Whether a site score has been stored yet (the score route would measure the site on its first call). */
	private static function calculated(): bool {
		$stored = get_option( ScoreService::OPTION_SITE );
		return is_array( $stored ) && isset( $stored['score'], $stored['config'] );
	}

	/** Card with the loading skeleton that the script replaces with the score summary. */
	private static function score_summary(): string {
		$h = '<h2 class="wps-score__h">' . esc_html__( 'SEO score', 'wpseokit' ) . '</h2>';
		if ( Plugin::yoast_active() ) {
			return '<section class="rbn-card wps-score wps-sum"><div class="rbn-card__b"><h2 class="wps-score__h"><a class="wps-stretch" href="' . esc_url( Shell::dash_url( 'score' ) ) . '" data-goto="score">' . esc_html__( 'SEO score', 'wpseokit' ) . '</a></h2>'
				. Ui::note( 'info', '<b>' . esc_html__( 'The score is paused while Yoast SEO is active.', 'wpseokit' ) . '</b> ' . esc_html__( 'Import your Yoast data to continue.', 'wpseokit' ) ) . '</div></section>';
		}
		return '<div data-score-summary data-calculated="' . ( self::calculated() ? '1' : '0' ) . '"><section class="rbn-card wps-score" aria-busy="true"><div class="rbn-card__b">' . $h . '<p class="wps-busy" role="status"><span class="wps-spin" aria-hidden="true"></span>' . esc_html__( 'Loading…', 'wpseokit' ) . '</p><span class="wps-skel wps-skel--w50"></span></div></section></div>';
	}

	private static function stats(): string {
		[ $done, $total ] = self::described();
		$stats            = array(
			array( 'sitemap', 'sitemap', __( 'URLs in the sitemap', 'wpseokit' ), '–', '', 'sitemap' ),
			array( 'redirects', 'arrows', __( 'Active redirects', 'wpseokit' ), '–', '', 'redirects' ),
			array( 'log', 'alert', __( '404 errors logged', 'wpseokit' ), '–', '', 'log404' ),
			array( 'described', 'doc', __( 'Posts with a description', 'wpseokit' ), (string) $done, ' <small>/ ' . (int) $total . '</small>', 'templates' ),
		);
		$out              = '<ul class="rbn-stats">';
		foreach ( $stats as $s ) {
			$out .= '<li class="rbn-stat wps-sum"><p class="rbn-stat__k">' . Ui::icon( $s[1] ) . '<a class="wps-stretch" href="' . esc_url( Shell::url( $s[5] ) ) . '">' . esc_html( $s[2] ) . '</a></p><p class="rbn-stat__v" data-stat="' . esc_attr( $s[0] ) . '">' . esc_html( $s[3] ) . $s[4] . '</p><p class="rbn-stat__s" data-stat-sub="' . esc_attr( $s[0] ) . '"></p></li>';
		}
		return $out . '</ul>';
	}

	private static function overview(): string {
		return '<h2 class="rbn-sr">' . esc_html__( 'Overview', 'wpseokit' ) . '</h2>' . self::band() . self::score_summary() . self::stats() . self::tool_cards();
	}

	/** Empty state card (icon, title, text, optional action html). */
	private static function empty_state( string $icon, string $title, string $text, string $act = '' ): string {
		return '<section class="rbn-card"><div class="rbn-card__b"><div class="rbn-empty"><span class="rbn-empty__i">' . Ui::icon( $icon ) . '</span><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>' . $act . '</div></div></section>';
	}

	private static function skeleton_cards(): string {
		$card = '<li><section class="rbn-card"><div class="rbn-card__b"><span class="wps-skel wps-skel--w30"></span><span class="wps-skel wps-skel--tall"></span><span class="wps-skel wps-skel--w70"></span></div></section></li>';
		return '<ul class="wps-reports">' . $card . $card . '</ul>';
	}

	private static function paused_card( string $text ): string {
		return '<section class="rbn-card wps-score"><div class="rbn-card__b"><h2 class="wps-score__h">' . esc_html__( 'Paused', 'wpseokit' ) . '</h2>'
			. Ui::note( 'info', '<b>' . esc_html__( 'Yoast SEO is active.', 'wpseokit' ) . '</b> ' . esc_html( $text ) )
			. '<p><a class="rbn-btn rbn-btn--pri" href="' . esc_url( Shell::dash_url( 'yoast' ) ) . '" data-goto="yoast">' . esc_html__( 'Import Yoast data', 'wpseokit' ) . '</a></p></div></section>';
	}

	/** Loading placeholder of a score view; the script swaps the content in. */
	private static function view( string $name ): string {
		return '<div data-score-view="' . esc_attr( $name ) . '" aria-live="polite"><p class="wps-busy" role="status"><span class="wps-spin" aria-hidden="true"></span>' . esc_html__( 'Loading…', 'wpseokit' ) . '</p>' . self::skeleton_cards() . '</div>';
	}

	/** @return array<string, string> sub id => panel html */
	private static function score_panels(): array {
		if ( Plugin::yoast_active() ) {
			return array();
		}
		$err = '<div data-score-error hidden><div class="rbn-note rbn-note--err" role="alert">' . Ui::icon( 'x' ) . '<div><p><b>' . esc_html__( 'The score could not be recalculated.', 'wpseokit' ) . '</b> ' . esc_html__( 'WPSeoKit could not finish checking the site. The results below are from the last successful calculation.', 'wpseokit' ) . '</p><p><button type="button" class="rbn-btn" data-recalc>' . Ui::icon( 'undo' ) . esc_html__( 'Try again', 'wpseokit' ) . '</button></p></div></div></div>';
		return array(
			'overview'        => $err . '<h2 class="rbn-sr">' . esc_html__( 'Score overview', 'wpseokit' ) . '</h2>' . self::view( 'overview' ),
			'recommendations' => '<h2 class="rbn-sr">' . esc_html__( 'Recommendations', 'wpseokit' ) . '</h2>' . self::view( 'recommendations' ),
			'posts'           => '<h2 class="rbn-sr">' . esc_html__( 'Posts', 'wpseokit' ) . '</h2>' . self::view( 'posts' ),
			'how'             => '<h2 class="rbn-sr">' . esc_html__( 'How it is calculated', 'wpseokit' ) . '</h2>' . self::view( 'how' ),
		);
	}

	private static function reports(): string {
		if ( Plugin::yoast_active() ) {
			return '<h2 class="rbn-sr">' . esc_html__( 'Reports', 'wpseokit' ) . '</h2>' . self::paused_card( __( 'Reports are not recorded while WPSeoKit output is paused. Import your Yoast data to continue.', 'wpseokit' ) );
		}
		$snap = '<button type="button" class="rbn-btn rbn-btn--pri" data-snapshot>' . Ui::icon( 'scan' ) . esc_html__( 'Take snapshot now', 'wpseokit' ) . '</button>';
		$busy = static fn( string $text ): string => '<p class="wps-busy" role="status"><span class="wps-spin" aria-hidden="true"></span>' . esc_html( $text ) . '</p>' . self::skeleton_cards();
		return '<h2 class="rbn-sr">' . esc_html__( 'Reports', 'wpseokit' ) . '</h2><div data-reports data-csv="' . esc_url( rest_url( 'wpseokit/v1/reports/export' ) ) . '">'
			. '<div data-rep="nodata" hidden>' . self::empty_state( 'chart', __( 'No report data yet', 'wpseokit' ), __( 'WPSeoKit saves one snapshot of your numbers each day. You can also take one now; it changes nothing on your site.', 'wpseokit' ), '<div class="wps-row">' . $snap . '<a class="rbn-btn" href="' . esc_url( Shell::dash_url( 'settings', 'advanced' ) ) . '" data-goto="settings:advanced">' . esc_html__( 'Snapshot settings', 'wpseokit' ) . '</a></div>' ) . '</div>'
			. '<div data-rep="preparing" aria-busy="true" hidden>' . $busy( __( 'Preparing your first snapshot… This usually takes a few seconds.', 'wpseokit' ) ) . '</div>'
			. '<div data-rep="loading" aria-busy="true">' . $busy( __( 'Loading your reports…', 'wpseokit' ) ) . '</div>'
			. '<div data-rep="error" hidden><div class="rbn-note rbn-note--err" role="alert">' . Ui::icon( 'alert' ) . '<div><p><b>' . esc_html__( 'WPSeoKit could not load the reports.', 'wpseokit' ) . '</b> ' . esc_html__( 'Your data is safe. Try again in a moment.', 'wpseokit' ) . '</p><div class="rbn-note__act"><button type="button" class="rbn-btn rbn-btn--pri" data-rep-retry>' . esc_html__( 'Try again', 'wpseokit' ) . '</button></div></div></div></div>'
			. '<div data-rep="ok" hidden><div data-rep-one hidden></div><div data-rep-main>'
			. '<div class="wps-range"><div class="wps-radios" role="radiogroup" aria-label="' . esc_attr__( 'Report range', 'wpseokit' ) . '"><label class="rbn-check"><input type="radio" name="rep-range" value="7"><span>' . esc_html__( '7 days', 'wpseokit' ) . '</span></label><label class="rbn-check"><input type="radio" name="rep-range" value="30" checked><span>' . esc_html__( '30 days', 'wpseokit' ) . '</span></label><label class="rbn-check"><input type="radio" name="rep-range" value="90"><span>' . esc_html__( '90 days', 'wpseokit' ) . '</span></label></div>'
			. '<div class="wps-range__act"><button type="button" class="rbn-btn" data-snapshot>' . Ui::icon( 'scan' ) . esc_html__( 'Take snapshot now', 'wpseokit' ) . '</button><a class="rbn-btn" href="' . esc_url( rest_url( 'wpseokit/v1/reports/export' ) ) . '" data-csv-link>' . Ui::icon( 'down' ) . esc_html__( 'Download CSV', 'wpseokit' ) . '</a><button type="button" class="rbn-btn" data-print>' . Ui::icon( 'doc' ) . esc_html__( 'Print', 'wpseokit' ) . '</button></div></div>'
			. '<p class="rbn-help wps-meta" data-rep-meta></p><div data-rep-charts></div></div></div></div>';
	}

	/**
	 * One log panel: a card whose body is AdminKit's shared log list (`adminkit-core-logs`) bound to one channel.
	 * The list draws the loading, empty, no-match and error states itself; the script (data-wps-logs) starts it when the sub-tab is shown.
	 *
	 * @param string $channel Log channel id, e.g. `wpseokit.activity`.
	 * @param string $title   Card heading.
	 * @param string $lead    One line under the heading.
	 * @param bool   $filters Show the list's level / event / date filters.
	 * @param string $before  Static html above the card (notes).
	 */
	private static function log_panel( string $channel, string $title, string $lead, bool $filters, string $before = '' ): string {
		return $before . '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $lead ) . '</p></div><div class="rbn-card__b">'
			. '<div data-wps-logs="' . esc_attr( substr( $channel, strpos( $channel, '.' ) + 1 ) ) . '" data-channel="' . esc_attr( $channel ) . '" data-per-page="8" data-filters="' . ( $filters ? '1' : '0' ) . '"></div></div></section>';
	}

	/** @return array<string, string> sub id => panel html */
	private static function log_panels(): array {
		return array(
			'activity' => self::log_panel( Registry::ACTIVITY, __( 'Activity', 'wpseokit' ), __( 'What WPSeoKit and you changed on this site.', 'wpseokit' ), true, '<div data-log-note></div>' ),
			'yoast'    => self::log_panel( Registry::YOAST, __( 'Yoast import log', 'wpseokit' ), __( 'Every check and import step, newest first.', 'wpseokit' ), false ),
			'updates'  => self::log_panel(
				Registry::UPDATE_LOG,
				__( 'Update checks', 'wpseokit' ),
				__( 'Each time WPSeoKit looks for a new version, newest first.', 'wpseokit' ),
				false,
				'<div class="rbn-note">' . Ui::icon( 'info' ) . '<div><p>' . esc_html__( 'A check that cannot reach the release list is expected until the first release is published. WPSeoKit tries again later.', 'wpseokit' ) . '</p></div></div>'
			),
			'errors'   => self::log_panel( Registry::ERRORS, __( 'Errors', 'wpseokit' ), __( 'Background problems, newest first.', 'wpseokit' ), true ),
		);
	}

	/** Sub-tab bar of one tab. @param array<string, string> $subs sub id => label. */
	private static function sub_bar( string $top, array $subs, string $active, string $label ): string {
		$out = '<div class="rbn-tabs rbn-tabs--sub" role="tablist" aria-label="' . esc_attr( $label ) . '" data-rbn-tabs data-subtabs="' . esc_attr( $top ) . '">';
		foreach ( $subs as $id => $text ) {
			$on   = (string) $id === $active;
			$out .= '<a class="rbn-tabs__tab" role="tab" id="stab-' . esc_attr( $top . '-' . $id ) . '" aria-controls="spn-' . esc_attr( $top . '-' . $id ) . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '" tabindex="' . ( $on ? '0' : '-1' ) . '" href="' . esc_url( Shell::dash_url( $top, (string) $id ) ) . '" data-sub="' . esc_attr( (string) $id ) . '" data-key="' . esc_attr( (string) $id ) . '">' . esc_html( $text ) . '</a>';
		}
		return $out . '</div>';
	}

	/** @param array<string, string> $subs sub id => label. @param array<string, string> $bodies sub id => html. */
	private static function sub_panels( string $top, array $subs, array $bodies, string $active, string $label ): string {
		$out = self::sub_bar( $top, $subs, $active, $label );
		foreach ( $subs as $id => $text ) {
			$out .= '<div class="rbn-tabpanel" role="tabpanel" id="spn-' . esc_attr( $top . '-' . $id ) . '" aria-labelledby="stab-' . esc_attr( $top . '-' . $id ) . '" data-sub="' . esc_attr( (string) $id ) . '"' . ( (string) $id === $active ? '' : ' hidden' ) . '>' . ( $bodies[ $id ] ?? '' ) . '</div>';
		}
		return $out;
	}

	/**
	 * Prints the dashboard.
	 *
	 * @param string $top Wanted tab id; unknown ids open the Overview.
	 * @param string $sub Wanted sub-tab id; unknown ids open the first one.
	 */
	public static function render( string $top = '', string $sub = '' ): void {
		$tabs = self::tabs();
		$top  = isset( $tabs[ $top ] ) ? $top : 'overview';

		$yoast = array();
		foreach ( ImportScreen::panels() as $id => $p ) {
			$yoast[ $id ] = array( $p[0], $p[1] );
		}
		$settings = SettingsScreen::panels();

		$subs = array(
			'score'    => $tabs['score'][2],
			'logs'     => $tabs['logs'][2],
			'yoast'    => array_map( static fn( array $p ): string => $p[0], $yoast ),
			'settings' => array_map( static fn( array $p ): string => $p[0], $settings ),
		);
		$body = array(
			'overview' => self::overview(),
			'reports'  => self::reports(),
		);
		$deep = array(
			'score'    => self::score_panels(),
			'logs'     => self::log_panels(),
			'yoast'    => array_map( static fn( array $p ): string => $p[1], $yoast ),
			'settings' => array_map( static fn( array $p ): string => $p[1], $settings ),
		);
		if ( Plugin::yoast_active() ) { // The score is not measured while output is paused.
			$body['score'] = '<h2 class="rbn-sr">' . esc_html__( 'SEO score', 'wpseokit' ) . '</h2>' . self::paused_card( __( 'WPSeoKit prints nothing now, so a score would not describe what search engines see. Import your Yoast data to continue.', 'wpseokit' ) );
		}

		$list   = '<div class="rbn-tabs" role="tablist" aria-label="' . esc_attr__( 'Dashboard sections', 'wpseokit' ) . '" data-rbn-tabs data-dtabs>';
		$panels = '';
		foreach ( $tabs as $id => $t ) {
			$on      = $id === $top;
			$list   .= '<a class="rbn-tabs__tab" role="tab" id="dtab-' . esc_attr( $id ) . '" aria-controls="dpan-' . esc_attr( $id ) . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '" tabindex="' . ( $on ? '0' : '-1' ) . '" href="' . esc_url( Shell::dash_url( $id ) ) . '" data-top="' . esc_attr( $id ) . '" data-key="' . esc_attr( $id ) . '">' . Ui::icon( $t[1] ) . esc_html( $t[0] ) . '</a>';
			$inner   = $body[ $id ] ?? '';
			if ( isset( $deep[ $id ] ) && $deep[ $id ] ) {
				$active = isset( $subs[ $id ][ $sub ] ) && $on ? $sub : (string) array_key_first( $subs[ $id ] );
				$inner  = self::sub_panels( $id, $subs[ $id ], $deep[ $id ], $active, $t[0] );
			}
			$panels .= '<div role="tabpanel" id="dpan-' . esc_attr( $id ) . '" aria-labelledby="dtab-' . esc_attr( $id ) . '" class="rbn-tabpanel" data-top="' . esc_attr( $id ) . '"' . ( $on ? '' : ' hidden' ) . '>' . $inner . '</div>';
		}
		$list .= '</div>';

		$out  = Ui::open( 'dashboard' ) . '<div class="rbn-head"><div class="rbn-head__id"><h1>' . esc_html__( 'WPSeoKit', 'wpseokit' ) . '</h1><span class="rbn-chip">' . esc_html( WPSEOKIT_VERSION ) . '</span>' . Shell::chip() . '</div><div class="rbn-head__act"></div></div>';
		$out .= Shell::yoast_note() . $list . $panels . ImportScreen::dialog();
		echo $out . Ui::close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped where it is built.
	}
}
