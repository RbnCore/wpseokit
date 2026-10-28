<?php
/**
 * Page shell of the plugin screen: tool registry, "← Dashboard" bar with the Tools menu, page head and inner tabs.
 *
 * The dashboard is the front page (`?page=wpseokit`); every tool has its own address (`&tool=<id>`) and its own
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

	/** Address of a tool (or the dashboard) and, optionally, one of its tabs. */
	public static function url( string $tool = '', string $tab = '' ): string {
		$args = array();
		if ( '' !== $tool && 'dashboard' !== $tool ) {
			$args['tool'] = $tool;
		}
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return Admin::page_url( $args );
	}

	/** Page callback body: dashboard or the requested tool. */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading page and tab names only; they are matched against the registry.
		$tool = isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable
		if ( '' === $tool && 'status' === $tab ) { // Old address used by the update card redirect.
			$tool = 'settings';
			$tab  = 'advanced';
		}
		echo '<div class="wrap rbn-wrap wpseokit-wrap">';
		if ( isset( self::$tools[ $tool ] ) ) {
			call_user_func( self::$tools[ $tool ]['render'] );
		} else {
			DashboardScreen::render();
		}
		echo '</div>';
	}

	/** The output chip of the page head. */
	public static function chip(): string {
		$paused = Plugin::yoast_active();
		return $paused
			? '<span class="rbn-chip rbn-chip--off"><span class="rbn-dot"></span>' . esc_html__( 'Output paused', 'wpseokit' ) . '</span>'
			: '<span class="rbn-chip rbn-chip--ok"><span class="rbn-dot"></span>' . esc_html__( 'Output on', 'wpseokit' ) . '</span>';
	}

	/** "← Dashboard" button and the Tools menu. */
	public static function crumb( string $current ): string {
		$items = '';
		foreach ( self::tools() as $id => $t ) {
			$items .= '<li><a href="' . esc_url( self::url( $id ) ) . '"' . ( $id === $current ? ' aria-current="page"' : '' ) . '>' . Ui::icon( $t['icon'] ) . esc_html( $t['label'] ) . '</a></li>';
		}
		return '<nav class="wps-crumb" aria-label="' . esc_attr__( 'Tool navigation', 'wpseokit' ) . '"><a class="rbn-btn" href="' . esc_url( self::url() ) . '">' . Ui::icon( 'chevl' ) . esc_html__( 'Dashboard', 'wpseokit' ) . '</a>'
			. '<div class="wps-menu"><button type="button" class="rbn-btn" aria-expanded="false" aria-controls="tools-menu" data-menu>' . Ui::icon( 'grid' ) . esc_html__( 'Tools', 'wpseokit' ) . Ui::icon( 'chevd' ) . '</button>'
			. '<ul class="wps-menu__list" id="tools-menu" hidden>' . $items . '</ul></div></nav>';
	}

	/** Page head with the title and the output chip. */
	public static function head( string $title, string $extra = '' ): string {
		return '<div class="rbn-head"><div class="rbn-head__id"><h1>' . esc_html( $title ) . '</h1>' . self::chip() . '</div><div class="rbn-head__act">' . $extra . '</div></div>';
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
		if ( 'settings' === $tool && 'status' === $wanted ) {
			$wanted = 'advanced';
		}
		$active = isset( $tabs[ $wanted ] ) ? $wanted : (string) array_key_first( $tabs );
		$list   = '<div class="wps-tabs" role="tablist" aria-label="' . esc_attr( $o['aria'] ?? $title ) . '" data-tabs>';
		$panels = '';
		foreach ( $tabs as $id => $tab ) {
			$on      = $id === $active;
			$list   .= '<button type="button" role="tab" id="tab-' . esc_attr( (string) $id ) . '" aria-controls="pan-' . esc_attr( (string) $id ) . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '" tabindex="' . ( $on ? '0' : '-1' ) . '" data-tab="' . esc_attr( (string) $id ) . '">' . esc_html( $tab[0] ) . '</button>';
			$panels .= '<div role="tabpanel" id="pan-' . esc_attr( (string) $id ) . '" aria-labelledby="tab-' . esc_attr( (string) $id ) . '"' . ( $on ? '' : ' hidden' ) . '>' . $tab[1] . '</div>';
		}
		$list .= '</div>';
		$body  = isset( $o['form'] ) ? Ui::form( $o['form'], $panels ) : $panels;
		$out   = Ui::open( $tool ) . self::crumb( $tool ) . self::head( $title ) . self::yoast_note() . ( $o['top'] ?? '' ) . $list . $body . Ui::close();
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped where it is built.
	}
}
