<?php
/**
 * Redirects tool: list, add form, CSV import/export and the on switch.
 * The list is drawn by the screen script from `/redirects`.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class RedirectsScreen {

	public static function init(): void {
		Shell::register(
			'redirects',
			static fn(): string => __( 'Redirects', 'wpseokit' ),
			'arrows',
			static fn(): string => __( 'Send old addresses to the right place.', 'wpseokit' ),
			60,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		Shell::page(
			'redirects',
			__( 'Redirects', 'wpseokit' ),
			array(
				'list'     => array( __( 'List', 'wpseokit' ), self::list() ),
				'add'      => array( __( 'Add', 'wpseokit' ), self::add() ),
				'io'       => array( __( 'Import / export', 'wpseokit' ), self::io() ),
				'settings' => array( __( 'Settings', 'wpseokit' ), self::settings() ),
			),
			array( 'aria' => __( 'Redirects sections', 'wpseokit' ) )
		);
		echo self::dialogs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped in Ui.
	}

	private static function list(): string {
		$filter  = '<form class="rbn-filter wps-filter3" role="search" aria-label="' . esc_attr__( 'Filter redirects', 'wpseokit' ) . '" data-redirect-filter>';
		$filter .= '<div><label for="rf-q">' . esc_html__( 'Search', 'wpseokit' ) . '</label><input class="rbn-input" id="rf-q" name="search" type="search" placeholder="' . esc_attr__( 'Path or target', 'wpseokit' ) . '"></div>';
		$filter .= '<div><label for="rf-t">' . esc_html__( 'Type', 'wpseokit' ) . '</label><select class="rbn-select" id="rf-t" name="status_code"><option value="">' . esc_html__( 'All types', 'wpseokit' ) . '</option><option>301</option><option>302</option><option>307</option><option>410</option></select></div>';
		$filter .= '<div><label for="rf-s">' . esc_html__( 'Status', 'wpseokit' ) . '</label><select class="rbn-select" id="rf-s" name="is_active"><option value="">' . esc_html__( 'All', 'wpseokit' ) . '</option><option value="1">' . esc_html__( 'On', 'wpseokit' ) . '</option><option value="0">' . esc_html__( 'Off', 'wpseokit' ) . '</option></select></div>';
		$filter .= '<div class="rbn-filter__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . esc_html__( 'Apply', 'wpseokit' ) . '</button></div></form>';

		$bulk  = '<div class="rbn-bulk" data-bulk hidden><p><b data-count>0</b> ' . esc_html__( 'selected', 'wpseokit' ) . '</p><div class="rbn-bulk__act">';
		$bulk .= '<button type="button" class="rbn-btn rbn-btn--sm" data-bulk-on>' . esc_html__( 'Turn on', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--sm" data-bulk-off>' . esc_html__( 'Turn off', 'wpseokit' ) . '</button>';
		$bulk .= '<button type="button" class="rbn-btn rbn-btn--danger rbn-btn--sm" data-bulk-delete>' . Ui::icon( 'trash' ) . esc_html__( 'Delete', 'wpseokit' ) . '</button></div></div>';

		$table  = '<div class="rbn-tw"><table class="rbn-table rbn-table--stack" data-redirects><caption class="rbn-sr">' . esc_html__( 'Redirects', 'wpseokit' ) . '</caption><thead><tr>';
		$table .= '<th scope="col" class="rbn-chk-cell"><label class="rbn-chk"><input type="checkbox" data-select-all><span class="rbn-sr">' . esc_html__( 'Select all redirects', 'wpseokit' ) . '</span></label></th>';
		$table .= '<th scope="col">' . esc_html__( 'From', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'To', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Type', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Hits', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Last hit', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wpseokit' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Actions', 'wpseokit' ) . '</span></th>';
		$table .= '</tr></thead><tbody></tbody></table></div><div class="rbn-pager" data-pager></div>';

		return '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'All redirects', 'wpseokit' ) . '</h2></div><div class="rbn-card__b rbn-card__b--flush">' . $filter . $bulk . '<div data-list-state></div>' . $table . '</div></section>';
	}

	private static function add(): string {
		$types = '';
		foreach ( array( '301', '302', '307', '410' ) as $i => $t ) {
			$types .= '<label><input type="radio" name="ra-type" value="' . $t . '"' . ( 0 === $i ? ' checked' : '' ) . '><span>' . $t . '</span></label>';
		}
		$add  = '<form class="rbn-stack" data-redirect-add novalidate>';
		$add .= Ui::row( Ui::label( 'ra-from', __( 'From (old path)', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ra-from" name="source_path" type="text" placeholder="/old-page/" aria-describedby="ra-from-h" autocomplete="off">', wp_kses( __( 'Start with a slash. Use <code>*</code> at the end to match everything below that path.', 'wpseokit' ), array( 'code' => array() ) ), 'ra-from-h' );
		$add .= Ui::row( Ui::label( 'ra-to', __( 'To (new address)', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ra-to" name="target_url" type="text" placeholder="/new-page/" aria-describedby="ra-to-h" autocomplete="off">', esc_html__( 'A path on this site or a full address. Leave empty for type 410.', 'wpseokit' ), 'ra-to-h' );
		$add .= Ui::row( '<span id="ra-type-l">' . esc_html__( 'Type', 'wpseokit' ) . '</span>', '<fieldset class="rbn-seg" aria-labelledby="ra-type-l">' . $types . '</fieldset>', esc_html__( '301 moves a page for good and passes its ranking. Use 302 or 307 only for short-term moves.', 'wpseokit' ) );
		$add .= '<div class="rbn-note rbn-note--err" role="alert" data-add-error hidden></div>';
		$add .= '<div class="rbn-row"><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'plus' ) . esc_html__( 'Add redirect', 'wpseokit' ) . '</button></div></form>';
		return Ui::card( __( 'Add a redirect', 'wpseokit' ), __( 'Takes effect the moment you save.', 'wpseokit' ), $add );
	}

	private static function io(): string {
		$in  = '<p class="rbn-p">' . wp_kses( __( 'One redirect per line: <code>from,to,type</code>. The check runs before anything is saved, and existing “from” addresses are never overwritten.', 'wpseokit' ), array( 'code' => array() ) ) . '</p>';
		$in .= '<div class="rbn-row"><button type="button" class="rbn-btn rbn-btn--pri" data-csv-open>' . Ui::icon( 'upload' ) . esc_html__( 'Choose CSV file', 'wpseokit' ) . '</button></div>';
		$out = '<p class="rbn-p">' . esc_html__( 'Every redirect, UTF-8, one per line.', 'wpseokit' ) . '</p><div class="rbn-row"><button type="button" class="rbn-btn" data-csv-export>' . Ui::icon( 'down' ) . esc_html__( 'Export CSV', 'wpseokit' ) . '</button></div>';
		return Ui::card( __( 'Import', 'wpseokit' ), __( 'Add many redirects at once from a CSV file.', 'wpseokit' ), $in )
			. Ui::card( __( 'Export', 'wpseokit' ), __( 'Download every redirect as a CSV file, for a backup or another site.', 'wpseokit' ), $out );
	}

	private static function settings(): string {
		$body = Ui::toggle_row( __( 'Redirects', 'wpseokit' ), 'redirects.enabled', 'rs-on', __( 'Run the redirects on the live site', 'wpseokit' ), __( 'Off = every redirect pauses and visitors see the old address. Nothing is deleted.', 'wpseokit' ) );
		return Ui::form( 'redirects', Ui::card( __( 'Redirect behavior', 'wpseokit' ), '', $body ) );
	}

	private static function dialogs(): string {
		$x   = '<button type="button" class="rbn-dlg__x" data-dlg-close aria-label="' . esc_attr__( 'Close', 'wpseokit' ) . '">' . Ui::icon( 'x' ) . '</button>';
		$csv = '<dialog class="rbn-dlg rbn-dlg--mid" aria-labelledby="csv-t" data-dlg="csv"' . Ui::tone_attr() . '><div class="rbn-dlg__h"><h2 id="csv-t">' . esc_html__( 'Import redirects from CSV', 'wpseokit' ) . '</h2>' . $x . '</div><div class="rbn-dlg__b"><div class="rbn-stack">';
		$csv .= '<p class="rbn-p">' . wp_kses( __( 'One redirect per line: <code>from,to,type</code>. The first line may be a header. Existing redirects with the same “from” are skipped, never overwritten.', 'wpseokit' ), array( 'code' => array() ) ) . '</p>';
		$csv .= Ui::row( Ui::label( 'csv-f', __( 'CSV file', 'wpseokit' ) ), '<input class="rbn-input" type="file" id="csv-f" accept=".csv,text/csv" data-csv-file>', esc_html__( 'Up to 1 MB, UTF-8.', 'wpseokit' ) );
		$csv .= '<div data-csv-report aria-live="polite"></div></div></div>';
		$csv .= '<div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Cancel', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-csv-run disabled>' . Ui::icon( 'upload' ) . esc_html__( 'Import', 'wpseokit' ) . '</button></div></dialog>';

		$del  = '<dialog class="rbn-dlg" aria-labelledby="del-t" data-dlg="delete"' . Ui::tone_attr() . '><div class="rbn-dlg__h"><h2 id="del-t" data-del-title></h2>' . $x . '</div><div class="rbn-dlg__b"><p class="rbn-p" data-del-body></p></div>';
		$del .= '<div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Keep them', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--danger" data-del-run>' . Ui::icon( 'trash' ) . '<span></span></button></div></dialog>';

		$edit  = '<dialog class="rbn-dlg" aria-labelledby="ed-t" data-dlg="edit"' . Ui::tone_attr() . '><form method="dialog" data-edit-form novalidate><div class="rbn-dlg__h"><h2 id="ed-t">' . esc_html__( 'Edit redirect', 'wpseokit' ) . '</h2>' . $x . '</div><div class="rbn-dlg__b"><div class="rbn-stack">';
		$edit .= Ui::row( Ui::label( 'ed-from', __( 'From', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ed-from" name="source_path" type="text">' );
		$edit .= Ui::row( Ui::label( 'ed-to', __( 'To', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ed-to" name="target_url" type="text">' );
		$edit .= Ui::row( Ui::label( 'ed-type', __( 'Type', 'wpseokit' ) ), '<select class="rbn-select" id="ed-type" name="status_code"><option>301</option><option>302</option><option>307</option><option>410</option></select>' );
		$edit .= '<div class="rbn-note rbn-note--err" role="alert" data-edit-error hidden></div></div></div>';
		$edit .= '<div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Cancel', 'wpseokit' ) . '</button><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'save' ) . esc_html__( 'Save', 'wpseokit' ) . '</button></div></form></dialog>';
		return $csv . $del . $edit;
	}
}
