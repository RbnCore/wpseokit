<?php
/**
 * Post SEO box: title and description with counters, search preview, primary category, social, schema and advanced.
 * Saved with the normal post save (nonce `wpseokit_metabox`, capability `edit_post`); nothing is printed on the front end.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use Wpseokit\Plugin;

defined( 'ABSPATH' ) || exit;

final class MetaBox {

	private const NONCE_ACTION = 'wpseokit_metabox';
	private const NONCE_FIELD  = 'wpseokit_metabox_nonce';
	private const SCHEMA_TYPES = array( 'Article', 'Movie', 'TVSeries' );

	public static function init(): void {
		Ui::boot();
		add_action( 'add_meta_boxes', array( self::class, 'register' ) );
		add_action( 'save_post', array( self::class, 'save' ), 10, 2 );
	}

	public static function register(): void {
		if ( Plugin::yoast_active() ) {
			return;
		}
		foreach ( array_keys( Ui::post_types() ) as $type ) {
			add_meta_box( 'wpseokit', __( 'SEO', 'wpseokit' ), array( self::class, 'render' ), $type, 'normal', 'high' );
		}
	}

	/** @return array<int, string> */
	private static function vars(): array {
		return array( '%title%', '%sitename%', '%sep%', '%category%', '%primary_category%', '%excerpt%' );
	}

	private static function lim( string $id, string $min_good_max, string $label ): string {
		return '<div class="wps-lim" id="' . esc_attr( $id ) . '" data-level="ok" data-lim-for="' . esc_attr( $min_good_max ) . '"><div class="rbn-progress" role="presentation"><i style="--w:0%"></i></div><output aria-live="polite" aria-label="' . esc_attr( $label ) . '">0</output></div>';
	}

	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$m = static fn( string $key ): string => (string) get_post_meta( $post->ID, '_wpseokit_' . $key, true );

		$robots = $m( 'robots' );
		$schema = json_decode( $m( 'schema_data' ), true );
		$schema = is_array( $schema ) ? $schema : array();
		$cast   = isset( $schema['cast'] ) && is_array( $schema['cast'] ) ? implode( ', ', array_map( 'strval', $schema['cast'] ) ) : '';
		$type   = $m( 'schema_type' );
		$tpl    = (array) Ui::val( 'templates.post_types.' . $post->post_type, array() );
		$plain  = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$path   = (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );

		ob_start();
		Ui::sprite();
		echo (string) ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own fixed file.

		$o  = '<div class="rbn-wrap rbn-wrap--box wpseokit-box" data-metabox' . Ui::tone_attr();
		$o .= ' data-site="' . esc_attr( (string) get_bloginfo( 'name' ) ) . '" data-host="' . esc_attr( $host ) . '" data-path="' . esc_attr( $path ) . '"';
		$o .= ' data-sep="' . esc_attr( (string) Ui::val( 'templates.sep', '-' ) ) . '" data-tpl-title="' . esc_attr( (string) ( $tpl['title'] ?? '' ) ) . '" data-tpl-desc="' . esc_attr( (string) ( $tpl['description'] ?? '' ) ) . '"';
		$o .= ' data-excerpt="' . esc_attr( wp_html_excerpt( (string) $post->post_excerpt, 300 ) ) . '" data-content="' . esc_attr( wp_html_excerpt( $plain, 300 ) ) . '" data-post-id="' . (int) $post->ID . '">';

		$tabs = array(
			'seo'    => array( __( 'SEO', 'wpseokit' ), 'seo' ),
			'social' => array( __( 'Social', 'wpseokit' ), 'share' ),
			'schema' => array( __( 'Schema', 'wpseokit' ), 'code' ),
			'adv'    => array( __( 'Advanced', 'wpseokit' ), 'gear' ),
		);
		$o   .= '<div class="wps-tabs" role="tablist" aria-label="' . esc_attr__( 'WPSeoKit sections', 'wpseokit' ) . '">';
		$first = true;
		foreach ( $tabs as $id => $t ) {
			$o    .= '<button type="button" role="tab" id="wps-tab-' . $id . '" aria-controls="wps-pn-' . $id . '" aria-selected="' . ( $first ? 'true' : 'false' ) . '" tabindex="' . ( $first ? '0' : '-1' ) . '">' . Ui::icon( $t[1] ) . esc_html( $t[0] ) . '</button>';
			$first = false;
		}
		$o .= '</div>';

		$o .= '<div role="tabpanel" id="wps-pn-seo" aria-labelledby="wps-tab-seo" tabindex="0">' . self::panel_seo( $post, $m ) . '</div>';
		$o .= '<div role="tabpanel" id="wps-pn-social" aria-labelledby="wps-tab-social" hidden tabindex="0">' . self::panel_social( $m ) . '</div>';
		$o .= '<div role="tabpanel" id="wps-pn-schema" aria-labelledby="wps-tab-schema" hidden tabindex="0">' . self::panel_schema( $type, $schema, $cast ) . '</div>';
		$o .= '<div role="tabpanel" id="wps-pn-adv" aria-labelledby="wps-tab-adv" hidden tabindex="0">' . self::panel_advanced( $m( 'canonical' ), $robots ) . '</div>';
		$o .= '</div>';
		echo $o; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment is escaped where it is built.
	}

	/** @param callable $m Reads one `_wpseokit_*` meta value. */
	private static function panel_seo( \WP_Post $post, callable $m ): string {
		$o  = '<div class="wps-serp-wrap"><div class="wps-serp-wrap__bar"><h3>' . esc_html__( 'Search preview', 'wpseokit' ) . '</h3>';
		$o .= '<fieldset class="rbn-seg"><legend class="rbn-sr">' . esc_html__( 'Preview device', 'wpseokit' ) . '</legend><label><input type="radio" name="wps-serp-mode" value="d" checked><span>' . Ui::icon( 'doc' ) . esc_html__( 'Desktop', 'wpseokit' ) . '</span></label><label><input type="radio" name="wps-serp-mode" value="m"><span>' . Ui::icon( 'phone' ) . esc_html__( 'Mobile', 'wpseokit' ) . '</span></label></fieldset></div>';
		$o .= '<div class="wps-serp" data-serp><div class="wps-serp__site"><span class="wps-serp__fav">' . Ui::icon( 'film' ) . '</span><span class="wps-serp__who"><b data-serp-site></b><span class="wps-serp__url" data-serp-url></span></span></div>';
		$o .= '<a class="wps-serp__t" href="#" tabindex="-1" aria-hidden="true" data-serp-title></a><p class="wps-serp__d" data-serp-desc></p></div></div>';

		$o .= '<div class="rbn-stack">';
		$o .= '<div class="rbn-field" style="grid-template-columns:1fr"><div class="rbn-field__l"><label for="wps-title">' . esc_html__( 'SEO title', 'wpseokit' ) . '</label></div><div class="rbn-field__c">';
		$o .= '<input class="rbn-input rbn-input--mono wps-wide" id="wps-title" name="wpseokit[title]" type="text" value="' . esc_attr( $m( 'title' ) ) . '" placeholder="%title% %sep% %sitename%" data-var-target data-lim="30,60,70" data-meter="wps-lim-t" aria-describedby="wps-lim-t wps-title-h">';
		$o .= Ui::vars( 'wps-title', self::vars() ) . self::lim( 'wps-lim-t', '30,60,70', __( 'Title length', 'wpseokit' ) );
		$o .= '<p class="rbn-help" id="wps-title-h">' . esc_html__( 'Aim for 30 to 60 characters. Search engines cut by width, not by count.', 'wpseokit' ) . '</p></div></div>';

		$o .= '<div class="rbn-field" style="grid-template-columns:1fr"><div class="rbn-field__l"><label for="wps-desc">' . esc_html__( 'Meta description', 'wpseokit' ) . '</label></div><div class="rbn-field__c">';
		$o .= '<textarea class="rbn-textarea wps-wide" id="wps-desc" name="wpseokit[description]" rows="3" data-var-target data-lim="70,155,170" data-meter="wps-lim-d" aria-describedby="wps-lim-d wps-desc-h">' . esc_textarea( $m( 'description' ) ) . '</textarea>';
		$o .= self::lim( 'wps-lim-d', '70,155,170', __( 'Description length', 'wpseokit' ) );
		$o .= '<p class="rbn-help" id="wps-desc-h">' . esc_html__( 'Aim for 70 to 155 characters. Empty falls back to the template, then the excerpt.', 'wpseokit' ) . '</p>';
		$o .= '<span class="wps-src" data-src>' . Ui::icon( 'doc' ) . '<span></span></span></div></div>';

		$o .= self::primary( $post, $m( 'primary_category' ) ) . '</div>';
		return $o;
	}

	private static function primary( \WP_Post $post, string $current ): string {
		if ( ! (bool) Ui::val( 'general.primary_category', true ) || ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			return '';
		}
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 300, 'fields' => 'all' ) );
		$map   = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$map[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
			}
		}
		$assigned = wp_get_post_categories( $post->ID );
		$o        = '<div class="rbn-field" style="grid-template-columns:1fr"><div class="rbn-field__l"><span id="wps-pc-l">' . esc_html__( 'Primary category', 'wpseokit' ) . '</span></div><div class="rbn-field__c">';
		$o       .= '<fieldset class="wps-primary" data-primary data-current="' . (int) $current . '" data-assigned="' . esc_attr( (string) wp_json_encode( array_map( 'intval', $assigned ) ) ) . '" data-terms="' . esc_attr( (string) wp_json_encode( $map ) ) . '"><legend class="rbn-sr">' . esc_html__( 'Primary category', 'wpseokit' ) . '</legend></fieldset>';
		$o       .= '<input type="hidden" name="wpseokit[primary_category]" value="' . (int) $current . '" data-primary-input>';
		$o       .= '<input type="hidden" name="wpseokit[primary_original]" value="' . (int) $current . '">';
		$o       .= '<p class="wps-urlprev" data-urlprev hidden><span>' . esc_html__( 'URL:', 'wpseokit' ) . '</span><code></code></p>';
		$o       .= '<div class="rbn-note rbn-note--warn" data-url-warn hidden>' . Ui::icon( 'alert' ) . '<div><p>' . esc_html__( 'Changing the primary category changes this post’s URL.', 'wpseokit' ) . '</p><label class="rbn-switch"><input type="checkbox" name="wpseokit[add_301]" value="1" checked><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Add a 301 redirect from the old URL', 'wpseokit' ) . '</b></span></label></div></div>';
		$o       .= '<p class="rbn-help">' . esc_html__( 'The primary category sets this post’s URL and breadcrumb.', 'wpseokit' ) . '</p></div></div>';
		return $o;
	}

	/** @param callable $m Reads one `_wpseokit_*` meta value. */
	private static function panel_social( callable $m ): string {
		$img = $m( 'og_image' );
		$o   = '<div class="rbn-stack"><div class="wps-og" data-og><div class="wps-og__img" data-og-img>' . Ui::icon( 'image' ) . '</div><div class="wps-og__t"><small data-og-host></small><b data-og-title></b><span data-og-desc></span></div></div>';
		$o  .= Ui::row( Ui::label( 'wps-og-t', __( 'Sharing title', 'wpseokit' ) ), '<input class="rbn-input" id="wps-og-t" name="wpseokit[og_title]" type="text" value="' . esc_attr( $m( 'og_title' ) ) . '" placeholder="' . esc_attr__( 'Same as the SEO title', 'wpseokit' ) . '">', esc_html__( 'Leave empty to use the SEO title.', 'wpseokit' ) );
		$o  .= Ui::row( Ui::label( 'wps-og-d', __( 'Sharing description', 'wpseokit' ) ), '<textarea class="rbn-textarea wps-wide" id="wps-og-d" name="wpseokit[og_description]" rows="2">' . esc_textarea( $m( 'og_description' ) ) . '</textarea>', esc_html__( 'Leave empty to use the meta description.', 'wpseokit' ) );
		$media  = '<div class="wps-media" data-media="wps-og-i"><div class="wps-media__img' . ( '' !== $img ? ' wps-media__img--filled' : '' ) . '">' . ( '' !== $img ? '<img src="' . esc_url( $img ) . '" alt="">' : Ui::icon( 'image' ) ) . '</div><div class="rbn-row">';
		$media .= '<button type="button" class="rbn-btn" data-media-pick data-media-url-only>' . Ui::icon( 'image' ) . esc_html__( 'Choose image', 'wpseokit' ) . '</button><button type="button" class="rbn-btn rbn-btn--danger" data-media-clear' . ( '' === $img ? ' hidden' : '' ) . '>' . Ui::icon( 'trash' ) . esc_html__( 'Remove', 'wpseokit' ) . '</button></div>';
		$media .= '<input type="hidden" name="wpseokit[og_image]" value="' . esc_attr( $img ) . '" data-media-url></div>';
		$o     .= Ui::row( esc_html__( 'Sharing image', 'wpseokit' ), $media, esc_html__( 'Empty: the featured image is used.', 'wpseokit' ) );
		return $o . '</div>';
	}

	/** @param array<string, mixed> $schema Decoded `_wpseokit_schema_data`. */
	private static function panel_schema( string $type, array $schema, string $cast ): string {
		$opts = '<option value="">' . esc_html__( 'Article (default)', 'wpseokit' ) . '</option>';
		foreach ( array( 'Movie' => __( 'Movie', 'wpseokit' ), 'TVSeries' => __( 'TV series', 'wpseokit' ) ) as $v => $l ) {
			$opts .= '<option value="' . esc_attr( $v ) . '"' . selected( $type, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		$o  = '<div class="rbn-stack">';
		$o .= Ui::row( Ui::label( 'wps-st', __( 'Schema type', 'wpseokit' ) ), '<select class="rbn-select" id="wps-st" name="wpseokit[schema_type]" data-schema-type>' . $opts . '</select>', esc_html__( 'Movie and TVSeries need the details below. Without them the post stays an Article.', 'wpseokit' ) );
		$o .= '<div class="rbn-stack" data-schema-fields>';
		$o .= Ui::row( Ui::label( 'wps-dir', __( 'Director', 'wpseokit' ) ), '<input class="rbn-input" id="wps-dir" name="wpseokit[schema][director]" type="text" value="' . esc_attr( (string) ( $schema['director'] ?? '' ) ) . '">' );
		$o .= Ui::row( Ui::label( 'wps-cast', __( 'Cast', 'wpseokit' ) ), '<input class="rbn-input" id="wps-cast" name="wpseokit[schema][cast]" type="text" value="' . esc_attr( $cast ) . '">', esc_html__( 'Separate names with commas.', 'wpseokit' ) );
		$o .= Ui::row( Ui::label( 'wps-yr', __( 'Year', 'wpseokit' ) ), '<span class="rbn-unit"><input class="rbn-input rbn-input--s" id="wps-yr" name="wpseokit[schema][year]" type="number" min="1888" max="2100" value="' . esc_attr( (string) ( $schema['year'] ?? '' ) ) . '"></span>' );
		$o .= '</div></div>';
		return $o;
	}

	private static function panel_advanced( string $canonical, string $robots ): string {
		$noindex  = false !== strpos( $robots, 'noindex' );
		$nofollow = false !== strpos( $robots, 'nofollow' );
		$sw       = '<div class="rbn-switchlist">';
		$sw      .= '<label class="rbn-switch"><input type="checkbox" id="wps-ni" name="wpseokit[noindex]" value="1"' . checked( $noindex, true, false ) . '><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Hide this post from search results', 'wpseokit' ) . '</b><span class="rbn-help">' . esc_html__( 'Adds noindex. The post also leaves the sitemap.', 'wpseokit' ) . '</span></span></label>';
		$sw      .= '<label class="rbn-switch"><input type="checkbox" id="wps-nf" name="wpseokit[nofollow]" value="1"' . checked( $nofollow, true, false ) . '><span class="rbn-switch__t"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Do not follow links on this post', 'wpseokit' ) . '</b><span class="rbn-help">' . esc_html__( 'Adds nofollow. Rarely needed.', 'wpseokit' ) . '</span></span></label></div>';
		$o        = '<div class="rbn-stack">';
		$o       .= Ui::row( Ui::label( 'wps-can', __( 'Canonical URL', 'wpseokit' ) ), '<input class="rbn-input rbn-input--mono" id="wps-can" name="wpseokit[canonical]" type="url" value="' . esc_attr( $canonical ) . '" placeholder="https://">', esc_html__( 'Leave empty to use the post address. Only fill this when the same content lives elsewhere.', 'wpseokit' ) );
		$o       .= Ui::row( esc_html__( 'Search engines', 'wpseokit' ), $sw );
		return $o . '</div>';
	}

	/** Saves the box. @param \WP_Post $post The saved post. */
	public static function save( int $post_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['wpseokit'] ) || ! is_array( $_POST['wpseokit'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key is cleaned below.
		$in  = wp_unslash( $_POST['wpseokit'] );
		$get = static fn( string $k ): string => isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';

		self::put( $post_id, 'title', sanitize_text_field( $get( 'title' ) ) );
		self::put( $post_id, 'description', sanitize_textarea_field( $get( 'description' ) ) );
		self::put( $post_id, 'canonical', esc_url_raw( $get( 'canonical' ) ) );
		self::put( $post_id, 'og_title', sanitize_text_field( $get( 'og_title' ) ) );
		self::put( $post_id, 'og_description', sanitize_textarea_field( $get( 'og_description' ) ) );
		self::put( $post_id, 'og_image', esc_url_raw( $get( 'og_image' ) ) );

		$robots = array( '' !== $get( 'noindex' ) ? 'noindex' : 'index', '' !== $get( 'nofollow' ) ? 'nofollow' : 'follow' );
		self::put( $post_id, 'robots', 'index,follow' === implode( ',', $robots ) ? '' : implode( ',', $robots ) );

		$type = $get( 'schema_type' );
		self::put( $post_id, 'schema_type', in_array( $type, self::SCHEMA_TYPES, true ) && 'Article' !== $type ? $type : '' );
		$data = isset( $in['schema'] ) && is_array( $in['schema'] ) ? $in['schema'] : array();
		$cast = array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', explode( ',', (string) ( $data['cast'] ?? '' ) ) ) ) ) );
		$json = array_filter(
			array(
				'director' => sanitize_text_field( (string) ( $data['director'] ?? '' ) ),
				'cast'     => $cast,
				'year'     => (int) ( $data['year'] ?? 0 ) > 0 ? (int) $data['year'] : 0,
			)
		);
		self::put( $post_id, 'schema_data', $json ? (string) wp_json_encode( $json ) : '' );

		$new = (int) $get( 'primary_category' );
		$old = (int) get_post_meta( $post_id, '_wpseokit_primary_category', true );
		if ( $new > 0 && ! has_term( $new, 'category', $post_id ) ) {
			$new = 0;
		}
		self::put( $post_id, 'primary_category', $new > 0 ? (string) $new : '' );
		if ( $new !== $old ) {
			/**
			 * Fires when the primary category of a post changes, so the redirect service can add a 301 from the old URL.
			 *
			 * @param int  $post_id Post id.
			 * @param int  $old     Previous term id (0 when none).
			 * @param int  $new     New term id (0 when none).
			 * @param bool $add_301 Whether the editor asked for a redirect.
			 */
			do_action( 'wpseokit_primary_category_changed', $post_id, $old, $new, '' !== $get( 'add_301' ) );
		}
	}

	private static function put( int $post_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, '_wpseokit_' . $key );
			return;
		}
		update_post_meta( $post_id, '_wpseokit_' . $key, wp_slash( $value ) );
	}
}
