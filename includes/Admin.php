<?php
/**
 * Admin shell: RBN Kit menu entry, tab navigation, notices and the Status tab (docs/sozlesme.md §5).
 *
 * Tabs of other packages register through the `wpseokit_admin_tabs` filter; this class draws only the shell and
 * the Status tab. While Yoast SEO is active only the Status tab and the warning are shown.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const SLUG = 'wpseokit';

	/** Screen id of the plugin page (rbn-kit_page_wpseokit); empty until the menu is built. */
	private static string $hook = '';

	/** @var array<int, array{type:string,msg:string}> */
	private static array $notices = array();

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'menu' ), 8 );
		add_action( 'admin_menu', array( self::class, 'late' ), 99 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'yoast_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPSEOKIT_FILE ), array( self::class, 'links' ) );
	}

	/**
	 * Page address.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 */
	public static function page_url( array $args = array() ): string {
		return \AdminKit\Helpers\Menu::page_url( self::SLUG, $args );
	}

	/**
	 * Queues a notice shown at the top of the plugin page.
	 *
	 * @param string $type One of success, error, warning, info.
	 */
	public static function notice( string $type, string $msg ): void {
		$type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
		self::$notices[] = array(
			'type' => $type,
			'msg'  => $msg,
		);
	}

	/**
	 * @param array<int, string> $links Action links.
	 * @return array<int, string>
	 */
	public static function links( array $links ): array {
		return \AdminKit\Helpers\Menu::action_link( $links, self::page_url(), __( 'Settings', 'wpseokit' ) );
	}

	/** Registers the page under the single RBN Kit top-level menu. */
	public static function menu(): void {
		\AdminKit\Helpers\Menu::add(
			array(
				'slug'       => self::SLUG,
				'title'      => __( 'WPSeoKit — SEO Toolkit', 'wpseokit' ),
				'menu_title' => __( 'SEO Toolkit', 'wpseokit' ),
				'cap'        => 'manage_options',
				'callback'   => array( self::class, 'render' ),
				'version'    => WPSEOKIT_VERSION,
			)
		);
	}

	/** Reads the screen id once the menu is built. */
	public static function late(): void {
		self::$hook = \AdminKit\Helpers\Menu::hook( self::SLUG );
	}

	/** Whether the current admin screen is the plugin page. */
	public static function is_page( string $hook ): bool {
		return '' !== self::$hook && $hook === self::$hook;
	}

	/** Loads the small shell stylesheet and the boot data on the plugin page only. */
	public static function enqueue( string $hook ): void {
		if ( ! self::is_page( $hook ) ) {
			return;
		}
		wp_register_style( 'wpseokit-admin', false, array(), WPSEOKIT_VERSION );
		wp_enqueue_style( 'wpseokit-admin' );
		wp_add_inline_style(
			'wpseokit-admin',
			'.wpseokit-wrap .wpseokit-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:16px 0;max-width:820px}'
			. '.wpseokit-wrap .wpseokit-card h2{margin-top:0}'
			. '.wpseokit-wrap .wpseokit-dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0 0 12px}'
			. '.wpseokit-wrap .wpseokit-dl dt{font-weight:600}.wpseokit-wrap .wpseokit-dl dd{margin:0}'
			. '.wpseokit-wrap .wpseokit-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}'
			. '.wpseokit-wrap .wpseokit-help{color:#50575e}'
		);
		wp_register_script( 'wpseokit-boot', '', array(), WPSEOKIT_VERSION, array( 'in_footer' => false ) );
		wp_enqueue_script( 'wpseokit-boot' );
		wp_add_inline_script( 'wpseokit-boot', 'window.wpseokit = ' . wp_json_encode( self::boot_data() ) . ';', 'before' );
	}

	/**
	 * Data handed to the admin scripts as `window.wpseokit`.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_data(): array {
		$data = array(
			'restUrl'  => esc_url_raw( rest_url( Rest::NAMESPACE . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'version'  => WPSEOKIT_VERSION,
			'pageUrl'  => self::page_url(),
			'yoast'    => Plugin::yoast_active(),
			'locale'   => determine_locale(),
		);
		$filtered = apply_filters( 'wpseokit_admin_data', $data );
		return is_array( $filtered ) ? $filtered : $data;
	}

	/** Warning shown on every admin screen for administrators while Yoast SEO is active. */
	public static function yoast_notice(): void {
		if ( ! Plugin::yoast_active() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::is_page( $screen->id ) ) {
			return; // The page shows the warning inside its own shell.
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( self::yoast_message() ) . '</p></div>';
	}

	private static function yoast_message(): string {
		return __( 'Yoast SEO is active. WPSeoKit prints nothing while it is active, so the two never output duplicate tags. Remove Yoast SEO first, then use WPSeoKit.', 'wpseokit' );
	}

	/**
	 * Tabs: id => label, cap, priority, callback. Includes the Status tab drawn here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tabs(): array {
		$tabs = array(
			'status' => array(
				'label'    => __( 'Status', 'wpseokit' ),
				'cap'      => 'manage_options',
				'priority' => 100,
				'callback' => array( self::class, 'render_status' ),
			),
		);
		if ( Plugin::output_enabled() ) {
			$filtered = apply_filters( 'wpseokit_admin_tabs', $tabs );
			$tabs     = is_array( $filtered ) ? $filtered : $tabs;
		}
		$tabs = array_filter(
			$tabs,
			static fn( $tab ): bool => is_array( $tab ) && isset( $tab['callback'] ) && current_user_can( (string) ( $tab['cap'] ?? 'manage_options' ) )
		);
		uasort(
			$tabs,
			static fn( array $a, array $b ): int => (int) ( $a['priority'] ?? 50 ) <=> (int) ( $b['priority'] ?? 50 )
		);
		return $tabs;
	}

	/** Page callback: shell, notices, navigation and the active tab. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to open this page.', 'wpseokit' ), '', array( 'response' => 403 ) );
		}
		if ( has_action( 'wpseokit_render_page' ) ) { // The dashboard and tool pages (Screens\Shell) draw their own shell.
			do_action( 'wpseokit_render_page' );
			return;
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a tab name only.
		$wanted = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$active = isset( $tabs[ $wanted ] ) ? $wanted : (string) array_key_first( $tabs );
		$class  = (string) apply_filters( 'wpseokit_admin_nav_class', 'nav-tab-wrapper' );

		echo '<div class="wrap rbn-wrap wpseokit-wrap"><h1>' . esc_html__( 'WPSeoKit — SEO Toolkit', 'wpseokit' ) . '</h1>';
		self::print_note();
		if ( Plugin::yoast_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( self::yoast_message() ) . '</p></div>';
		}
		foreach ( self::$notices as $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' inline"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
		}
		echo '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'WPSeoKit sections', 'wpseokit' ) . '">';
		foreach ( $tabs as $id => $tab ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$id === $active ? ' nav-tab-active' : '',
				esc_url( self::page_url( array( 'tab' => (string) $id ) ) ),
				esc_html( (string) $tab['label'] )
			);
		}
		echo '</nav>';
		if ( isset( $tabs[ $active ] ) ) {
			call_user_func( $tabs[ $active ]['callback'] );
		}
		echo '</div>';
	}

	/** Shows the result message after an update action (`wk_note`). */
	private static function print_note(): void {
		echo self::note_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the message is escaped below.
	}

	/** Result message after an update action (`wk_note`) as a notice, or an empty string. */
	public static function note_html(): string {
		$messages = array(
			'update_checked' => array( 'success', __( 'Update check finished.', 'wpseokit' ) ),
			'update_is_off'  => array( 'warning', __( 'The update check is off; nothing was checked.', 'wpseokit' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a message key only; it is matched against the list above.
		$key = isset( $_GET['wk_note'] ) ? sanitize_key( wp_unslash( $_GET['wk_note'] ) ) : '';
		// Result of the central updater's "Check now" (AdminKit): checked | off.
		$upd = \AdminKit\Core\Updater::note();
		if ( null !== $upd ) {
			$key = 'checked' === $upd ? 'update_checked' : 'update_is_off';
		}
		if ( ! isset( $messages[ $key ] ) ) {
			return '';
		}
		return '<div class="notice notice-' . esc_attr( $messages[ $key ][0] ) . ' inline" role="status"><p>' . esc_html( $messages[ $key ][1] ) . '</p></div>';
	}

	/** The Status tab: version, schema, Yoast state and the updates card. */
	public static function render_status(): void {
		$rows = array(
			__( 'Plugin version', 'wpseokit' )  => WPSEOKIT_VERSION,
			__( 'Database schema', 'wpseokit' ) => (string) (int) get_option( Db::OPTION, 0 ),
			__( 'Output', 'wpseokit' )          => Plugin::output_enabled() ? __( 'On', 'wpseokit' ) : __( 'Off (Yoast SEO is active)', 'wpseokit' ),
		);
		echo '<div class="wpseokit-card"><h2>' . esc_html__( 'Status', 'wpseokit' ) . '</h2><dl class="wpseokit-dl">';
		foreach ( $rows as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl></div>';
		echo '<div class="wpseokit-card"><h2>' . esc_html__( 'Updates', 'wpseokit' ) . '</h2>';
		echo self::update_card_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the body escapes its own output.
		echo '</div>';
	}

	/** Body of the "Updates" card: state comes from the central updater (AdminKit), which also checks and verifies. */
	private static function update_card_body(): string {
		$st   = \AdminKit\Core\Updater::status( 'wpseokit' );
		$last = (int) $st['checked_at'] > 0 ? esc_html( wp_date( 'd.m.Y H:i', (int) $st['checked_at'] ) ) : esc_html__( 'never', 'wpseokit' );
		$html = '<dl class="wpseokit-dl"><dt>' . esc_html__( 'Installed version', 'wpseokit' ) . '</dt><dd>' . esc_html( WPSEOKIT_VERSION ) . '</dd>'
			. '<dt>' . esc_html__( 'Status', 'wpseokit' ) . '</dt><dd>' . \AdminKit\Core\Updater::state_html( $st ) . '</dd>'
			. '<dt>' . esc_html__( 'Last check', 'wpseokit' ) . '</dt><dd>' . $last . '</dd></dl>'
			. '<p class="wpseokit-help"><a href="' . esc_url( admin_url( 'admin.php?page=adminkit#adminkit-network' ) ) . '">' . esc_html__( 'Update checks follow the RBN Kit network setting.', 'wpseokit' ) . '</a></p>';
		$form = \AdminKit\Core\Updater::check_form( 'wpseokit', self::page_url( array( 'tab' => 'status' ) ), esc_html__( 'Check now', 'wpseokit' ) );
		return '' === $form ? $html : $html . '<div class="wpseokit-actions">' . $form . '</div>';
	}
}
