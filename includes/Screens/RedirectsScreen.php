<?php
/**
 * Redirects tool: list, add form, CSV import/export, preferred address and the on switch.
 * The list is drawn by the screen script from `/redirects`, the preferred address tab from `/preferred-address/*`.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use AdminKit\Core\Security\Host;

use Wpseokit\Services\PreferredAddressApplyService;

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
		$filter  = '<form class="wps-filter wps-filter3" role="search" aria-label="' . esc_attr__( 'Filter redirects', 'wpseokit' ) . '" data-redirect-filter>';
		$filter .= '<div><label for="rf-q">' . esc_html__( 'Search', 'wpseokit' ) . '</label><input class="rbn-input" id="rf-q" name="search" type="search" placeholder="' . esc_attr__( 'Path or target', 'wpseokit' ) . '"></div>';
		$filter .= '<div><label for="rf-t">' . esc_html__( 'Type', 'wpseokit' ) . '</label><select class="rbn-select" id="rf-t" name="status_code"><option value="">' . esc_html__( 'All types', 'wpseokit' ) . '</option><option>301</option><option>302</option><option>307</option><option>410</option></select></div>';
		$filter .= '<div><label for="rf-s">' . esc_html__( 'Status', 'wpseokit' ) . '</label><select class="rbn-select" id="rf-s" name="is_active"><option value="">' . esc_html__( 'All', 'wpseokit' ) . '</option><option value="1">' . esc_html__( 'On', 'wpseokit' ) . '</option><option value="0">' . esc_html__( 'Off', 'wpseokit' ) . '</option></select></div>';
		$filter .= '<div class="wps-filter__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . esc_html__( 'Apply', 'wpseokit' ) . '</button></div></form>';

		$bulk  = '<div class="wps-bulk" data-bulk hidden><p><b data-count>0</b> ' . esc_html__( 'selected', 'wpseokit' ) . '</p><div class="wps-bulk__act">';
		$bulk .= '<button type="button" class="rbn-btn rbn-btn--sm" data-bulk-on>' . esc_html__( 'Turn on', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--sm" data-bulk-off>' . esc_html__( 'Turn off', 'wpseokit' ) . '</button>';
		$bulk .= '<button type="button" class="rbn-btn rbn-btn--danger rbn-btn--sm" data-bulk-delete>' . Ui::icon( 'trash' ) . esc_html__( 'Delete', 'wpseokit' ) . '</button></div></div>';

		$table  = '<div class="rbn-tw"><table class="rbn-table rbn-table--stack" data-redirects><caption class="rbn-sr">' . esc_html__( 'Redirects', 'wpseokit' ) . '</caption><thead><tr>';
		$table .= '<th scope="col" class="wps-chk-cell"><label class="rbn-check"><input type="checkbox" data-select-all><span class="rbn-sr">' . esc_html__( 'Select all redirects', 'wpseokit' ) . '</span></label></th>';
		$table .= '<th scope="col">' . esc_html__( 'From', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'To', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Type', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Hits', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Last hit', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wpseokit' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Actions', 'wpseokit' ) . '</span></th>';
		$table .= '</tr></thead><tbody></tbody></table></div><div class="rbn-pager" data-pager></div>';

		return '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'All redirects', 'wpseokit' ) . '</h2></div><div class="rbn-card__b rbn-card__b--flush">' . $filter . $bulk . '<div data-list-state></div>' . $table . '</div></section>';
	}

	private static function add(): string {
		$types = '';
		foreach ( array( '301', '302', '307', '410' ) as $i => $t ) {
			$types .= '<label class="rbn-check"><input type="radio" name="ra-type" value="' . $t . '"' . ( 0 === $i ? ' checked' : '' ) . '><span>' . $t . '</span></label>';
		}
		$add  = '<form class="wps-stack" data-redirect-add novalidate>';
		$add .= Ui::row( Ui::label( 'ra-from', __( 'From (old path)', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ra-from" name="source_path" type="text" placeholder="/old-page/" aria-describedby="ra-from-h" autocomplete="off">', wp_kses( __( 'Start with a slash. Use <code>*</code> at the end to match everything below that path.', 'wpseokit' ), array( 'code' => array() ) ), 'ra-from-h' );
		$add .= Ui::row( Ui::label( 'ra-to', __( 'To (new address)', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ra-to" name="target_url" type="text" placeholder="/new-page/" aria-describedby="ra-to-h" autocomplete="off">', esc_html__( 'A path on this site or a full address. Leave empty for type 410.', 'wpseokit' ), 'ra-to-h' );
		$add .= Ui::row( '<span id="ra-type-l">' . esc_html__( 'Type', 'wpseokit' ) . '</span>', '<fieldset class="wps-radios" aria-labelledby="ra-type-l">' . $types . '</fieldset>', esc_html__( '301 moves a page for good and passes its ranking. Use 302 or 307 only for short-term moves.', 'wpseokit' ) );
		$add .= '<div class="rbn-note rbn-note--err" role="alert" data-add-error hidden></div>';
		$add .= '<div class="wps-row"><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'plus' ) . esc_html__( 'Add redirect', 'wpseokit' ) . '</button></div></form>';
		return Ui::card( __( 'Add a redirect', 'wpseokit' ), __( 'Takes effect the moment you save.', 'wpseokit' ), $add );
	}

	private static function io(): string {
		$in  = '<p>' . wp_kses( __( 'One redirect per line: <code>from,to,type</code>. The check runs before anything is saved, and existing “from” addresses are never overwritten.', 'wpseokit' ), array( 'code' => array() ) ) . '</p>';
		$in .= '<div class="wps-row"><button type="button" class="rbn-btn rbn-btn--pri" data-csv-open>' . Ui::icon( 'upload' ) . esc_html__( 'Choose CSV file', 'wpseokit' ) . '</button></div>';
		$out = '<p>' . esc_html__( 'Every redirect, UTF-8, one per line.', 'wpseokit' ) . '</p><div class="wps-row"><button type="button" class="rbn-btn" data-csv-export>' . Ui::icon( 'down' ) . esc_html__( 'Export CSV', 'wpseokit' ) . '</button></div>';
		return Ui::card( __( 'Import', 'wpseokit' ), __( 'Add many redirects at once from a CSV file.', 'wpseokit' ), $in )
			. Ui::card( __( 'Export', 'wpseokit' ), __( 'Download every redirect as a CSV file, for a backup or another site.', 'wpseokit' ), $out );
	}

	/** Preferred address tab: four cards. Every live part is filled by the screen script from `/preferred-address/*`. */
	private static function preferred(): string {
		$sch_cur = (string) Ui::val( 'redirects.preferred_scheme', 'auto' );
		$sch     = '';
		foreach ( array( 'auto' => __( 'Automatic', 'wpseokit' ), 'https' => 'https', 'http' => 'http' ) as $v => $l ) {
			$sch .= '<label class="rbn-check"><input type="radio" name="pa-sch" value="' . esc_attr( $v ) . '"' . checked( $sch_cur, $v, false ) . '><span' . ( 'auto' === $v ? ' data-addr-auto' : '' ) . '>' . esc_html( $l ) . '</span></label>';
		}
		$form = '<form class="wps-stack" data-addr-form novalidate>'
			. Ui::note( 'info', '<b>' . esc_html__( 'Why only one address?', 'wpseokit' ) . '</b> ' . wp_kses( __( '<code>http</code>, <code>https</code>, <code>www</code> and no <code>www</code> make four different addresses. If all four answer, search engines see four copies of your site. One answers, the other three redirect to it.', 'wpseokit' ), array( 'code' => array() ) ) )
			. Ui::row( '<span id="pa-sch-l">' . esc_html__( 'Scheme', 'wpseokit' ) . '</span>', '<fieldset class="wps-radios" aria-labelledby="pa-sch-l">' . $sch . '</fieldset>', esc_html__( 'Automatic follows your WordPress address. Google prefers https pages.', 'wpseokit' ) )
			. '<div class="rbn-field" data-addr-host-field>' . Ui::label( 'pa-host', __( 'Host name', 'wpseokit' ) ) . '<input class="rbn-input rbn-input--mono" id="pa-host" name="pa-host" type="text" value="' . esc_attr( (string) Ui::val( 'redirects.preferred_host', '' ) ) . '" placeholder="' . esc_attr( Host::host( home_url() ) ) . '" autocomplete="off" spellcheck="false" aria-describedby="pa-host-h"><p class="rbn-help" id="pa-host-h">' . wp_kses( __( 'Leave empty to use the host of your WordPress address. Choose with or without <code>www</code>, and keep it. Changing it later means new redirects for every address.', 'wpseokit' ), array( 'code' => array() ) ) . '</p><p class="rbn-field__err" id="pa-host-e" data-addr-host-err hidden>' . Ui::icon( 'alert' ) . '<span>' . wp_kses( __( 'Enter the host name only, such as <code>example.com</code>. Leave out <code>https://</code> and any path.', 'wpseokit' ), array( 'code' => array() ) ) . '</span></p></div>'
			. '<p data-addr-inuse hidden>' . esc_html__( 'In use:', 'wpseokit' ) . ' <b class="wps-url" data-addr-origin></b> <span class="rbn-help">' . esc_html__( 'The audit and the rules use what you see here, even before you save.', 'wpseokit' ) . '</span></p>'
			. '<div data-addr-warn></div>'
			. '<div class="rbn-card__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'save' ) . esc_html__( 'Save preference', 'wpseokit' ) . '</button><span class="rbn-help" data-addr-saved role="status"></span></div></form>';

		$audit = '<div class="wps-stack"><div class="rbn-card__act"><button type="button" class="rbn-btn rbn-btn--pri" data-audit-run>' . Ui::icon( 'scan' ) . '<span>' . esc_html__( 'Run audit', 'wpseokit' ) . '</span></button></div>'
			. '<p class="rbn-sr" role="status" data-audit-live></p>'
			. '<div data-audit-out><div class="rbn-empty"><span class="rbn-empty__i">' . Ui::icon( 'scan' ) . '</span><h3 class="wps-h3">' . esc_html__( 'Not checked yet', 'wpseokit' ) . '</h3><p>' . esc_html__( 'Run the audit to see how each version of your address behaves. The result is not saved. It also checks canonical tags, sitemap, feed and HSTS.', 'wpseokit' ) . '</p></div></div>'
			. self::verdict_help() . '</div>';

		$srv  = '<fieldset class="wps-radios" aria-labelledby="pa-srv-l"><label class="rbn-check"><input type="radio" name="pa-srv" value="apache" checked><span>' . esc_html__( 'Apache (.htaccess)', 'wpseokit' ) . '</span></label><label class="rbn-check"><input type="radio" name="pa-srv" value="nginx"><span>nginx</span></label></fieldset>';
		$rule = '<div class="wps-stack"><div data-rule-state aria-live="polite"></div><div class="wps-stack" data-rule-body hidden>'
			. '<div class="rbn-note rbn-note--warn">' . Ui::icon( 'alert' ) . '<div><p><b>' . esc_html__( 'Before you paste it', 'wpseokit' ) . '</b></p><ul class="wps-ul" data-rule-warn></ul></div></div>'
			. '<p><b>' . esc_html__( 'WPSeoKit does not edit your server files.', 'wpseokit' ) . '</b> ' . esc_html__( 'You paste the rule yourself. After that, run the audit again: every version should show 301 and 1 redirect.', 'wpseokit' ) . '</p>'
			. '<p data-rule-panel></p>'
			. '<div class="rbn-field wps-field-1"><span class="rbn-label" id="pa-srv-l">' . esc_html__( 'Server', 'wpseokit' ) . '</span>' . $srv . '</div>'
			. '<div data-srv="apache"><pre class="wps-pre wps-pre--scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Apache rule, scrolls sideways', 'wpseokit' ) . '"><code data-code="apache"></code></pre></div>'
			. '<div data-srv="nginx" hidden><pre class="wps-pre wps-pre--scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'nginx rule, scrolls sideways', 'wpseokit' ) . '"><code data-code="nginx"></code></pre></div>'
			. '<div class="rbn-card__act"><button type="button" class="rbn-btn rbn-btn--pri" data-rule-copy>' . Ui::icon( 'copy' ) . '<span>' . esc_html__( 'Copy Apache rule', 'wpseokit' ) . '</span></button><span class="rbn-help" data-rule-copied role="status"></span></div>'
			. '<h3 class="wps-h3">' . esc_html__( 'What the rule does', 'wpseokit' ) . '</h3>'
			. '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'What the rule does for each version', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'From', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'To', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Redirects', 'wpseokit' ) . '</th></tr></thead><tbody data-rule-table></tbody></table></div>'
			. '</div></div>';

		$fb = '<div class="wps-stack"><div data-fb-state aria-live="polite"></div>'
			. '<label class="rbn-switch"><input type="checkbox" id="pa-php" data-fb="php_fallback" aria-describedby="pa-php-h"' . checked( (bool) Ui::val( 'redirects.php_fallback', false ), true, false ) . '><span class="rbn-switch__x"></span><span><b>' . esc_html__( 'Redirect with PHP when the server does not', 'wpseokit' ) . ' <span class="rbn-badge rbn-badge--mute">' . esc_html__( 'Slower than a server rule', 'wpseokit' ) . '</span></b><span class="rbn-help" id="pa-php-h">' . wp_kses( __( 'WordPress answers first, so every redirect takes longer than a server rule. Sends one 301 to the preferred address and keeps the path and query. Never touches wp-admin, the REST API, cron, XML-RPC, <code>/.well-known/</code> or requests that are not GET or HEAD.', 'wpseokit' ), array( 'code' => array() ) ) . '</span></span></label>'
			. '<label class="rbn-switch"><input type="checkbox" id="pa-proxy" data-fb="trust_proxy_headers" aria-describedby="pa-proxy-h"' . checked( (bool) Ui::val( 'redirects.trust_proxy_headers', false ), true, false ) . '><span class="rbn-switch__x"></span><span><b>' . esc_html__( 'Trust proxy headers', 'wpseokit' ) . '</b><span class="rbn-help" id="pa-proxy-h">' . wp_kses( __( 'Turn on only if a proxy or CDN sits in front of your site and hides https from WordPress. It then reads <code>X-Forwarded-Proto</code> and <code>X-Forwarded-Host</code>. Off: when a proxy header says the visitor used https, no redirect happens, so it cannot loop. Available when the PHP fallback is on.', 'wpseokit' ), array( 'code' => array() ) ) . '</span></span></label>'
			. '<p class="rbn-help" data-fb-saved role="status"></p></div>';

		return '<div class="wps-addr" data-addr>'
			. Ui::card( __( 'Preferred address', 'wpseokit' ), __( 'Pick the one address your site answers on. Every other version should send visitors there in a single step.', 'wpseokit' ), $form )
			. self::apply_card()
			. Ui::card( __( 'Address check', 'wpseokit' ), __( 'Asks your site for each of the four versions and follows the redirects, up to 5 steps. It changes nothing.', 'wpseokit' ), $audit )
			. Ui::card( __( 'Server rule', 'wpseokit' ), __( 'One rule that sends every other version straight to the preferred address. Copy it into your server settings or ask your host to add it.', 'wpseokit' ), $rule )
			. Ui::card( __( 'PHP fallback', 'wpseokit' ), __( 'Only for sites where you cannot add a server rule. Off by default.', 'wpseokit' ), $fb )
			. '</div>';
	}

	/**
	 * The "Apply this address" card: the pre-check, the apply, the confirmation window and the undo.
	 *
	 * The form goes to admin-post.php (nonce + `manage_options`); the check result is drawn on the page, so the
	 * button is only there when the check passed. No browser dialog and no new JavaScript.
	 */
	private static function apply_card(): string {
		$state   = PreferredAddressApplyService::state();
		$pending = (array) $state['pending'];
		$precheck = PreferredAddressApplyService::precheck_of();
		$body    = '';

		if ( ! empty( $state['disabled'] ) ) {
			$body .= Ui::note(
				'warn',
				'<b>' . esc_html__( 'The feature is switched off.', 'wpseokit' ) . '</b> ' . esc_html__( 'WPSEOKIT_PA_DISABLE is defined in wp-config.php, so nothing is changed and nothing is redirected.', 'wpseokit' )
			);
		}

		// The result of the last action (the form sends it back to this page).
		$body .= self::apply_note();

		if ( 'awaiting' === $state['status'] ) {
			$body .= self::apply_pending( $pending );
			return Ui::card( __( 'Apply this address', 'wpseokit' ), __( 'The address is waiting for your confirmation.', 'wpseokit' ), $body );
		}

		if ( 'applied' === $state['status'] ) {
			$body .= self::apply_done( $state );
			return Ui::card( __( 'Apply this address', 'wpseokit' ), __( 'The preferred address is in effect.', 'wpseokit' ), $body );
		}

		// Not applied yet: the form, the check result and the apply button.
		$host    = (string) Ui::val( 'redirects.preferred_host', '' );
		$scheme  = (string) Ui::val( 'redirects.preferred_scheme', 'auto' );
		$cands   = PreferredAddressApplyService::candidates();
		$other   = '';
		foreach ( $cands as $c ) {
			if ( $c !== PreferredAddressApplyService::home_host() ) {
				$other = $c;
			}
		}
		if ( '' === $host ) {
			$host = $other;
		}
		// The forms carry the chosen address (the host field is drawn above them).
		$GLOBALS['pa_host']   = $host;
		$GLOBALS['pa_scheme'] = $scheme;
		$schemes = '';
		foreach ( array( 'auto' => __( 'Automatic', 'wpseokit' ), 'https' => 'https', 'http' => 'http' ) as $v => $l ) {
			$schemes .= '<label class="rbn-check"><input type="radio" name="pa-scheme" value="' . esc_attr( $v ) . '"' . checked( $scheme, $v, false ) . '><span>' . esc_html( $l ) . '</span></label>';
		}
		$body .= '<div class="wps-stack">'
			. Ui::note(
				'info',
				'<b>' . esc_html__( 'What this does', 'wpseokit' ) . '</b> ' . esc_html__( 'It writes the new address into WordPress and sends the old address there with one 301. You will have to log in again at the new address, because the cookie domain changes.', 'wpseokit' )
			)
			. self::check_form( $host, $scheme, $schemes )
			. '</div>';

		if ( array() !== $precheck ) {
			$body .= self::apply_checks( $precheck );
			if ( ! empty( $precheck['ok'] ) ) {
				$body .= Ui::note(
					'warn',
					'<b>' . esc_html__( 'You will have to log in again.', 'wpseokit' ) . '</b> ' . esc_html__( 'The address of the site changes, so the browser is sent to the new address and the session starts there. If the new address does not work, the previous one comes back by itself within ten minutes.', 'wpseokit' )
				);
				$body .= self::apply_form( 'apply', (string) $precheck['token'], esc_html__( 'Apply this address', 'wpseokit' ), $host, $scheme );
			}
		}

		if ( in_array( (string) ( $state['undo']['state'] ?? '' ), array( 'undone', 'rolled_back' ), true ) ) {
			$body .= Ui::note(
				'ok',
				'<b>' . esc_html__( 'The previous address is in effect again.', 'wpseokit' ) . '</b> ' . esc_html__( 'You can apply the address again whenever you are ready.', 'wpseokit' )
			);
		}

		return Ui::card( __( 'Apply this address', 'wpseokit' ), __( 'Moves the site to the chosen address and turns the permanent redirect on.', 'wpseokit' ), $body );
	}

	/** A form of the apply card (admin-post.php: the nonce and the capability are checked there). */
	private static function apply_form( string $action, string $token, string $label, string $host = '', string $scheme = 'auto' ): string {
		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wps-stack">';
		$form .= '<input type="hidden" name="action" value="wpseokit_preferred_address">';
		$form .= '<input type="hidden" name="pa_action" value="' . esc_attr( $action ) . '">';
		$form .= '<input type="hidden" name="pa-host" value="' . esc_attr( $host ) . '">';
		$form .= '<input type="hidden" name="pa-scheme" value="' . esc_attr( $scheme ) . '">';
		$form .= '<input type="hidden" name="pa-token" value="' . esc_attr( $token ) . '">';
		$form .= wp_nonce_field( 'wpseokit_pa', '_wpnonce', true, false );
		$form .= '<div class="rbn-card__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'check' ) . esc_html( $label ) . '</button></div></form>';
		return $form;
	}

	/**
	 * The form of the check: the host field, the scheme and the "Check first" button. The button posts the values
	 * the site owner typed, so no JavaScript is needed.
	 */
	private static function check_form( string $host, string $scheme, string $schemes ): string {
		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wps-stack">';
		$form .= '<input type="hidden" name="action" value="wpseokit_preferred_address">';
		$form .= '<input type="hidden" name="pa_action" value="precheck">';
		$form .= wp_nonce_field( 'wpseokit_pa', '_wpnonce', true, false );
		$form .= Ui::row(
			'<span id="pa-target-l">' . esc_html__( 'New address', 'wpseokit' ) . '</span>',
			'<input class="rbn-input rbn-input--mono" id="pa-target-host" name="pa-host" type="text" value="' . esc_attr( $host ) . '" autocomplete="off" spellcheck="false" aria-describedby="pa-target-h"><p class="rbn-help" id="pa-target-h">' . esc_html__( 'Only the other version of this site\'s own address may be applied: the name with www and the name without it.', 'wpseokit' ) . '</p>'
		);
		$form .= Ui::row( '<span id="pa-target-sch-l">' . esc_html__( 'Scheme', 'wpseokit' ) . '</span>', '<fieldset class="wps-radios" aria-labelledby="pa-target-sch-l">' . $schemes . '</fieldset>', esc_html__( 'A site on https is never moved to http.', 'wpseokit' ) );
		$form .= '<div class="rbn-card__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'scan' ) . esc_html__( 'Check first', 'wpseokit' ) . '</button></div></form>';
		return $form;
	}

	/** The result of the last check, drawn as a list of checks. */
	private static function apply_checks( array $precheck ): string {
		$out = '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Checks before applying', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Check', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Result', 'wpseokit' ) . '</th></tr></thead><tbody>';
		foreach ( (array) ( $precheck['checks'] ?? array() ) as $check ) {
			$state = (string) ( $check['state'] ?? 'ok' );
			$tone  = 'ok' === $state ? 'ok' : ( 'warn' === $state ? 'warn' : 'err' );
			$icon  = 'ok' === $state ? 'check' : ( 'warn' === $state ? 'alert' : 'x' );
			$out  .= '<tr><th scope="row" data-label="' . esc_attr__( 'Check', 'wpseokit' ) . '">' . esc_html( (string) ( $check['title'] ?? '' ) ) . '</th>'
				. '<td data-label="' . esc_attr__( 'Result', 'wpseokit' ) . '"><span class="rbn-badge rbn-badge--' . esc_attr( $tone ) . '">' . Ui::icon( $icon ) . esc_html( (string) ( $check['text'] ?? '' ) ) . '</span>';
			foreach ( (array) ( $check['lines'] ?? array() ) as $line ) {
				$out .= '<pre class="wps-pre wps-pre--scroll" tabindex="0" role="region"><code>' . esc_html( (string) $line ) . '</code></pre>';
			}
			$out .= '</td></tr>';
		}
		$out .= '</tbody></table></div>';
		foreach ( (array) ( $precheck['warnings'] ?? array() ) as $w ) {
			$out .= Ui::note( 'warn', esc_html( (string) $w ) );
		}
		return $out;
	}

	/** The open confirmation window: the time left, the confirm button and the undo button. */
	private static function apply_pending( array $pending ): string {
		$origin   = (string) ( $pending['origin'] ?? '' );
		$token    = (string) ( $pending['token'] ?? '' );
		$left     = max( 0, (int) ( $pending['remaining'] ?? 0 ) );
		$minutes  = (int) ceil( $left / 60 );
		$out      = Ui::note(
			'warn',
			'<b>' . esc_html__( 'Waiting for your confirmation.', 'wpseokit' ) . '</b> ' . sprintf(
				/* translators: 1: the new address, 2: minutes left */
				__( 'The site now answers on %1$s. Log in there and confirm within %2$d minutes; if you do not, the previous address comes back by itself.', 'wpseokit' ),
				esc_html( $origin ),
				$minutes
			)
		);
		$out     .= self::apply_form( 'confirm', $token, esc_html__( 'Confirm the new address', 'wpseokit' ) );
		$out     .= self::apply_form( 'undo', '', esc_html__( 'Go back to the previous address', 'wpseokit' ) );
		return $out;
	}

	/** The state after the address was applied and confirmed. */
	private static function apply_done( array $state ): string {
		$old   = PreferredAddressApplyService::old_host();
		$count = PreferredAddressApplyService::old_link_posts( $old );
		$out   = Ui::note(
			'ok',
			'<b>' . esc_html__( 'The new address is in effect.', 'wpseokit' ) . '</b> ' . sprintf(
				/* translators: %s: the old host name */
				__( 'The old address (%s) now sends visitors to the new one with one 301.', 'wpseokit' ),
				esc_html( $old )
			)
		);
		$out  .= Ui::note(
			'info',
			sprintf(
				/* translators: %d: number of posts */
				__( 'Information only: %d posts still carry the old address in their content. Those links go to the new address with a 301 as well; the plugin does not rewrite your content.', 'wpseokit' ),
				$count
			)
		);
		$out  .= self::apply_form( 'undo', '', esc_html__( 'Go back to the previous address', 'wpseokit' ) );
		return $out;
	}

	/** The result of the last action, shown at the top of the card. */
	private static function apply_note(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading two page arguments only; they are matched against a fixed list.
		$step = isset( $_GET['pa_step'] ) ? sanitize_key( wp_unslash( $_GET['pa_step'] ) ) : '';
		$res  = isset( $_GET['pa_res'] ) ? sanitize_key( wp_unslash( $_GET['pa_res'] ) ) : '';
		// phpcs:enable
		if ( '' === $step ) {
			return '';
		}
		if ( 'apply' === $step ) {
			return 'ok' === $res
				? Ui::note( 'ok', esc_html__( 'The address was applied. The browser is sent to the new address; log in there to confirm it.', 'wpseokit' ) )
				: Ui::note( 'err', esc_html__( 'The address was not applied. See the checks below.', 'wpseokit' ) );
		}
		if ( 'confirm' === $step ) {
			return Ui::note( 'ok', esc_html__( 'The new address was confirmed.', 'wpseokit' ) );
		}
		if ( 'undo' === $step ) {
			return Ui::note( 'ok', esc_html__( 'The previous address was restored.', 'wpseokit' ) );
		}
		if ( 'check' === $step ) {
			return 'ok' === $res
				? Ui::note( 'ok', esc_html__( 'The check is finished.', 'wpseokit' ) )
				: Ui::note( 'warn', esc_html__( 'The check found something that has to be fixed first.', 'wpseokit' ) );
		}
		return '';
	}

	/** The verdict list under the audit; the script draws the same labels on each row (see `I.verdicts`). */
	private static function verdict_help(): string {
		$rows = '';
		foreach ( self::verdicts() as $v ) {
			$rows .= '<tr><th scope="row" data-label="' . esc_attr__( 'Verdict', 'wpseokit' ) . '"><span class="rbn-badge rbn-badge--' . esc_attr( $v[1] ) . '">' . Ui::icon( $v[2] ) . esc_html( $v[0] ) . '</span></th><td data-label="' . esc_attr__( 'Meaning', 'wpseokit' ) . '">' . esc_html( $v[3] ) . '</td></tr>';
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
			'duplicate'        => array( __( 'Duplicate page (200)', 'wpseokit' ), 'err', 'x', __( 'The version answers with 200. It is a second copy of your site.', 'wpseokit' ) ),
			'wrong_target'     => array( __( 'Wrong target', 'wpseokit' ), 'err', 'x', __( 'It redirects, but not to the preferred address.', 'wpseokit' ) ),
			'https_downgrade'  => array( __( 'HTTPS to HTTP', 'wpseokit' ), 'err', 'lock', __( 'A step moves visitors from https to http. Remove it.', 'wpseokit' ) ),
			'loop'             => array( __( 'Redirect loop', 'wpseokit' ), 'err', 'undo', __( 'The redirects return to an address already visited, or pass 5 steps.', 'wpseokit' ) ),
			'preferred_not_ok' => array( __( 'Preferred address not OK', 'wpseokit' ), 'err', 'x', __( 'The preferred address itself does not answer with 200.', 'wpseokit' ) ),
			'unreachable'      => array( __( 'Unreachable', 'wpseokit' ), 'mute', 'octo', __( 'No DNS record, refused connection, certificate error or timeout. The reason is shown.', 'wpseokit' ) ),
		);
	}

	private static function settings(): string {
		$body = Ui::toggle_row( __( 'Redirects', 'wpseokit' ), 'redirects.enabled', 'rs-on', __( 'Run the redirects on the live site', 'wpseokit' ), __( 'Off = every redirect pauses and visitors see the old address. Nothing is deleted.', 'wpseokit' ) );
		return Ui::form( 'redirects', Ui::card( __( 'Redirect behavior', 'wpseokit' ), '', $body ) );
	}

	private static function dialogs(): string {
		$x   = '<button type="button" class="rbn-btn rbn-btn--icon" data-dlg-close aria-label="' . esc_attr__( 'Close', 'wpseokit' ) . '">' . Ui::icon( 'x' ) . '</button>';
		$csv = '<dialog class="rbn-dlg wps-dlg--mid" aria-labelledby="csv-t" data-dlg="csv"' . Ui::tone_attr() . '><div class="rbn-dlg__h wps-dlg__h"><h2 id="csv-t">' . esc_html__( 'Import redirects from CSV', 'wpseokit' ) . '</h2>' . $x . '</div><div class="rbn-dlg__b"><div class="wps-stack">';
		$csv .= '<p>' . wp_kses( __( 'One redirect per line: <code>from,to,type</code>. The first line may be a header. Existing redirects with the same “from” are skipped, never overwritten.', 'wpseokit' ), array( 'code' => array() ) ) . '</p>';
		$csv .= Ui::row( Ui::label( 'csv-f', __( 'CSV file', 'wpseokit' ) ), '<input class="rbn-input" type="file" id="csv-f" accept=".csv,text/csv" data-csv-file>', esc_html__( 'Up to 1 MB, UTF-8.', 'wpseokit' ) );
		$csv .= '<div data-csv-report aria-live="polite"></div></div></div>';
		$csv .= '<div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Cancel', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-csv-run disabled>' . Ui::icon( 'upload' ) . esc_html__( 'Import', 'wpseokit' ) . '</button></div></dialog>';

		$del  = '<dialog class="rbn-dlg" aria-labelledby="del-t" data-dlg="delete"' . Ui::tone_attr() . '><div class="rbn-dlg__h wps-dlg__h"><h2 id="del-t" data-del-title></h2>' . $x . '</div><div class="rbn-dlg__b"><p data-del-body></p></div>';
		$del .= '<div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Keep them', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--danger" data-del-run>' . Ui::icon( 'trash' ) . '<span></span></button></div></dialog>';

		$edit  = '<dialog class="rbn-dlg" aria-labelledby="ed-t" data-dlg="edit"' . Ui::tone_attr() . '><form method="dialog" data-edit-form novalidate><div class="rbn-dlg__h wps-dlg__h"><h2 id="ed-t">' . esc_html__( 'Edit redirect', 'wpseokit' ) . '</h2>' . $x . '</div><div class="rbn-dlg__b"><div class="wps-stack">';
		$edit .= Ui::row( Ui::label( 'ed-from', __( 'From', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ed-from" name="source_path" type="text">' );
		$edit .= Ui::row( Ui::label( 'ed-to', __( 'To', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="ed-to" name="target_url" type="text">' );
		$edit .= Ui::row( Ui::label( 'ed-type', __( 'Type', 'wpseokit' ) ), '<select class="rbn-select" id="ed-type" name="status_code"><option>301</option><option>302</option><option>307</option><option>410</option></select>' );
		$edit .= '<div class="rbn-note rbn-note--err" role="alert" data-edit-error hidden></div></div></div>';
		$edit .= '<div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-dlg-close>' . esc_html__( 'Cancel', 'wpseokit' ) . '</button><button type="submit" class="rbn-btn rbn-btn--pri">' . Ui::icon( 'save' ) . esc_html__( 'Save', 'wpseokit' ) . '</button></div></form></dialog>';
		return $csv . $del . $edit;
	}
}
