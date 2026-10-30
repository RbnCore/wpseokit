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

	/** The output state of the page head status chip: label and tone. */
	private static function status(): array {
		return Plugin::yoast_active()
			? array( 'label' => __( 'Output paused', 'wpseokit' ), 'tone' => 'warn' )
			: array( 'label' => __( 'Output on', 'wpseokit' ), 'tone' => 'ok' );
	}

	/**
	 * The one page head of every WPSeoKit screen: the shared AdminKit head with this plugin's data. Nothing else prints a head.
	 *
	 * @param string                                            $page_title Page title (`h1`); empty for no title.
	 * @param array{current?:string,back?:bool,menu?:bool,title_hidden?:bool} $o
	 *        current: tool id marked in the Tools menu; back: "Dashboard" button; menu: Tools menu; title_hidden: keep the `h1` for screen readers only.
	 */
	public static function header( string $page_title = '', array $o = array() ): string {
		$items = array();
		foreach ( self::tools() as $id => $t ) {
			$items[] = array(
				'url'     => self::url( $id ),
				'label'   => $t['label'],
				'icon'    => $t['icon'],
				'current' => ( $o['current'] ?? '' ) === $id,
			);
		}
		return \AdminKit\Helpers\Header::render(
			array(
				'name'         => __( 'WPSeoKit', 'wpseokit' ),
				'version'      => WPSEOKIT_VERSION,
				'status'       => self::status(),
				'back'         => ! empty( $o['back'] ) ? array( 'label' => __( 'Dashboard', 'wpseokit' ), 'url' => self::url() ) : array(),
				'menu'         => ! empty( $o['menu'] ) ? array( 'label' => __( 'Tools', 'wpseokit' ), 'items' => $items ) : array(),
				'nav_label'    => __( 'Tool navigation', 'wpseokit' ),
				'title'        => $page_title,
				'title_hidden' => ! empty( $o['title_hidden'] ),
			)
		);
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
