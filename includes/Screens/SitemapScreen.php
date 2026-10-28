<?php
/**
 * Sitemap tool: switch and address, content types, generated files and the robots.txt line.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class SitemapScreen {

	public static function init(): void {
		Shell::register(
			'sitemap',
			static fn(): string => __( 'Sitemap', 'wpseokit' ),
			'sitemap',
			static fn(): string => __( 'A list of your pages that search engines read first.', 'wpseokit' ),
			30,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		$top = '';
		if ( ! (bool) Ui::val( 'sitemap.enabled', true ) ) {
			$top = Ui::note( 'info', '<b>' . esc_html__( 'The XML sitemap is off.', 'wpseokit' ) . '</b> ' . esc_html__( 'Search engines have no list of your pages.', 'wpseokit' ) );
		}
		Shell::page(
			'sitemap',
			__( 'Sitemap', 'wpseokit' ),
			array(
				'general' => array( __( 'General', 'wpseokit' ), self::general() ),
				'content' => array( __( 'Content types', 'wpseokit' ), self::content() ),
				'files'   => array( __( 'Files', 'wpseokit' ), self::files() ),
				'robots'  => array( __( 'robots.txt', 'wpseokit' ), self::robots() ),
			),
			array(
				'form' => 'sitemap',
				'aria' => __( 'Sitemap sections', 'wpseokit' ),
				'top'  => $top,
			)
		);
	}

	private static function general(): string {
		$url   = home_url( '/sitemap_index.xml' );
		$main  = Ui::toggle_row( __( 'Status', 'wpseokit' ), 'sitemap.enabled', 'sm-on', __( 'Enable the XML sitemap', 'wpseokit' ), __( 'Keeps the same addresses Yoast used, so search engines see no change.', 'wpseokit' ) );
		$copy  = '<div class="wps-copy"><input class="rbn-input rbn-input--mono" id="sm-url" value="' . esc_attr( $url ) . '" readonly aria-label="' . esc_attr__( 'Sitemap address', 'wpseokit' ) . '">';
		$copy .= '<button type="button" class="rbn-btn" data-copy="sm-url">' . Ui::icon( 'copy' ) . esc_html__( 'Copy', 'wpseokit' ) . '</button>';
		$copy .= '<a class="rbn-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . Ui::icon( 'out' ) . esc_html__( 'Open', 'wpseokit' ) . '</a></div>';
		$copy .= '<div class="rbn-row"><button type="button" class="rbn-btn" data-sitemap-flush>' . Ui::icon( 'scan' ) . esc_html__( 'Rebuild now', 'wpseokit' ) . '</button></div>';
		$main .= Ui::row( Ui::label( 'sm-url', __( 'Sitemap address', 'wpseokit' ) ), $copy, esc_html__( 'The sitemap rebuilds by itself on a schedule and when you publish.', 'wpseokit' ) );
		return Ui::card( __( 'XML sitemap', 'wpseokit' ), '', $main );
	}

	private static function content(): string {
		$body  = Ui::row( esc_html__( 'Post types', 'wpseokit' ), Ui::multi( 'sitemap.post_types', 'sm-pt', Ui::post_types() ) );
		$body .= Ui::row( esc_html__( 'Taxonomies', 'wpseokit' ), Ui::multi( 'sitemap.taxonomies', 'sm-tx', Ui::taxonomies() ) );
		$body .= Ui::toggle_row( __( 'Images', 'wpseokit' ), 'sitemap.include_images', 'sm-img', __( 'List featured images with each post', 'wpseokit' ), __( 'Helps image search find your posters and stills.', 'wpseokit' ) );
		$body .= Ui::text( 'sitemap.per_page', 'sm-max', __( 'URLs per file', 'wpseokit' ), array( 'type' => 'number', 'min' => 100, 'max' => 2000, 'step' => 100, 'unit' => __( 'URLs', 'wpseokit' ), 'help' => __( 'Between 100 and 2,000. Lower this if your server times out.', 'wpseokit' ) ) );
		$body .= Ui::text( 'sitemap.cache_hours', 'sm-cache', __( 'Rebuild every', 'wpseokit' ), array( 'type' => 'number', 'min' => 1, 'max' => 72, 'unit' => __( 'hours', 'wpseokit' ) ) );
		return Ui::card( __( 'Content in the sitemap', 'wpseokit' ), '', $body );
	}

	private static function files(): string {
		$table = '<div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack" data-sitemap-files><caption class="rbn-sr">' . esc_html__( 'Sitemap files', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'File', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'URLs', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Last modified', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wpseokit' ) . '</th></tr></thead><tbody><tr><td colspan="4" data-empty>' . esc_html__( 'Loading…', 'wpseokit' ) . '</td></tr></tbody></table></div></div>';
		return '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Generated files', 'wpseokit' ) . '</h2><p>' . esc_html__( 'What search engines download from your site.', 'wpseokit' ) . '</p></div>' . $table . '</section>';
	}

	private static function robots(): string {
		$line = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
		$body = Ui::row(
			esc_html__( 'Line in robots.txt', 'wpseokit' ),
			'<pre class="wps-pre" tabindex="0" aria-label="' . esc_attr__( 'Sitemap line in robots.txt', 'wpseokit' ) . '">' . esc_html( $line ) . '</pre>',
			esc_html__( 'Added by itself while the sitemap is on, unless the site asks search engines not to index it.', 'wpseokit' )
		);
		$body .= Ui::row( esc_html__( 'WordPress sitemap', 'wpseokit' ), '<span class="rbn-badge rbn-badge--ok">' . Ui::icon( 'check' ) . esc_html__( 'Turned off', 'wpseokit' ) . '</span>', esc_html__( 'WordPress ships its own /wp-sitemap.xml. WPSeoKit turns it off so search engines see one sitemap, not two.', 'wpseokit' ) );
		return Ui::card( __( 'robots.txt and WordPress sitemap', 'wpseokit' ), '', $body );
	}
}
