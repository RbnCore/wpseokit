<?php
/**
 * Dashboard: the front page of the plugin. Four counters, one card per tool, the setup checklist and recent activity.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use Wpseokit\Plugin;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class DashboardScreen {

	public static function init(): void {
		Ui::boot();
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

	/** @return array<int, array{string,string,string,string,bool}> check, badge html, action label, action url, primary */
	private static function checks(): array {
		$defaults  = Settings::defaults();
		$templates = (array) Ui::val( 'templates', array() ) === (array) ( $defaults['templates'] ?? array() );
		$org       = '' !== trim( (string) Ui::val( 'schema.organization_name', '' ) ) || '' !== trim( (string) Ui::val( 'schema.person_name', '' ) );
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
				$org ? self::badge( 'ok', 'check', __( 'Added', 'wpseokit' ) ) : self::badge( 'warn', 'alert', __( 'Missing', 'wpseokit' ) ),
				$org ? __( 'Edit schema', 'wpseokit' ) : __( 'Add details', 'wpseokit' ),
				Shell::url( 'schema', 'organization' ),
				! $org,
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
				Shell::url( 'settings', 'advanced' ),
				false,
			),
			array(
				__( 'Yoast SEO data', 'wpseokit' ),
				'<span data-import-state>' . self::badge( 'info', 'info', __( 'Not checked', 'wpseokit' ) ) . '</span>',
				__( 'Review import', 'wpseokit' ),
				Shell::url( 'import' ),
				false,
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
				$has_logo = (int) Ui::val( 'schema.organization_logo_id', 0 ) > 0 || '' !== (string) Ui::val( 'schema.organization_logo_url', '' );
				$named    = '' !== trim( (string) Ui::val( 'schema.organization_name', '' ) ) || '' !== trim( (string) Ui::val( 'schema.person_name', '' ) );
				$badge    = $named && $has_logo ? $ok( __( 'Publisher set', 'wpseokit' ) ) : $warn( $named ? __( 'Logo missing', 'wpseokit' ) : __( 'Publisher missing', 'wpseokit' ) );
				return array( $badge, __( 'Article, WebSite and breadcrumb schema', 'wpseokit' ), $link( 'schema', 'organization', __( 'Add organization', 'wpseokit' ) ) );
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
				return array( '<span data-card-badge="redirects">' . $ok( __( 'Active', 'wpseokit' ) ) . '</span>', __( 'Loading…', 'wpseokit' ), $link( 'redirects', 'add', __( 'Add redirect', 'wpseokit' ) ) );
			case 'log404':
				return array( '<span data-card-badge="log404">' . $mute( __( 'Checking', 'wpseokit' ) ) . '</span>', __( 'Loading…', 'wpseokit' ), $link( 'log404', '', __( 'Fix top errors', 'wpseokit' ) ) );
			case 'import':
				return array( '<span data-import-state>' . self::badge( 'info', 'info', __( 'Not checked', 'wpseokit' ) ) . '</span>', Plugin::yoast_active() ? __( 'Yoast SEO is active on this site', 'wpseokit' ) : __( 'Yoast SEO is not active', 'wpseokit' ), $link( 'import', 'report', __( 'View report', 'wpseokit' ) ) );
			default:
				$verified = '' !== (string) Ui::val( 'general.verify_google', '' ) || '' !== (string) Ui::val( 'general.verify_bing', '' );
				return array( Plugin::output_enabled() ? $ok( __( 'Output on', 'wpseokit' ) ) : $warn( __( 'Output paused', 'wpseokit' ) ), $verified ? __( 'Site verification codes are set', 'wpseokit' ) : __( 'No verification codes yet', 'wpseokit' ), $link( 'settings', 'verification', __( 'Verification', 'wpseokit' ) ) );
		}
	}

	private static function tool_cards(): string {
		$out   = '<h2 class="wps-sect">' . esc_html__( 'SEO tools', 'wpseokit' ) . '</h2><ul class="wps-tools">';
		$yoast = Plugin::yoast_active();
		foreach ( Shell::tools() as $id => $t ) {
			[ $badge, $line, $more ] = self::card_state( $id );
			if ( $yoast && ! in_array( $id, array( 'redirects', 'log404', 'import', 'settings' ), true ) ) {
				$badge = self::badge( 'warn', 'pause', __( 'Paused (Yoast active)', 'wpseokit' ) );
			}
			/* translators: %s: tool name. */
			$open = sprintf( __( 'Open %s', 'wpseokit' ), $t['label'] );
			$out .= '<li><article class="rbn-card wps-tool"><div class="rbn-card__b rbn-card__b--pad"><div class="wps-tool__top"><span class="wps-tool__i">' . Ui::icon( $t['icon'] ) . '</span><h3 class="wps-tool__n"><a href="' . esc_url( Shell::url( $id ) ) . '">' . esc_html( $t['label'] ) . '</a></h3></div>'
				. '<p class="wps-tool__d">' . esc_html( $t['desc'] ) . '</p><p class="wps-tool__s">' . $badge . '</p><p class="wps-tool__m" data-card-line="' . esc_attr( $id ) . '">' . esc_html( $line ) . '</p></div>'
				. '<div class="rbn-card__foot"><a class="rbn-btn rbn-btn--pri" href="' . esc_url( Shell::url( $id ) ) . '" aria-label="' . esc_attr( $open ) . '">' . esc_html__( 'Open', 'wpseokit' ) . '</a>' . $more . '</div></article></li>';
		}
		return $out . '</ul>';
	}

	public static function render(): void {
		[ $done, $total ] = self::described();
		$stats            = array(
			array( 'sitemap', 'sitemap', __( 'URLs in the sitemap', 'wpseokit' ), '–', '', 'sitemap' ),
			array( 'redirects', 'arrows', __( 'Active redirects', 'wpseokit' ), '–', '', 'redirects' ),
			array( 'log', 'alert', __( '404 errors logged', 'wpseokit' ), '–', '', 'log404' ),
			array( 'described', 'doc', __( 'Posts with a description', 'wpseokit' ), (string) $done, '<small>/ ' . (int) $total . '</small>', 'templates' ),
		);
		$out              = Ui::open( 'dashboard' ) . '<header class="rbn-head"><div class="rbn-head__id"><h1>' . esc_html__( 'WPSeoKit', 'wpseokit' ) . '</h1><span class="rbn-chip">' . esc_html( WPSEOKIT_VERSION ) . '</span>' . Shell::chip() . '</div><div class="rbn-head__act"></div></header>';
		$out             .= Shell::yoast_note() . '<ul class="rbn-stats">';
		foreach ( $stats as $s ) {
			$out .= '<li class="rbn-stat"><p class="rbn-stat__k">' . Ui::icon( $s[1] ) . '<a href="' . esc_url( Shell::url( $s[5] ) ) . '">' . esc_html( $s[2] ) . '</a></p><p class="rbn-stat__v" data-stat="' . esc_attr( $s[0] ) . '">' . esc_html( $s[3] ) . $s[4] . '</p><p class="rbn-stat__s" data-stat-sub="' . esc_attr( $s[0] ) . '"></p></li>';
		}
		$out .= '</ul>' . self::tool_cards();

		$rows = '';
		foreach ( self::checks() as $c ) {
			$rows .= '<tr><th scope="row" data-l="' . esc_attr__( 'Check', 'wpseokit' ) . '">' . esc_html( $c[0] ) . '</th><td data-l="' . esc_attr__( 'Status', 'wpseokit' ) . '">' . $c[1] . '</td><td data-l="' . esc_attr__( 'Action', 'wpseokit' ) . '" class="rbn-acts"><a class="rbn-btn rbn-btn--sm' . ( $c[4] ? ' rbn-btn--pri' : '' ) . '" href="' . esc_url( $c[3] ) . '">' . esc_html( $c[2] ) . '</a></td></tr>';
		}
		$check = '<div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Setup checklist', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Check', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wpseokit' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Action', 'wpseokit' ) . '</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
		$act   = '<div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack" data-activity><caption class="rbn-sr">' . esc_html__( 'Recent activity', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Event', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Hits', 'wpseokit' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Action', 'wpseokit' ) . '</span></th></tr></thead><tbody><tr><td colspan="3" data-empty>' . esc_html__( 'Loading…', 'wpseokit' ) . '</td></tr></tbody></table></div></div>';

		$out .= '<div class="rbn-grid rbn-grid--2"><section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Setup checklist', 'wpseokit' ) . '</h2><p>' . esc_html__( 'These checks decide whether your site is ready for search engines.', 'wpseokit' ) . '</p></div>' . $check . '</section>';
		$out .= '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Recent activity', 'wpseokit' ) . '</h2><p>' . esc_html__( 'Broken addresses visitors hit most, and the latest redirects.', 'wpseokit' ) . '</p></div>' . $act . '</section></div>';
		echo $out . Ui::close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped in Ui.
	}
}
