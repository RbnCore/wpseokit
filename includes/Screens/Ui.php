<?php
/**
 * Admin UI helpers shared by every screen: kit assets, boot data, icons and form-row builders.
 * Output of each helper is escaped here; callers only concatenate finished fragments.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use Wpseokit\Admin;
use Wpseokit\Plugin;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class Ui {

	private static bool $booted      = false;
	private static bool $sprite_done = false;

	/** Hooks the asset loader once, whichever screen starts first. */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
	}

	/** Whether the current admin request is a post edit screen the SEO box lives on. */
	private static function is_editor( string $hook ): bool {
		return in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && ! Plugin::yoast_active();
	}

	/** Loads the kit stylesheet, the screen script and its data on the plugin page and the post editor only. */
	public static function enqueue( string $hook ): void {
		$page   = Admin::is_page( $hook );
		$editor = self::is_editor( $hook );
		if ( ! $page && ! $editor ) {
			return;
		}
		wp_enqueue_style( 'wpseokit-kit', WPSEOKIT_URL . 'assets/admin/rbn-admin-ui.css', array(), WPSEOKIT_VERSION );
		wp_enqueue_style( 'wpseokit-ui', WPSEOKIT_URL . 'assets/admin/wpseokit-ui.css', array( 'wpseokit-kit' ), WPSEOKIT_VERSION );
		wp_enqueue_script( 'wpseokit-ui', WPSEOKIT_URL . 'assets/admin/wpseokit-admin.js', array(), WPSEOKIT_VERSION, array( 'in_footer' => true ) );
		wp_add_inline_script( 'wpseokit-ui', 'window.wpseokitUi = ' . wp_json_encode( self::data( $editor ) ) . ';', 'before' );
	}

	/**
	 * Data handed to the screen script.
	 *
	 * @return array<string, mixed>
	 */
	private static function data( bool $editor ): array {
		return array(
			'restUrl'  => esc_url_raw( rest_url( Rest::NAMESPACE . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'pageUrl'  => Admin::page_url(),
			'tone'     => self::tone(),
			'editor'   => $editor,
			'siteName' => (string) get_bloginfo( 'name' ),
			'siteHost' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'i18n'     => self::strings(),
		);
	}

	/** @return array<string, string> */
	private static function strings(): array {
		return array(
			'saved'          => __( 'Settings saved.', 'wpseokit' ),
			'saveFailed'     => __( 'Settings were not saved.', 'wpseokit' ),
			'saving'         => __( 'Saving…', 'wpseokit' ),
			'loading'        => __( 'Loading…', 'wpseokit' ),
			'loadFailed'     => __( 'This list could not be loaded.', 'wpseokit' ),
			'retry'          => __( 'Try again', 'wpseokit' ),
			'demo'           => __( 'Demo data: the server part of this screen is not installed yet, so nothing here is saved.', 'wpseokit' ),
			'on'             => __( 'On', 'wpseokit' ),
			'off'            => __( 'Off', 'wpseokit' ),
			'edit'           => __( 'Edit', 'wpseokit' ),
			'delete'         => __( 'Delete', 'wpseokit' ),
			'save'           => __( 'Save', 'wpseokit' ),
			'cancel'         => __( 'Cancel', 'wpseokit' ),
			'close'          => __( 'Close', 'wpseokit' ),
			'today'          => __( 'Today', 'wpseokit' ),
			'never'          => __( 'Never', 'wpseokit' ),
			'noRedirects'    => __( 'No redirects yet. Add the first one on the Add tab.', 'wpseokit' ),
			'noMatch'        => __( 'Nothing matches this search.', 'wpseokit' ),
			'no404'          => __( 'No 404 errors logged. Visitors are finding your pages.', 'wpseokit' ),
			'added'          => __( 'Redirect added.', 'wpseokit' ),
			'updated'        => __( 'Redirect updated.', 'wpseokit' ),
			'removed'        => __( 'Redirect deleted.', 'wpseokit' ),
			'created404'     => __( 'Redirect created.', 'wpseokit' ),
			'undo'           => __( 'Undo', 'wpseokit' ),
			'fromRequired'   => __( 'Enter the old path, starting with a slash.', 'wpseokit' ),
			'toRequired'     => __( 'Enter the new address, or choose type 410.', 'wpseokit' ),
			/* translators: %d: number of selected rows. */
			'deleteN'        => __( 'Delete %d redirects?', 'wpseokit' ),
			/* translators: %d: number of selected rows. */
			'deleteNBtn'     => __( 'Delete %d redirects', 'wpseokit' ),
			'deleteBody'     => __( 'Visitors who use these old addresses will see a 404 page again. This cannot be undone, but you can add the redirects back.', 'wpseokit' ),
			'keep'           => __( 'Keep them', 'wpseokit' ),
			/* translators: %d: number of redirects. */
			'importN'        => __( 'Import %d redirects', 'wpseokit' ),
			'csvChecked'     => __( 'checked', 'wpseokit' ),
			'csvNeedsFile'   => __( 'Choose a CSV file first.', 'wpseokit' ),
			'line'           => __( 'Line', 'wpseokit' ),
			'redirectTo'     => __( 'Redirect to…', 'wpseokit' ),
			'bestMatch'      => __( 'Best match', 'wpseokit' ),
			'redirected'     => __( 'Redirect', 'wpseokit' ),
			'rebuilt'        => __( 'Sitemap rebuilt.', 'wpseokit' ),
			'rebuilding'     => __( 'Rebuilding the sitemap…', 'wpseokit' ),
			'copied'         => __( 'Copied', 'wpseokit' ),
			'previewTitle'   => __( 'Gentle Shadows', 'wpseokit' ),
			'chooseImage'    => __( 'Choose image', 'wpseokit' ),
			'replaceImage'   => __( 'Replace image', 'wpseokit' ),
			'sourceHere'     => __( 'Source: written here', 'wpseokit' ),
			'sourceTemplate' => __( 'Source: title template', 'wpseokit' ),
			'sourceExcerpt'  => __( 'Source: excerpt', 'wpseokit' ),
			'sourceContent'  => __( 'Source: start of the content', 'wpseokit' ),
			'limGood'        => __( 'Good length', 'wpseokit' ),
			'limShort'       => __( 'Too short', 'wpseokit' ),
			'limLong'        => __( 'Getting long', 'wpseokit' ),
			'limOver'        => __( 'Too long: it will be cut', 'wpseokit' ),
			'urlChange'      => __( 'Changing the primary category changes this post’s URL.', 'wpseokit' ),
			'urlAdd301'      => __( 'Add a 301 redirect from the old URL', 'wpseokit' ),
			'checkRunning'   => __( 'Checking your Yoast data…', 'wpseokit' ),
			'importRunning'  => __( 'Importing…', 'wpseokit' ),
			'importDone'     => __( 'Import finished.', 'wpseokit' ),
			'importUndone'   => __( 'Import undone.', 'wpseokit' ),
			'found'          => __( 'Found', 'wpseokit' ),
			'willImport'     => __( 'Will import', 'wpseokit' ),
			'skipped'        => __( 'Skipped', 'wpseokit' ),
			'conflicts'      => __( 'Conflicts', 'wpseokit' ),
			/* translators: %d: number of redirects. */
			'cardRedirects'  => __( '%d active redirects', 'wpseokit' ),
			/* translators: %d: number of logged addresses. */
			'cardLog'        => __( '%d errors logged', 'wpseokit' ),
			'needsLook'      => __( 'Needs a look', 'wpseokit' ),
			'clean'          => __( 'Clean', 'wpseokit' ),
			'event'          => __( 'Event', 'wpseokit' ),
			'hits'           => __( 'Hits', 'wpseokit' ),
			'action'         => __( 'Action', 'wpseokit' ),
			'fix'            => __( 'Fix', 'wpseokit' ),
			'noSchema'       => __( 'This page prints no schema.', 'wpseokit' ),
			'feedOn'         => __( 'The feed is on and answers at this address.', 'wpseokit' ),
			'feedOff'        => __( 'The feed is off.', 'wpseokit' ),
			'noPosts'        => __( 'There is no published post to show.', 'wpseokit' ),
			'verify'         => __( 'Verify', 'wpseokit' ),
			'verified'       => __( 'Verified.', 'wpseokit' ),
		);
	}

	/** Prints the icon sprite once per request. */
	public static function sprite(): void {
		if ( self::$sprite_done ) {
			return;
		}
		self::$sprite_done = true;
		$file              = WPSEOKIT_DIR . 'assets/admin/sprite.svg';
		if ( is_readable( $file ) ) {
			echo (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own fixed file.
		}
	}

	public static function icon( string $name ): string {
		return '<svg class="rbn-ico" aria-hidden="true"><use href="#i-' . esc_attr( $name ) . '"/></svg>';
	}

	/** Dark tone: WP "Midnight" colour scheme or a dark-mode plugin; can be overridden by a filter. */
	public static function tone(): string {
		$dark = 'midnight' === (string) get_user_option( 'admin_color' ) || defined( 'WP_DARK_MODE_VERSION' ) || defined( 'WP_DARK_MODE_PLUGIN_FILE' );
		$tone = (string) apply_filters( 'wpseokit_tone', $dark ? 'koyu' : 'acik' );
		return 'koyu' === $tone ? 'koyu' : 'acik';
	}

	public static function tone_attr(): string {
		return 'koyu' === self::tone() ? ' data-tone="koyu"' : '';
	}

	/** Opens a screen: sprite, loading mark and a live-region for results. */
	public static function open( string $screen ): string {
		ob_start();
		self::sprite();
		return (string) ob_get_clean() . '<div class="wps-screen" data-screen="' . esc_attr( $screen ) . '"' . self::tone_attr() . '><div class="wps-live-region" role="status" aria-live="polite" data-result></div>';
	}

	public static function close(): string {
		return '</div>';
	}

	/** @param string $type ok|warn|err|info */
	public static function note( string $type, string $html, string $role = '' ): string {
		$icons = array( 'ok' => 'check', 'warn' => 'alert', 'err' => 'x', 'info' => 'info' );
		$attr  = '' !== $role ? ' role="' . esc_attr( $role ) . '"' : '';
		return '<div class="rbn-note rbn-note--' . esc_attr( $type ) . '"' . $attr . '>' . self::icon( $icons[ $type ] ?? 'info' ) . '<div><p>' . $html . '</p></div></div>';
	}

	public static function card( string $title, string $desc, string $body, string $extra = '' ): string {
		$head = '<div class="rbn-card__h"><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $desc ? '<p>' . esc_html( $desc ) . '</p>' : '' ) . '</div>';
		return '<section class="rbn-card"' . $extra . '>' . $head . '<div class="rbn-card__b">' . $body . '</div></section>';
	}

	/** One label + control row. */
	public static function row( string $label_html, string $control, string $help = '', string $help_id = '' ): string {
		$h = '' !== $help ? '<p class="rbn-help"' . ( '' !== $help_id ? ' id="' . esc_attr( $help_id ) . '"' : '' ) . '>' . $help . '</p>' : '';
		return '<div class="rbn-field"><div class="rbn-field__l">' . $label_html . '</div><div class="rbn-field__c">' . $control . $h . '</div></div>';
	}

	public static function label( string $id, string $text ): string {
		return '<label for="' . esc_attr( $id ) . '">' . esc_html( $text ) . '</label>';
	}

	/** Setting value by dotted path (`templates.sep`). @param mixed $default */
	public static function val( string $path, $default = '' ) {
		return SettingsRepository::get( $path, $default );
	}

	/**
	 * Text field bound to a setting path.
	 *
	 * @param array<string, mixed> $o help, mono, placeholder, textarea, rows, vars(array), type, min, max, step, list(bool)
	 */
	public static function text( string $path, string $id, string $label, array $o = array() ): string {
		$value = self::val( $path, '' );
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_map( 'strval', $value ) );
		}
		$help_id = '' !== (string) ( $o['help'] ?? '' ) ? $id . '-h' : '';
		$attrs   = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $path ) . '"';
		$attrs  .= '' !== $help_id ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '';
		$attrs  .= isset( $o['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $o['placeholder'] ) . '"' : '';
		$attrs  .= ! empty( $o['vars'] ) ? ' data-var-target' : '';
		$attrs  .= ! empty( $o['list'] ) ? ' data-type="list"' : '';
		if ( ! empty( $o['textarea'] ) ) {
			$ctl = '<textarea class="rbn-textarea wps-wide"' . $attrs . ' rows="' . (int) ( $o['rows'] ?? 3 ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			$type = (string) ( $o['type'] ?? 'text' );
			if ( 'number' === $type ) {
				$attrs .= ' data-type="int" min="' . (int) ( $o['min'] ?? 0 ) . '" max="' . (int) ( $o['max'] ?? 0 ) . '" step="' . (int) ( $o['step'] ?? 1 ) . '"';
			}
			$cls = 'rbn-input' . ( ! empty( $o['mono'] ) ? ' rbn-input--mono' : '' ) . ( 'number' === $type ? ' rbn-input--s' : '' );
			$ctl = '<input class="' . $cls . '" type="' . esc_attr( $type ) . '"' . $attrs . ' value="' . esc_attr( (string) $value ) . '">';
		}
		if ( ! empty( $o['unit'] ) ) {
			$ctl = '<span class="rbn-unit">' . $ctl . '<span>' . esc_html( (string) $o['unit'] ) . '</span></span>';
		}
		if ( ! empty( $o['vars'] ) ) {
			$ctl .= self::vars( $id, (array) $o['vars'] );
			$ctl .= '<p class="wps-live" data-preview-for="' . esc_attr( $id ) . '"></p>';
		}
		return self::row( self::label( $id, $label ), $ctl, (string) ( $o['help'] ?? '' ), $help_id );
	}

	/** "Insert a variable" disclosure. @param array<int, string> $tokens */
	public static function vars( string $target, array $tokens, bool $open = false ): string {
		$out = '<details class="wps-vars" data-vars-for="' . esc_attr( $target ) . '"' . ( $open ? ' open' : '' ) . '><summary>' . self::icon( 'plus' ) . esc_html__( 'Insert a variable', 'wpseokit' ) . '</summary><div class="rbn-toks">';
		foreach ( $tokens as $t ) {
			$out .= '<button type="button" class="rbn-tok" data-insert="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</button>';
		}
		return $out . '</div></details>';
	}

	/** Switch bound to a boolean setting. */
	public static function toggle( string $path, string $id, string $title, string $help = '', bool $disabled = false ): string {
		$on = (bool) self::val( $path, false );
		return '<label class="rbn-switch"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $path ) . '" data-type="bool"' . ( $on ? ' checked' : '' ) . ( $disabled ? ' disabled' : '' ) . '><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html( $title ) . '</b>' . ( '' !== $help ? '<span class="rbn-help">' . esc_html( $help ) . '</span>' : '' ) . '</span></label>';
	}

	/** Switch inside a field row. */
	public static function toggle_row( string $row_label, string $path, string $id, string $title, string $help = '', bool $disabled = false ): string {
		return self::row( esc_html( $row_label ), self::toggle( $path, $id, $title, $help, $disabled ) );
	}

	/**
	 * Segmented radio group bound to a setting path.
	 *
	 * @param array<string, string> $options value => label
	 */
	public static function seg( string $path, string $id, string $label, array $options, string $help = '' ): string {
		$cur = (string) self::val( $path, '' );
		$out = '<fieldset class="rbn-seg" aria-labelledby="' . esc_attr( $id ) . '-l">';
		foreach ( $options as $value => $text ) {
			$out .= '<label><input type="radio" name="' . esc_attr( $path ) . '" value="' . esc_attr( (string) $value ) . '"' . checked( $cur, (string) $value, false ) . '><span>' . esc_html( $text ) . '</span></label>';
		}
		$out .= '</fieldset>';
		return self::row( '<span id="' . esc_attr( $id ) . '-l">' . esc_html( $label ) . '</span>', $out, $help );
	}

	/**
	 * A set of switches that write one list setting (`robots.noindex_post_types`).
	 *
	 * @param array<string, string> $items value => label
	 */
	public static function multi( string $path, string $id, array $items ): string {
		$cur = array_map( 'strval', (array) self::val( $path, array() ) );
		$out = '<div class="rbn-switchlist">';
		$i   = 0;
		foreach ( $items as $value => $text ) {
			$out .= '<label class="rbn-switch"><input type="checkbox" id="' . esc_attr( $id . '-' . $i ) . '" name="' . esc_attr( $path ) . '" value="' . esc_attr( (string) $value ) . '" data-multi' . ( in_array( (string) $value, $cur, true ) ? ' checked' : '' ) . '><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html( $text ) . '</b></span></label>';
			++$i;
		}
		return $out . '</div>';
	}

	/** Image chooser bound to an id + url setting pair. */
	public static function media( string $id_path, string $url_path, string $id, string $help = '' ): string {
		$url   = (string) self::val( $url_path, '' );
		$img   = '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="">' : self::icon( 'image' );
		$ctl   = '<div class="wps-media" data-media="' . esc_attr( $id ) . '"><div class="wps-media__img' . ( '' !== $url ? ' wps-media__img--filled' : '' ) . '">' . $img . '</div><div class="rbn-row">';
		$ctl  .= '<button type="button" class="rbn-btn" data-media-pick>' . self::icon( 'image' ) . esc_html( '' !== $url ? __( 'Replace image', 'wpseokit' ) : __( 'Choose image', 'wpseokit' ) ) . '</button>';
		$ctl  .= '<button type="button" class="rbn-btn rbn-btn--danger" data-media-clear' . ( '' === $url ? ' hidden' : '' ) . '>' . self::icon( 'trash' ) . esc_html__( 'Remove', 'wpseokit' ) . '</button></div>';
		$ctl  .= '<input type="hidden" name="' . esc_attr( $id_path ) . '" value="' . (int) self::val( $id_path, 0 ) . '" data-type="int" data-media-id>';
		$ctl  .= '<input type="hidden" name="' . esc_attr( $url_path ) . '" value="' . esc_attr( $url ) . '" data-media-url></div>';
		return $ctl . ( '' !== $help ? '<p class="rbn-help">' . esc_html( $help ) . '</p>' : '' );
	}

	/** Sticky save bar shown only while a form has unsaved changes. */
	public static function bar(): string {
		return '<div class="rbn-bar" data-bar><p>' . esc_html__( 'You have unsaved changes.', 'wpseokit' ) . '</p><div class="rbn-bar__act"><button type="button" class="rbn-btn" data-discard>' . esc_html__( 'Discard', 'wpseokit' ) . '</button><button type="submit" class="rbn-btn rbn-btn--pri" data-save>' . self::icon( 'save' ) . esc_html__( 'Save changes', 'wpseokit' ) . '</button></div></div>';
	}

	/** Wraps cards in a settings form handled by the shared script. */
	public static function form( string $name, string $inner ): string {
		return '<form class="wps-form" data-settings-form="' . esc_attr( $name ) . '" novalidate>' . $inner . self::bar() . '</form>';
	}

	/** Loading skeleton block. */
	public static function skeleton( int $rows = 3 ): string {
		$row = '<div class="wps-skel-row"><span class="wps-skel"></span><span class="wps-skel"></span><span class="wps-skel wps-skel--w70"></span><span class="wps-skel wps-skel--w50"></span></div>';
		return '<div class="wps-skeleton" aria-busy="true"><p class="wps-busy" role="status"><span class="wps-spin" aria-hidden="true"></span>' . esc_html__( 'Loading…', 'wpseokit' ) . '</p><section class="rbn-card" aria-hidden="true"><div class="rbn-card__h"><span class="wps-skel wps-skel--w30"></span></div><div class="wps-skel-rows">' . str_repeat( $row, $rows ) . '</div></section></div>';
	}

	/** Public post types the SEO features apply to. @return array<string, string> name => label */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' !== $pt->name ) {
				$out[ $pt->name ] = (string) $pt->labels->name;
			}
		}
		return $out;
	}

	/** Public taxonomies. @return array<string, string> name => label */
	public static function taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[ $tax->name ] = (string) $tax->labels->name;
		}
		return $out;
	}
}
