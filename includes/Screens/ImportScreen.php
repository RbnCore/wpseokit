<?php
/**
 * Yoast import tool: summary → dry run → run (with a confirmation dialog) → report.
 * The report is drawn by the screen script from `/import/yoast`.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class ImportScreen {

	public static function init(): void {
		Shell::register(
			'import',
			static fn(): string => __( 'Yoast import', 'wpseokit' ),
			'down',
			static fn(): string => __( 'Bring your titles and descriptions over from Yoast SEO.', 'wpseokit' ),
			80,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		Shell::page(
			'import',
			__( 'Yoast import', 'wpseokit' ),
			array(
				'summary' => array( __( 'Summary', 'wpseokit' ), self::summary() ),
				'dry'     => array( __( 'Dry run', 'wpseokit' ), self::dry() ),
				'run'     => array( __( 'Run', 'wpseokit' ), self::run() ),
				'report'  => array( __( 'Report', 'wpseokit' ), '<div data-import-done><p class="rbn-help">' . esc_html__( 'No import has run yet. The report appears here when it finishes.', 'wpseokit' ) . '</p></div>' ),
			),
			array( 'aria' => __( 'Yoast import steps', 'wpseokit' ) )
		);
		echo self::dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped in Ui.
	}

	private static function summary(): string {
		$start  = '<div class="rbn-empty"><span class="rbn-empty__i">' . Ui::icon( 'down' ) . '</span><h2>' . esc_html__( 'Bring your Yoast SEO data over', 'wpseokit' ) . '</h2>';
		$start .= '<p>' . esc_html__( 'First WPSeoKit only counts what it can import. Nothing is written until you confirm, and your Yoast data is never deleted.', 'wpseokit' ) . '</p>';
		$start .= '<button type="button" class="rbn-btn rbn-btn--pri" data-import-check>' . Ui::icon( 'scan' ) . esc_html__( 'Check my Yoast data', 'wpseokit' ) . '</button></div>';
		return '<section class="rbn-card" data-import-start><div class="rbn-card__b">' . $start . '</div></section>';
	}

	private static function dry(): string {
		$out  = '<div data-import-busy hidden>' . Ui::skeleton( 4 ) . '</div><div data-import-error hidden></div>';
		$out .= '<section class="rbn-card" data-import-empty><div class="rbn-card__b"><p class="rbn-help">' . esc_html__( 'Run the check on the Summary tab first. The result appears here.', 'wpseokit' ) . '</p></div></section>';
		$table = '<div class="rbn-tw"><table class="rbn-table rbn-table--stack" data-import-table><caption class="rbn-sr">' . esc_html__( 'What the check found', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Data', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Found', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Will import', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Note', 'wpseokit' ) . '</th></tr></thead><tbody></tbody></table></div>';
		$out  .= '<section class="rbn-card" data-import-report hidden><div class="rbn-card__h"><h2>' . esc_html__( 'Dry-run report', 'wpseokit' ) . '</h2><p data-import-summary></p></div><div class="rbn-card__b rbn-card__b--flush">' . $table . '</div></section>';
		return $out;
	}

	private static function run(): string {
		$decide  = '<section class="rbn-card" data-import-decide><div class="rbn-card__h"><h2>' . esc_html__( 'Import options', 'wpseokit' ) . '</h2><p>' . esc_html__( 'Choose how to handle values that exist in both plugins.', 'wpseokit' ) . '</p></div><div class="rbn-card__b">';
		$decide .= Ui::row(
			'<span id="imp-ov-l">' . esc_html__( 'When both have a value', 'wpseokit' ) . '</span>',
			'<fieldset class="rbn-seg" aria-labelledby="imp-ov-l"><label><input type="radio" name="imp-overwrite" value="0" checked><span>' . esc_html__( 'Keep WPSeoKit', 'wpseokit' ) . '</span></label><label><input type="radio" name="imp-overwrite" value="1"><span>' . esc_html__( 'Use Yoast', 'wpseokit' ) . '</span></label></fieldset>',
			esc_html__( 'Keeping WPSeoKit never overwrites something you edited by hand.', 'wpseokit' )
		);
		$decide .= '</div></section>';
		$bar     = '<div class="rbn-bar" data-import-bar><p>' . esc_html__( 'Nothing has been imported yet.', 'wpseokit' ) . '</p><div class="rbn-bar__act"><button type="button" class="rbn-btn" data-import-check>' . esc_html__( 'Run the check again', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-import-confirm>' . Ui::icon( 'down' ) . esc_html__( 'Import now', 'wpseokit' ) . '</button></div></div>';
		$wait = '<div data-import-wait>' . Ui::note( 'info', '<b>' . esc_html__( 'Nothing to import yet.', 'wpseokit' ) . '</b> ' . esc_html__( 'Check your Yoast data first. The import options appear here after the check.', 'wpseokit' ) . ' <button type="button" class="rbn-btn rbn-btn--sm" data-import-check>' . esc_html__( 'Check my Yoast data', 'wpseokit' ) . '</button>', 'status' ) . '</div>';
		return $wait . $decide . $bar;
	}

	private static function dialog(): string {
		$x    = '<button type="button" class="rbn-dlg__x" data-dlg-close aria-label="' . esc_attr__( 'Close', 'wpseokit' ) . '">' . Ui::icon( 'x' ) . '</button>';
		$out  = '<dialog class="rbn-dlg" aria-labelledby="imp-t" data-dlg="import"' . Ui::tone_attr() . '><div class="rbn-dlg__h"><h2 id="imp-t">' . esc_html__( 'Import Yoast data?', 'wpseokit' ) . '</h2>' . $x . '</div><div class="rbn-dlg__b"><div class="rbn-stack"><p class="rbn-p">' . esc_html__( 'WPSeoKit will write titles, descriptions, primary categories and settings. Your Yoast data stays where it is.', 'wpseokit' ) . '</p>';
		$out .= '<label class="rbn-chk"><input type="checkbox" id="imp-ack" data-import-ack><span>' . esc_html__( 'I have a database backup and I want to import now.', 'wpseokit' ) . '</span></label></div></div>';
		$out .= '<div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Cancel', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-import-run disabled>' . Ui::icon( 'down' ) . esc_html__( 'Import', 'wpseokit' ) . '</button></div></dialog>';
		return $out;
	}
}
