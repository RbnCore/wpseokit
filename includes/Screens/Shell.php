<?php
/**
 * Page shell of the plugin screen: tool registry, "← Dashboard" bar with the Tools menu, page head and inner tabs.
 *
 * The dashboard is the front page (`?page=wpseokit`, six tabs `&tab=` with sub-tabs `&sub=`); every tool has its own address (`&tool=<id>`) and its own
 * tabs (`&tab=<id>`). Tools register themselves with `Shell::register()`; `Admin::render()` hands the page over
 * through the `wpseokit_render_page` action.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use Wpseokit\Admin;
use Wpseokit\Plugin;

defined( 'ABSPATH' ) || exit;

final class Shell {

	/** @var array<string, array{label:string,icon:string,desc:string,priority:int,render:callable}> */
	private static array $tools = array();

	private static bool $hooked = false;

	/**
	 * Registers a tool.
	 *
	 * @param string   $id       Address id (`tool=<id>`); also the `data-screen` name the script switches on.
	 * @param callable $label    Returns the translated name.
	 * @param string   $icon     Sprite icon name.
	 * @param callable $desc     Returns the one-sentence description shown on the dashboard card.
	 * @param int      $priority Card and menu order.
	 * @param callable $render   Prints the tool page body (no wrapper).
	 */
	public static function register( string $id, callable $label, string $icon, callable $desc, int $priority, callable $render ): void {
		self::hook();
		self::$tools[ $id ] = array(
			'label'    => (string) $label(),
			'icon'     => $icon,
			'desc'     => (string) $desc(),
			'priority' => $priority,
			'render'   => $render,
		);
	}

	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		Ui::boot();
		add_action( 'wpseokit_render_page', array( self::class, 'render' ) );
	}

	/** @return array<string, array{label:string,icon:string,desc:string,priority:int,render:callable}> */
	public static function tools(): array {
		$tools = self::$tools;
		uasort( $tools, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
		return $tools;
	}

	/** Address of a tool (or the dashboard) and, optionally, one of its tabs. Yoast import and Settings live in the dashboard tabs. */
	public static function url( string $tool = '', string $tab = '' ): string {
		if ( 'import' === $tool ) {
			return self::dash_url( 'yoast', $tab );
		}
		if ( 'settings' === $tool ) {
			return self::dash_url( 'settings', $tab );
		}
		$args = array();
		if ( '' !== $tool && 'dashboard' !== $tool ) {
			$args['tool'] = $tool;
		}
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return Admin::page_url( $args );
	}

	/** Address of a dashboard tab (`tab`) and its sub-tab (`sub`); the Overview needs neither. */
	public static function dash_url( string $tab = '', string $sub = '' ): string {
		$args = array();
		if ( '' !== $tab && 'overview' !== $tab ) {
			$args['tab'] = $tab;
			if ( '' !== $sub ) {
				$args['sub'] = $sub;
			}
		}
		return Admin::page_url( $args );
	}

	/** Page callback body: dashboard or the requested tool. */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading page and tab names only; they are matched against the registry.
		$tool = isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$sub  = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : '';
		// phpcs:enable
		if ( '' === $tool && 'status' === $tab ) { // Old address used by the update card redirect.
			$tab = 'settings';
			$sub = 'advanced';
		}
		if ( 'import' === $tool || 'settings' === $tool ) { // Old tool addresses now open the dashboard tab.
			$sub  = $tab;
			$tab  = 'import' === $tool ? 'yoast' : 'settings';
			$tool = '';
		}
		echo '<div class="wrap rbn-wrap wpseokit-wrap"' . Ui::tone_attr() . '>';
		if ( isset( self::$tools[ $tool ] ) ) {
			call_user_func( self::$tools[ $tool ]['render'] );
		} else {
			DashboardScreen::render( $tab, $sub );
		}
		echo '</div>';
	}

	/** The output chip of the page head. */
	public static function chip(): string {
		$paused = Plugin::yoast_active();
		return $paused
			? '<span class="rbn-chip rbn-chip--warn"><span class="rbn-dot"></span>' . esc_html__( 'Output paused', 'wpseokit' ) . '</span>'
			: '<span class="rbn-chip rbn-chip--ok"><span class="rbn-dot"></span>' . esc_html__( 'Output on', 'wpseokit' ) . '</span>';
	}

	/**
	 * The one page head of every WPSeoKit screen: fixed brand row (name, version chip, output chip), the page buttons on its right and,
	 * when `$page_title` is given, the page's single `h1` under it. Nothing else prints a head.
	 *
	 * Plugin data comes from outside the markup builder (`compose()`), so the same markup moves to a shared kit component as is.
	 *
	 * @param string                                                   $page_title Page title (`h1`); empty for no title.
	 * @param array{current?:string,back?:bool,menu?:bool,actions?:string,badge?:string,title_hidden?:bool} $o
	 *        current: tool id marked in the Tools menu; back: "Dashboard" button; menu: Tools menu; actions: extra button html;
	 *        badge: extra chip html after the output chip; title_hidden: keep the `h1` for screen readers only.
	 */
	public static function header( string $page_title = '', array $o = array() ): string {
		$menu = array();
		foreach ( self::tools() as $id => $t ) {
			$menu[] = array(
				'href'    => self::url( $id ),
				'label'   => $t['label'],
				'icon'    => Ui::icon( $t['icon'] ),
				'current' => ( $o['current'] ?? '' ) === $id,
			);
		}
		return self::compose(
			array(
				'name'    => __( 'WPSeoKit', 'wpseokit' ),
				'version' => WPSEOKIT_VERSION,
				'status'  => self::chip(),
			),
			$page_title,
			array(
				'back_url'   => ! empty( $o['back'] ) ? self::url() : '',
				'back_icon'  => Ui::icon( 'chevl' ),
				'back_label' => __( 'Dashboard', 'wpseokit' ),
				'menu'       => ! empty( $o['menu'] ) ? $menu : array(),
				'menu_icons' => array( Ui::icon( 'grid' ), Ui::icon( 'chevd' ) ),
				'menu_label' => __( 'Tools', 'wpseokit' ),
				'nav_label'  => __( 'Tool navigation', 'wpseokit' ),
				'actions'    => (string) ( $o['actions'] ?? '' ),
				'badge'      => (string) ( $o['badge'] ?? '' ),
				'title_hidden' => ! empty( $o['title_hidden'] ),
			)
		);
	}

	/**
	 * Pure markup of the page head: no plugin state read here, every plugin value arrives in the arguments (kit-ready).
	 *
	 * @param array{name:string,version:string,status:string}  $brand Plugin name, version, ready status chip html.
	 * @param array<string, mixed>                              $p     Parts: back_url/back_icon/back_label, menu (rows of href,label,icon,current), menu_icons, menu_label, nav_label, actions, badge, title_hidden.
	 */
	public static function compose( array $brand, string $page_title, array $p ): string {
		$buttons = '';
		if ( ! empty( $p['back_url'] ) ) {
			$buttons .= '<a class="rbn-btn" href="' . esc_url( $p['back_url'] ) . '">' . $p['back_icon'] . esc_html( $p['back_label'] ) . '</a>';
		}
		if ( ! empty( $p['menu'] ) ) {
			$items = '';
			foreach ( $p['menu'] as $row ) {
				$items .= '<li><a href="' . esc_url( $row['href'] ) . '"' . ( $row['current'] ? ' aria-current="page"' : '' ) . '>' . $row['icon'] . esc_html( $row['label'] ) . '</a></li>';
			}
			$buttons .= '<div class="wps-menu"><button type="button" class="rbn-btn" aria-expanded="false" aria-controls="tools-menu" data-menu>' . $p['menu_icons'][0] . esc_html( $p['menu_label'] ) . $p['menu_icons'][1] . '</button>'
				. '<ul class="wps-menu__list" id="tools-menu" hidden>' . $items . '</ul></div>';
		}
		$nav = '' !== $buttons ? '<nav class="wps-crumb" aria-label="' . esc_attr( $p['nav_label'] ) . '">' . $buttons . '</nav>' : '';
		$act = $nav . ( $p['actions'] ?? '' );

		$out = '<div class="rbn-head wps-brand"><div class="rbn-head__id"><p class="rbn-brand">' . esc_html( $brand['name'] ) . '</p><span class="rbn-chip">' . esc_html( $brand['version'] ) . '</span>' . $brand['status'] . ( $p['badge'] ?? '' ) . '</div>'
			. ( '' !== $act ? '<div class="rbn-head__act">' . $act . '</div>' : '' ) . '</div>';
		if ( '' !== $page_title && ! empty( $p['title_hidden'] ) ) {
			$out .= '<h1 class="rbn-sr">' . esc_html( $page_title ) . '</h1>';
		} elseif ( '' !== $page_title ) {
			$out .= '<div class="rbn-head"><div class="rbn-head__id"><h1>' . esc_html( $page_title ) . '</h1></div></div>';
		}
		return $out;
	}

	/** Yoast warning shown above a tool. */
	public static function yoast_note(): string {
		if ( ! Plugin::yoast_active() ) {
			return '';
		}
		return '<div class="rbn-note rbn-note--warn" role="status">' . Ui::icon( 'alert' ) . '<div><p><b>' . esc_html__( 'Yoast SEO is active.', 'wpseokit' ) . '</b> ' . esc_html__( 'WPSeoKit prints nothing on your site while Yoast is on, so search engines never see duplicate tags.', 'wpseokit' ) . '</p>'
			. '<div class="rbn-note__act"><a class="rbn-btn rbn-btn--pri" href="' . esc_url( self::url( 'import' ) ) . '">' . Ui::icon( 'down' ) . esc_html__( 'Import Yoast data', 'wpseokit' ) . '</a></div></div></div>';
	}

	/**
	 * A full tool page: bar, head, tabs and panels. With `form` the panels sit in one settings form with one save bar.
	 *
	 * @param string                                        $tool  Tool id.
	 * @param string                                        $title Page title.
	 * @param array<string, array{0:string,1:string}>       $tabs  tab id => [label, panel html].
	 * @param array{form?:string,aria?:string,top?:string}  $o     form: settings form name; aria: tablist label; top: html above the tabs.
	 */
	public static function page( string $tool, string $title, array $tabs, array $o = array() ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a tab name only.
		$wanted = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$active = isset( $tabs[ $wanted ] ) ? $wanted : (string) array_key_first( $tabs );
		$list   = '<div class="rbn-tabs" role="tablist" aria-label="' . esc_attr( $o['aria'] ?? $title ) . '" data-rbn-tabs>';
		$panels = '';
		foreach ( $tabs as $id => $tab ) {
			$on      = $id === $active;
			$list   .= '<button type="button" class="rbn-tabs__tab" role="tab" id="tab-' . esc_attr( (string) $id ) . '" aria-controls="pan-' . esc_attr( (string) $id ) . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '" tabindex="' . ( $on ? '0' : '-1' ) . '" data-key="' . esc_attr( (string) $id ) . '">' . esc_html( $tab[0] ) . '</button>';
			$panels .= '<div class="rbn-tabpanel" role="tabpanel" id="pan-' . esc_attr( (string) $id ) . '" aria-labelledby="tab-' . esc_attr( (string) $id ) . '"' . ( $on ? '' : ' hidden' ) . '>' . $tab[1] . '</div>';
		}
		$list .= '</div>';
		$body  = isset( $o['form'] ) ? Ui::form( $o['form'], $panels ) : $panels;
		$out   = Ui::open( $tool ) . self::header( $title, array( 'current' => $tool, 'back' => true, 'menu' => true ) ) . self::yoast_note() . ( $o['top'] ?? '' ) . $list . $body . Ui::close();
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped where it is built.
	}
}
