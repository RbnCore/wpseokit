<?php
/**
 * 404 log tool: most hit first, one-click redirect row, bulk actions and log settings. The list is drawn by the screen script from `/404-logs`.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class Log404Screen {

	public static function init(): void {
		Shell::register(
			'log404',
			static fn(): string => __( '404 log', 'wpseokit' ),
			'alert',
			static fn(): string => __( 'See which broken addresses visitors hit.', 'wpseokit' ),
			70,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		Shell::page(
			'log404',
			__( '404 log', 'wpseokit' ),
			array(
				'log'      => array( __( 'Log', 'wpseokit' ), self::log() ),
				'settings' => array( __( 'Settings', 'wpseokit' ), self::settings() ),
			),
			array( 'aria' => __( '404 log sections', 'wpseokit' ) )
		);
		echo self::dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped in Ui.
	}

	private static function log(): string {
		$out = Ui::note( 'info', esc_html__( 'Addresses that bots probe (such as wp-login.php) are hidden by default. Show them with the switch below the search.', 'wpseokit' ) );

		$filter  = '<form class="rbn-filter wps-filter3" role="search" aria-label="' . esc_attr__( 'Filter 404 log', 'wpseokit' ) . '" data-log-filter>';
		$filter .= '<div><label for="lf-q">' . esc_html__( 'Search', 'wpseokit' ) . '</label><input class="rbn-input" id="lf-q" name="search" type="search" placeholder="' . esc_attr__( 'Path', 'wpseokit' ) . '"></div>';
		$filter .= '<div><label class="rbn-switch" style="margin-top:var(--rbn-s5)"><input type="checkbox" id="lf-bots" name="bots"><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Show bot noise', 'wpseokit' ) . '</b></span></label></div>';
		$filter .= '<div class="rbn-filter__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . esc_html__( 'Apply', 'wpseokit' ) . '</button></div></form>';

		$bulk  = '<div class="rbn-bulk" data-bulk hidden><p><b data-count>0</b> ' . esc_html__( 'selected', 'wpseokit' ) . '</p><div class="rbn-bulk__act">';
		$bulk .= '<button type="button" class="rbn-btn rbn-btn--sm" data-bulk-redirect>' . Ui::icon( 'arrows' ) . esc_html__( 'Redirect all to…', 'wpseokit' ) . '</button>';
		$bulk .= '<button type="button" class="rbn-btn rbn-btn--danger rbn-btn--sm" data-bulk-delete>' . Ui::icon( 'trash' ) . esc_html__( 'Delete from log', 'wpseokit' ) . '</button></div></div>';

		$table  = '<div class="rbn-tw"><table class="rbn-table rbn-table--stack" data-log><caption class="rbn-sr">' . esc_html__( '404 errors, most hits first', 'wpseokit' ) . '</caption><thead><tr>';
		$table .= '<th scope="col" class="rbn-chk-cell"><label class="rbn-chk"><input type="checkbox" data-select-all><span class="rbn-sr">' . esc_html__( 'Select all', 'wpseokit' ) . '</span></label></th>';
		$table .= '<th scope="col">' . esc_html__( 'Address', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Hits', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Referrer', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Last seen', 'wpseokit' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Actions', 'wpseokit' ) . '</span></th>';
		$table .= '</tr></thead><tbody></tbody></table></div><div class="rbn-pager" data-pager></div>';

		return $out . '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Most hit first', 'wpseokit' ) . '</h2></div><div class="rbn-card__b rbn-card__b--flush">' . $filter . $bulk . '<div data-list-state></div>' . $table . '</div></section>';
	}

	private static function settings(): string {
		$body  = Ui::toggle_row( __( 'Logging', 'wpseokit' ), 'redirects.log_404', 'ls-on', __( 'Record addresses that return 404', 'wpseokit' ), __( 'Only the address, the referrer and a hit count are stored. No visitor data.', 'wpseokit' ) );
		$body .= Ui::toggle_row( __( 'Bots', 'wpseokit' ), 'redirects.ignore_bots', 'ls-bots', __( 'Do not record search engine bots', 'wpseokit' ) );
		$body .= Ui::text( 'redirects.log_retention_days', 'ls-days', __( 'Keep entries for', 'wpseokit' ), array( 'type' => 'number', 'min' => 1, 'max' => 365, 'unit' => __( 'days', 'wpseokit' ) ) );
		$body .= Ui::text( 'redirects.log_max_rows', 'ls-rows', __( 'Most entries kept', 'wpseokit' ), array( 'type' => 'number', 'min' => 100, 'max' => 50000, 'step' => 100, 'unit' => __( 'entries', 'wpseokit' ), 'help' => __( 'The oldest entries go first when the log is full.', 'wpseokit' ) ) );
		return Ui::form( 'log404', Ui::card( __( 'Log settings', 'wpseokit' ), '', $body ) );
	}

	private static function dialog(): string {
		$x   = '<button type="button" class="rbn-dlg__x" data-dlg-close aria-label="' . esc_attr__( 'Close', 'wpseokit' ) . '">' . Ui::icon( 'x' ) . '</button>';
		$out  = '<dialog class="rbn-dlg" aria-labelledby="bk-t" data-dlg="bulk"' . Ui::tone_attr() . '><form method="dialog" data-bulk-form novalidate><div class="rbn-dlg__h"><h2 id="bk-t">' . esc_html__( 'Redirect the selected addresses', 'wpseokit' ) . '</h2>' . $x . '</div><div class="rbn-dlg__b"><div class="rbn-stack">';
		$out .= Ui::row( Ui::label( 'bk-to', __( 'To (new address)', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="bk-to" name="target_url" type="text" placeholder="/">', esc_html__( 'All selected addresses get a 301 redirect to this page.', 'wpseokit' ) );
		$out .= '<div class="rbn-note rbn-note--err" role="alert" data-bulk-error hidden></div></div></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Cancel', 'wpseokit' ) . '</button><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'arrows' ) . esc_html__( 'Create redirects', 'wpseokit' ) . '</button></div></form></dialog>';
		return $out;
	}
}
