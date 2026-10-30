<?php
/**
 * Yoast import tab of the dashboard: summary → dry run → run (with a confirmation dialog) → report.
 * The report is drawn by the screen script from `/import/yoast`.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class ImportScreen {

	/**
	 * Sub-tabs of the dashboard "Yoast import" tab: sub id => [label, panel html].
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function panels(): array {
		return array(
			'summary' => array( __( 'Summary', 'wpseokit' ), self::summary() ),
			'dry'     => array( __( 'Dry run', 'wpseokit' ), '<h2 class="rbn-sr">' . esc_html__( 'Dry run', 'wpseokit' ) . '</h2>' . self::dry() ),
			'run'     => array( __( 'Run', 'wpseokit' ), '<h2 class="rbn-sr">' . esc_html__( 'Run', 'wpseokit' ) . '</h2>' . self::run() ),
			'report'  => array( __( 'Report', 'wpseokit' ), '<h2 class="rbn-sr">' . esc_html__( 'Report', 'wpseokit' ) . '</h2><div data-import-done><p class="rbn-help">' . esc_html__( 'No import has run yet. The report appears here when it finishes.', 'wpseokit' ) . '</p></div>' ),
		);
	}

	private static function summary(): string {
		$start  = '<div class="rbn-empty"><span class="rbn-empty__i">' . Ui::icon( 'down' ) . '</span><h2>' . esc_html__( 'Bring your Yoast SEO data over', 'wpseokit' ) . '</h2>';
		$start .= '<p>' . esc_html__( 'First WPSeoKit only counts what it can import. Nothing is written until you confirm, and your Yoast data is never deleted.', 'wpseokit' ) . '</p>';
		$start .= '<button type="button" class="rbn-btn rbn-btn--pri" data-import-check>' . Ui::icon( 'scan' ) . esc_html__( 'Check my Yoast data', 'wpseokit' ) . '</button></div>';
		return '<div data-import-state></div><section class="rbn-card" data-import-start><div class="rbn-card__b">' . $start . '</div></section>';
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
			'<fieldset class="wps-radios" aria-labelledby="imp-ov-l"><label class="rbn-check"><input type="radio" name="imp-overwrite" value="0" checked><span>' . esc_html__( 'Keep WPSeoKit', 'wpseokit' ) . '</span></label><label class="rbn-check"><input type="radio" name="imp-overwrite" value="1"><span>' . esc_html__( 'Use Yoast', 'wpseokit' ) . '</span></label></fieldset>',
			esc_html__( 'Keeping WPSeoKit never overwrites something you edited by hand.', 'wpseokit' )
		);
		$decide .= '</div></section>';
		$bar     = '<div class="wps-savebar" data-import-bar><p>' . esc_html__( 'Nothing has been imported yet.', 'wpseokit' ) . '</p><div class="wps-savebar__act"><button type="button" class="rbn-btn" data-import-check>' . esc_html__( 'Run the check again', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-import-confirm>' . Ui::icon( 'down' ) . esc_html__( 'Import now', 'wpseokit' ) . '</button></div></div>';
		$wait = '<div data-import-wait>' . Ui::note( 'info', '<b>' . esc_html__( 'Nothing to import yet.', 'wpseokit' ) . '</b> ' . esc_html__( 'Check your Yoast data first. The import options appear here after the check.', 'wpseokit' ) . ' <button type="button" class="rbn-btn rbn-btn--sm" data-import-check>' . esc_html__( 'Check my Yoast data', 'wpseokit' ) . '</button>', 'status' ) . '</div>';
		return $wait . $decide . $bar;
	}

	/** The confirmation dialog was migrated to dynamic RbnUi.confirm modal (WPRJ-667). */
	public static function dialog(): string {
		return '';
	}
}
