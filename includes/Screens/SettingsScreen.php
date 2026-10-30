<?php
/**
 * Settings tab of the dashboard: setup checklist, general (sharing, author and attachment pages), verification codes, access and advanced (data, status, updates).
 * Each tab that holds settings has its own save bar, so the updates card can keep its own forms.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use Wpseokit\Admin;
use Wpseokit\Db;
use Wpseokit\Plugin;

defined( 'ABSPATH' ) || exit;

final class SettingsScreen {

	/**
	 * Sub-tabs of the dashboard "Settings" tab: sub id => [label, panel html].
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function panels(): array {
		return array(
			'setup'        => array( __( 'Setup', 'wpseokit' ), self::setup() ),
			'general'      => array( __( 'General', 'wpseokit' ), '<h2 class="rbn-sr">' . esc_html__( 'General', 'wpseokit' ) . '</h2>' . self::general() ),
			'verification' => array( __( 'Verification', 'wpseokit' ), '<h2 class="rbn-sr">' . esc_html__( 'Verification', 'wpseokit' ) . '</h2>' . self::verification() ),
			'access'       => array( __( 'Access', 'wpseokit' ), self::access() ),
			'advanced'     => array( __( 'Advanced', 'wpseokit' ), '<h2 class="rbn-sr">' . esc_html__( 'Advanced', 'wpseokit' ) . '</h2>' . self::advanced() ),
		);
	}

	/** Setup checklist: the checks that decide whether the site is ready for search engines. */
	private static function setup(): string {
		$rows = '';
		foreach ( DashboardScreen::checks() as $c ) {
			$rows .= '<tr><th scope="row" data-label="' . esc_attr__( 'Check', 'wpseokit' ) . '">' . esc_html( $c[0] ) . '</th><td data-label="' . esc_attr__( 'Status', 'wpseokit' ) . '">' . $c[1] . '</td><td data-label="' . esc_attr__( 'Action', 'wpseokit' ) . '" class="rbn-acts"><a class="rbn-btn rbn-btn--sm' . ( $c[4] ? ' rbn-btn--pri' : '' ) . '" href="' . esc_url( $c[3] ) . '"' . ( $c[5] ?? '' ) . '>' . esc_html( $c[2] ) . '</a></td></tr>';
		}
		$table = '<div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Setup checklist', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Check', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wpseokit' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Action', 'wpseokit' ) . '</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
		return '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Setup checklist', 'wpseokit' ) . '</h2><p>' . esc_html__( 'These checks decide whether your site is ready for search engines.', 'wpseokit' ) . '</p></div>' . $table . '</section>';
	}

	private static function general(): string {
		$state  = Plugin::output_enabled()
			? '<span class="rbn-badge rbn-badge--ok">' . Ui::icon( 'check' ) . esc_html__( 'On', 'wpseokit' ) . '</span>'
			: '<span class="rbn-badge rbn-badge--warn">' . Ui::icon( 'alert' ) . esc_html__( 'Paused while Yoast SEO is active', 'wpseokit' ) . '</span>';
		$output = Ui::row( esc_html__( 'Output', 'wpseokit' ), $state, esc_html__( 'WPSeoKit prints titles, descriptions and schema only when no other SEO plugin does.', 'wpseokit' ) );

		$pages  = Ui::toggle_row( __( 'Primary category', 'wpseokit' ), 'general.primary_category', 'st-pc', __( 'Let editors pick a primary category', 'wpseokit' ), __( 'The primary category sets the post URL and the breadcrumb.', 'wpseokit' ) );
		$pages .= Ui::toggle_row( __( 'Author pages', 'wpseokit' ), 'general.disable_author_archive', 'st-auth', __( 'Turn off author archives', 'wpseokit' ), __( 'Visitors are redirected to the home page.', 'wpseokit' ) );
		$pages .= Ui::toggle_row( __( 'Attachment pages', 'wpseokit' ), 'general.disable_attachment_pages', 'st-att', __( 'Redirect attachment pages to the file', 'wpseokit' ) );

		$social  = Ui::toggle_row( __( 'Open Graph', 'wpseokit' ), 'social.opengraph', 'st-og', __( 'Print Open Graph tags', 'wpseokit' ), __( 'Used by Facebook, LinkedIn, WhatsApp and most chat apps.', 'wpseokit' ) );
		$social .= Ui::toggle_row( __( 'Twitter / X cards', 'wpseokit' ), 'social.twitter', 'st-tw', __( 'Print Twitter card tags', 'wpseokit' ) );
		$social .= Ui::seg(
			'social.twitter_card_type',
			'st-card',
			__( 'Card type', 'wpseokit' ),
			array(
				'summary'             => __( 'Small image', 'wpseokit' ),
				'summary_large_image' => __( 'Large image', 'wpseokit' ),
			)
		);
		$social .= Ui::row( '<span id="st-img-l">' . esc_html__( 'Default sharing image', 'wpseokit' ) . '</span>', Ui::media( 'social.default_image_id', 'social.default_image_url', 'st-img' ), esc_html__( 'Used when a post has no sharing image and no featured image. 1200 × 630 px works best.', 'wpseokit' ) );
		$social .= Ui::text( 'social.facebook_site', 'st-fb', __( 'Facebook page', 'wpseokit' ), array( 'type' => 'url', 'mono' => true ) );
		$social .= Ui::text( 'social.twitter_site', 'st-tws', __( 'Twitter / X username', 'wpseokit' ), array( 'mono' => true, 'placeholder' => '@yourname' ) );

		$top = '';
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$top = Ui::note( 'warn', '<b>' . esc_html__( 'WordPress is set to discourage search engines.', 'wpseokit' ) . '</b> ' . esc_html__( 'WPSeoKit does not change this.', 'wpseokit' ) . ' <a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">' . esc_html__( 'Open Reading settings', 'wpseokit' ) . '</a>', 'status' );
		}
		return $top . Ui::form(
			'settings',
			Ui::card( __( 'Output', 'wpseokit' ), '', $output )
			. Ui::card( __( 'Pages', 'wpseokit' ), '', $pages )
			. Ui::card( __( 'Social sharing', 'wpseokit' ), '', $social )
		);
	}

	private static function verification(): string {
		$verify = '';
		foreach ( array(
			'verify_google' => __( 'Google Search Console', 'wpseokit' ),
			'verify_bing'   => __( 'Bing Webmaster Tools', 'wpseokit' ),
			'verify_yandex' => __( 'Yandex Webmaster', 'wpseokit' ),
			'verify_baidu'  => __( 'Baidu Webmaster', 'wpseokit' ),
		) as $key => $label ) {
			$verify .= Ui::text( 'general.' . $key, 'st-' . $key, $label, array( 'mono' => true, 'help' => __( 'Leave empty to print nothing.', 'wpseokit' ) ) );
		}
		return Ui::form( 'settings', Ui::card( __( 'Site verification', 'wpseokit' ), __( 'Paste only the code from each meta tag’s content, not the whole tag.', 'wpseokit' ), $verify ) );
	}

	private static function access(): string {
		$rows = '';
		foreach ( array(
			array( __( 'Change settings, redirects and the 404 log', 'wpseokit' ), __( 'Administrators', 'wpseokit' ), __( 'A wrong setting or redirect affects every visitor, so these stay with administrators.', 'wpseokit' ) ),
			array( __( 'Edit the SEO box of a post', 'wpseokit' ), __( 'Anyone who can edit that post', 'wpseokit' ), __( 'Follows the WordPress permission to edit the post.', 'wpseokit' ) ),
		) as $r ) {
			$rows .= '<tr><th scope="row" data-label="' . esc_attr__( 'Action', 'wpseokit' ) . '">' . esc_html( $r[0] ) . '</th><td data-label="' . esc_attr__( 'Who', 'wpseokit' ) . '">' . esc_html( $r[1] ) . '</td><td data-label="' . esc_attr__( 'Why', 'wpseokit' ) . '">' . esc_html( $r[2] ) . '</td></tr>';
		}
		$table = '<div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Who can use WPSeoKit', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Action', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Who', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Why', 'wpseokit' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
		return '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Who can use WPSeoKit', 'wpseokit' ) . '</h2><p>' . esc_html__( 'Access follows WordPress roles. Administrators always keep full access.', 'wpseokit' ) . '</p></div>' . $table . '</section>';
	}

	private static function advanced(): string {
		$score_reports  = Ui::toggle_row( __( 'Daily recalculation', 'wpseokit' ), 'score.daily_recalc', 'st-score-daily', __( 'Recalculate SEO scores daily', 'wpseokit' ), __( 'When turned off, scores are only recalculated when you click Recalculate or edit a post.', 'wpseokit' ) );
		$score_reports .= Ui::text( 'score.cache_hours', 'st-score-cache', __( 'Score cache lifetime', 'wpseokit' ), array( 'type' => 'number', 'min' => 1, 'max' => 48, 'step' => 1, 'unit' => __( 'hours', 'wpseokit' ), 'help' => __( 'How long calculated scores stay cached before a refresh (1–48 hours).', 'wpseokit' ) ) );
		$score_reports .= Ui::text( 'score.http_timeout', 'st-score-timeout', __( 'HTTP timeout', 'wpseokit' ), array( 'type' => 'number', 'min' => 2, 'max' => 15, 'step' => 1, 'unit' => __( 'seconds', 'wpseokit' ), 'help' => __( 'Wait time for local HTTP requests during checks (2–15 seconds).', 'wpseokit' ) ) );
		$score_reports .= Ui::text( 'score.batch_seconds', 'st-score-batch', __( 'Batch runtime', 'wpseokit' ), array( 'type' => 'number', 'min' => 5, 'max' => 60, 'step' => 1, 'unit' => __( 'seconds', 'wpseokit' ), 'help' => __( 'Maximum time per background batch when scoring posts (5–60 seconds).', 'wpseokit' ) ) );
		$score_reports .= Ui::toggle_row( __( 'Daily snapshots', 'wpseokit' ), 'reports.daily_enabled', 'st-rep-daily', __( 'Save daily snapshot reports', 'wpseokit' ), __( 'When turned off, daily snapshot reports are paused. You can still take snapshots manually.', 'wpseokit' ) );
		$score_reports .= Ui::text( 'reports.retention_days', 'st-rep-retention', __( 'Snapshot retention', 'wpseokit' ), array( 'type' => 'number', 'min' => 30, 'max' => 1095, 'step' => 1, 'unit' => __( 'days', 'wpseokit' ), 'help' => __( 'How many days of snapshot history to keep before deleting old ones (30–1095 days).', 'wpseokit' ) ) );

		$data = Ui::toggle_row( __( 'Uninstall', 'wpseokit' ), 'general.delete_on_uninstall', 'st-del', __( 'Delete all WPSeoKit data when the plugin is deleted', 'wpseokit' ), __( 'Settings, redirects, the 404 log and per-post SEO fields. Leave off to keep them for a later reinstall.', 'wpseokit' ) );

		$status = '';
		foreach ( array(
			__( 'Plugin version', 'wpseokit' )  => WPSEOKIT_VERSION,
			__( 'Database schema', 'wpseokit' ) => (string) (int) get_option( Db::OPTION, 0 ),
			__( 'Output', 'wpseokit' )          => Plugin::output_enabled() ? __( 'On', 'wpseokit' ) : __( 'Off (Yoast SEO is active)', 'wpseokit' ),
		) as $label => $value ) {
			$status .= '<tr><th scope="row" data-label="' . esc_attr__( 'Item', 'wpseokit' ) . '">' . esc_html( $label ) . '</th><td data-label="' . esc_attr__( 'Value', 'wpseokit' ) . '">' . esc_html( $value ) . '</td></tr>';
		}
		$status = '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Status', 'wpseokit' ) . '</h2></div><div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Plugin status', 'wpseokit' ) . '</caption><tbody>' . $status . '</tbody></table></div></div></section>';

		return Admin::note_html()
			. Ui::form(
				'settings',
				Ui::card( __( 'SEO score and reports', 'wpseokit' ), __( 'Background calculation of SEO health scores and daily performance snapshots.', 'wpseokit' ), $score_reports, ' id="sec-score-reports"' )
				. Ui::card( __( 'Data and uninstall', 'wpseokit' ), '', $data )
			)
			. $status
			. self::updates();
	}

	/** Update check card; its buttons post to admin-post.php, so it sits outside the settings form. */
	private static function updates(): string {
		$st      = \AdminKit\Core\Updater::status( 'wpseokit' );
		$checked = (int) $st['checked_at'];
		$state   = \AdminKit\Core\Updater::state_html( $st );
		$last    = $checked > 0 ? esc_html( wp_date( 'd.m.Y H:i', $checked ) ) : esc_html__( 'never', 'wpseokit' );

		$body  = Ui::row( esc_html__( 'Installed version', 'wpseokit' ), '<b>' . esc_html( WPSEOKIT_VERSION ) . '</b>' );
		$body .= Ui::row( esc_html__( 'Update status', 'wpseokit' ), '<p>' . $state . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		$body .= Ui::row( esc_html__( 'Last check', 'wpseokit' ), '<span>' . $last . '</span>', esc_html__( 'The package is verified with its SHA-256 digest and Ed25519 signature before installation; a package that cannot be verified is not installed. Automatic updates are turned on or off with the “Enable auto-updates” link on the Plugins page.', 'wpseokit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$body .= '<p class="rbn-help"><a href="' . esc_url( admin_url( 'admin.php?page=adminkit#adminkit-network' ) ) . '">' . esc_html__( 'Update checks follow the RBN Kit network setting.', 'wpseokit' ) . '</a></p>';
		$act = \AdminKit\Core\Updater::check_form( 'wpseokit', Admin::page_url( array( 'tab' => 'settings' ) ), Ui::icon( 'scan' ) . esc_html__( 'Check now', 'wpseokit' ) );
		if ( '' !== $act ) {
			$body .= '<div class="wps-row">' . $act . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the form escapes its own output.
		}
		return Ui::card( __( 'Updates', 'wpseokit' ), '', $body );
	}
}
