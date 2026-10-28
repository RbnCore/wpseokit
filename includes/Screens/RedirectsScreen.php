<?php
/**
 * Redirects tool: list, add form, CSV import/export, preferred address and the on switch.
 * The list is drawn by the screen script from `/redirects`, the preferred address tab from `/preferred-address/*`.
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
				'pref'     => array( __( 'Preferred address', 'wpseokit' ), self::preferred() ),
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

	/** Preferred address tab: four cards. Every live part is filled by the screen script from `/preferred-address/*`. */
	private static function preferred(): string {
		$sch_cur = (string) Ui::val( 'redirects.preferred_scheme', 'auto' );
		$sch     = '';
		foreach ( array( 'auto' => __( 'Automatic', 'wpseokit' ), 'https' => 'https', 'http' => 'http' ) as $v => $l ) {
			$sch .= '<label><input type="radio" name="pa-sch" value="' . esc_attr( $v ) . '"' . checked( $sch_cur, $v, false ) . '><span' . ( 'auto' === $v ? ' data-addr-auto' : '' ) . '>' . esc_html( $l ) . '</span></label>';
		}
		$form = '<form class="rbn-stack" data-addr-form novalidate>'
			. Ui::note( 'info', '<b>' . esc_html__( 'Why only one address?', 'wpseokit' ) . '</b> ' . wp_kses( __( '<code>http</code>, <code>https</code>, <code>www</code> and no <code>www</code> make four different addresses. If all four answer, search engines see four copies of your site. One answers, the other three redirect to it.', 'wpseokit' ), array( 'code' => array() ) ) )
			. Ui::row( '<span id="pa-sch-l">' . esc_html__( 'Scheme', 'wpseokit' ) . '</span>', '<fieldset class="rbn-seg" aria-labelledby="pa-sch-l">' . $sch . '</fieldset>', esc_html__( 'Automatic follows your WordPress address. Google prefers https pages.', 'wpseokit' ) )
			. '<div class="rbn-field" data-addr-host-field><div class="rbn-field__l">' . Ui::label( 'pa-host', __( 'Host name', 'wpseokit' ) ) . '</div><div class="rbn-field__c"><input class="rbn-input rbn-input--mono" id="pa-host" name="pa-host" type="text" value="' . esc_attr( (string) Ui::val( 'redirects.preferred_host', '' ) ) . '" placeholder="' . esc_attr( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '" autocomplete="off" spellcheck="false" aria-describedby="pa-host-h"><p class="rbn-help" id="pa-host-h">' . wp_kses( __( 'Leave empty to use the host of your WordPress address. Choose with or without <code>www</code>, and keep it. Changing it later means new redirects for every address.', 'wpseokit' ), array( 'code' => array() ) ) . '</p><p class="rbn-err-msg" id="pa-host-e" data-addr-host-err hidden>' . Ui::icon( 'alert' ) . '<span>' . wp_kses( __( 'Enter the host name only, such as <code>example.com</code>. Leave out <code>https://</code> and any path.', 'wpseokit' ), array( 'code' => array() ) ) . '</span></p></div></div>'
			. '<p class="rbn-p" data-addr-inuse hidden>' . esc_html__( 'In use:', 'wpseokit' ) . ' <b class="wps-url" data-addr-origin></b> <span class="rbn-help">' . esc_html__( 'The audit and the rules use what you see here, even before you save.', 'wpseokit' ) . '</span></p>'
			. '<div data-addr-warn></div>'
			. '<div class="rbn-card__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'save' ) . esc_html__( 'Save preference', 'wpseokit' ) . '</button><span class="rbn-help" data-addr-saved role="status"></span></div></form>';

		$audit = '<div class="rbn-stack"><div class="rbn-card__act"><button type="button" class="rbn-btn rbn-btn--pri" data-audit-run>' . Ui::icon( 'scan' ) . '<span>' . esc_html__( 'Run audit', 'wpseokit' ) . '</span></button></div>'
			. '<p class="rbn-sr" role="status" data-audit-live></p>'
			. '<div data-audit-out><div class="rbn-empty"><span class="rbn-empty__i">' . Ui::icon( 'scan' ) . '</span><h3 class="wps-h3">' . esc_html__( 'Not checked yet', 'wpseokit' ) . '</h3><p>' . esc_html__( 'Run the audit to see how each version of your address behaves. The result is not saved. It also checks canonical tags, sitemap, feed and HSTS.', 'wpseokit' ) . '</p></div></div>'
			. self::verdict_help() . '</div>';

		$srv  = '<fieldset class="rbn-seg" aria-labelledby="pa-srv-l"><label><input type="radio" name="pa-srv" value="apache" checked><span>' . esc_html__( 'Apache (.htaccess)', 'wpseokit' ) . '</span></label><label><input type="radio" name="pa-srv" value="nginx"><span>nginx</span></label></fieldset>';
		$rule = '<div class="rbn-stack"><div data-rule-state aria-live="polite"></div><div class="rbn-stack" data-rule-body hidden>'
			. '<div class="rbn-note rbn-note--warn">' . Ui::icon( 'alert' ) . '<div><p><b>' . esc_html__( 'Before you paste it', 'wpseokit' ) . '</b></p><ul class="wps-ul" data-rule-warn></ul></div></div>'
			. '<p class="rbn-p"><b>' . esc_html__( 'WPSeoKit does not edit your server files.', 'wpseokit' ) . '</b> ' . esc_html__( 'You paste the rule yourself. After that, run the audit again: every version should show 301 and 1 redirect.', 'wpseokit' ) . '</p>'
			. '<p class="rbn-p" data-rule-panel></p>'
			. '<div class="rbn-field wps-field-1"><div class="rbn-field__l"><span id="pa-srv-l">' . esc_html__( 'Server', 'wpseokit' ) . '</span></div><div class="rbn-field__c">' . $srv . '</div></div>'
			. '<div data-srv="apache"><pre class="wps-pre wps-pre--scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Apache rule, scrolls sideways', 'wpseokit' ) . '"><code data-code="apache"></code></pre></div>'
			. '<div data-srv="nginx" hidden><pre class="wps-pre wps-pre--scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'nginx rule, scrolls sideways', 'wpseokit' ) . '"><code data-code="nginx"></code></pre></div>'
			. '<div class="rbn-card__act"><button type="button" class="rbn-btn rbn-btn--pri" data-rule-copy>' . Ui::icon( 'copy' ) . '<span>' . esc_html__( 'Copy Apache rule', 'wpseokit' ) . '</span></button><span class="rbn-help" data-rule-copied role="status"></span></div>'
			. '<h3 class="wps-h3">' . esc_html__( 'What the rule does', 'wpseokit' ) . '</h3>'
			. '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'What the rule does for each version', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'From', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'To', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Redirects', 'wpseokit' ) . '</th></tr></thead><tbody data-rule-table></tbody></table></div>'
			. '</div></div>';

		$fb = '<div class="rbn-stack"><div data-fb-state aria-live="polite"></div>'
			. '<label class="rbn-switch"><input type="checkbox" id="pa-php" data-fb="php_fallback" aria-describedby="pa-php-h"' . checked( (bool) Ui::val( 'redirects.php_fallback', false ), true, false ) . '><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Redirect with PHP when the server does not', 'wpseokit' ) . ' <span class="rbn-badge rbn-badge--mute">' . esc_html__( 'Slower than a server rule', 'wpseokit' ) . '</span></b><span class="rbn-help" id="pa-php-h">' . wp_kses( __( 'WordPress answers first, so every redirect takes longer than a server rule. Sends one 301 to the preferred address and keeps the path and query. Never touches wp-admin, the REST API, cron, XML-RPC, <code>/.well-known/</code> or requests that are not GET or HEAD.', 'wpseokit' ), array( 'code' => array() ) ) . '</span></span></label>'
			. '<label class="rbn-switch"><input type="checkbox" id="pa-proxy" data-fb="trust_proxy_headers" aria-describedby="pa-proxy-h"' . checked( (bool) Ui::val( 'redirects.trust_proxy_headers', false ), true, false ) . '><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Trust proxy headers', 'wpseokit' ) . '</b><span class="rbn-help" id="pa-proxy-h">' . wp_kses( __( 'Turn on only if a proxy or CDN sits in front of your site and hides https from WordPress. It then reads <code>X-Forwarded-Proto</code> and <code>X-Forwarded-Host</code>. Off: when a proxy header says the visitor used https, no redirect happens, so it cannot loop. Available when the PHP fallback is on.', 'wpseokit' ), array( 'code' => array() ) ) . '</span></span></label>'
			. '<p class="rbn-help" data-fb-saved role="status"></p></div>';

		return '<div class="wps-addr" data-addr>'
			. Ui::card( __( 'Preferred address', 'wpseokit' ), __( 'Pick the one address your site answers on. Every other version should send visitors there in a single step.', 'wpseokit' ), $form )
			. Ui::card( __( 'Address check', 'wpseokit' ), __( 'Asks your site for each of the four versions and follows the redirects, up to 5 steps. It changes nothing.', 'wpseokit' ), $audit )
			. Ui::card( __( 'Server rule', 'wpseokit' ), __( 'One rule that sends every other version straight to the preferred address. Copy it into your server settings or ask your host to add it.', 'wpseokit' ), $rule )
			. Ui::card( __( 'PHP fallback', 'wpseokit' ), __( 'Only for sites where you cannot add a server rule. Off by default.', 'wpseokit' ), $fb )
			. '</div>';
	}

	/** The verdict list under the audit; the script draws the same labels on each row (see `I.verdicts`). */
	private static function verdict_help(): string {
		$rows = '';
		foreach ( self::verdicts() as $v ) {
			$rows .= '<tr><th scope="row" data-l="' . esc_attr__( 'Verdict', 'wpseokit' ) . '"><span class="rbn-badge rbn-badge--' . esc_attr( $v[1] ) . '">' . Ui::icon( $v[2] ) . esc_html( $v[0] ) . '</span></th><td data-l="' . esc_attr__( 'Meaning', 'wpseokit' ) . '">' . esc_html( $v[3] ) . '</td></tr>';
		}
		return '<details><summary>' . esc_html__( 'What each verdict means', 'wpseokit' ) . '</summary><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Verdicts', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Verdict', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Meaning', 'wpseokit' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>';
	}

	/**
	 * Verdict id => label, badge tone, icon, meaning. Shared with the script through Ui.
	 *
	 * @return array<string, array{string,string,string,string}>
	 */
	public static function verdicts(): array {
		return array(
			'ok'               => array( __( 'OK', 'wpseokit' ), 'ok', 'check', __( 'The preferred address answers with 200, or another version reaches it with one 301 or 308.', 'wpseokit' ) ),
			'chain'            => array( __( 'Chain', 'wpseokit' ), 'warn', 'alert', __( 'Two or more redirects in a row. Send the version straight to the preferred address.', 'wpseokit' ) ),
			'temporary'        => array( __( 'Temporary redirect', 'wpseokit' ), 'warn', 'clock', __( '302, 303 or 307. Search engines may keep the old address. Use 301.', 'wpseokit' ) ),
			'duplicate'        => array( __( 'Duplicate page (200)', 'wpseokit' ), 'crit', 'x', __( 'The version answers with 200. It is a second copy of your site.', 'wpseokit' ) ),
			'wrong_target'     => array( __( 'Wrong target', 'wpseokit' ), 'crit', 'x', __( 'It redirects, but not to the preferred address.', 'wpseokit' ) ),
			'https_downgrade'  => array( __( 'HTTPS to HTTP', 'wpseokit' ), 'crit', 'lock', __( 'A step moves visitors from https to http. Remove it.', 'wpseokit' ) ),
			'loop'             => array( __( 'Redirect loop', 'wpseokit' ), 'crit', 'undo', __( 'The redirects return to an address already visited, or pass 5 steps.', 'wpseokit' ) ),
			'preferred_not_ok' => array( __( 'Preferred address not OK', 'wpseokit' ), 'crit', 'x', __( 'The preferred address itself does not answer with 200.', 'wpseokit' ) ),
			'unreachable'      => array( __( 'Unreachable', 'wpseokit' ), 'mute', 'octo', __( 'No DNS record, refused connection, certificate error or timeout. The reason is shown.', 'wpseokit' ) ),
		);
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
