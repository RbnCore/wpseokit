<?php
/**
 * llms.txt tool: publish switch and summary, what to list, and a preview of the file.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class LlmsScreen {

	public static function init(): void {
		Shell::register(
			'llms',
			static fn(): string => __( 'llms.txt', 'wpseokit' ),
			'bot',
			static fn(): string => __( 'A plain-text guide to your site for AI assistants.', 'wpseokit' ),
			50,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		$top = '';
		if ( ! (bool) Ui::val( 'llms.enabled', false ) ) {
			$top = Ui::note( 'info', '<b>' . esc_html__( 'llms.txt is off.', 'wpseokit' ) . '</b> ' . esc_html__( 'AI assistants have no short guide to your site. Check the preview before you turn it on.', 'wpseokit' ) );
		}
		Shell::page(
			'llms',
			__( 'llms.txt', 'wpseokit' ),
			array(
				'general' => array( __( 'General', 'wpseokit' ), self::general() ),
				'content' => array( __( 'Content', 'wpseokit' ), self::content() ),
				'preview' => array( __( 'Preview', 'wpseokit' ), self::preview() ),
			),
			array(
				'form' => 'llms',
				'aria' => __( 'llms.txt sections', 'wpseokit' ),
				'top'  => $top,
			)
		);
	}

	private static function general(): string {
		$url   = home_url( '/llms.txt' );
		$body  = Ui::toggle_row( __( 'Status', 'wpseokit' ), 'llms.enabled', 'll-on', __( 'Publish llms.txt', 'wpseokit' ), __( 'Off by default. Turn it on after you read the preview.', 'wpseokit' ) );
		$copy  = '<div class="wps-copy"><input class="rbn-input rbn-input--mono" id="ll-url" value="' . esc_attr( $url ) . '" readonly aria-label="' . esc_attr__( 'File address', 'wpseokit' ) . '">';
		$copy .= '<button type="button" class="rbn-btn" data-copy="ll-url">' . Ui::icon( 'copy' ) . esc_html__( 'Copy', 'wpseokit' ) . '</button>';
		$copy .= '<a class="rbn-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . Ui::icon( 'out' ) . esc_html__( 'Open', 'wpseokit' ) . '</a></div>';
		$body .= Ui::row( Ui::label( 'll-url', __( 'File address', 'wpseokit' ) ), $copy );
		$body .= Ui::text( 'llms.summary', 'll-sum', __( 'Site summary', 'wpseokit' ), array( 'counter' => true, 'help' => __( 'One or two sentences. Plain text, no markup. Leave empty to use the site tagline.', 'wpseokit' ) ) );
		return Ui::card( __( 'llms.txt file', 'wpseokit' ), __( 'A plain-text page that summarizes your site for AI assistants.', 'wpseokit' ), $body );
	}

	private static function content(): string {
		$pages = array();
		foreach ( get_pages( array( 'number' => 50, 'sort_column' => 'post_title' ) ) as $page ) {
			$pages[ (string) $page->ID ] = (string) $page->post_title;
		}
		$body  = Ui::row( esc_html__( 'Pages', 'wpseokit' ), $pages ? Ui::multi( 'llms.pages', 'lc-pages', $pages ) : '<p class="rbn-help">' . esc_html__( 'There are no published pages yet.', 'wpseokit' ) . '</p>', esc_html__( 'Pick the pages assistants should know about. Fewer, better links work best.', 'wpseokit' ) );
		$body .= Ui::row( esc_html__( 'Latest content', 'wpseokit' ), Ui::multi( 'llms.post_types', 'lc-pt', Ui::post_types() ) );
		$body .= Ui::text( 'llms.latest', 'lc-n', __( 'Items per type', 'wpseokit' ), array( 'type' => 'number', 'min' => 0, 'max' => 200, 'help' => __( 'Between 0 and 200. Use 0 for no automatic list.', 'wpseokit' ) ) );
		$body .= Ui::row( esc_html__( 'Categories and tags', 'wpseokit' ), Ui::multi( 'llms.taxonomies', 'lc-tx', Ui::taxonomies() ) );
		$body .= Ui::text( 'llms.max_terms', 'lc-mt', __( 'Most terms per section', 'wpseokit' ), array( 'type' => 'number', 'min' => 0, 'max' => 200 ) );
		$body .= Ui::text( 'llms.exclude', 'lc-ex', __( 'Leave out (IDs)', 'wpseokit' ), array( 'textarea' => true, 'rows' => 2, 'list' => true, 'mono' => true, 'help' => __( 'Post or page IDs, one per line. They never appear in the file.', 'wpseokit' ) ) );
		return Ui::card( __( 'What to list', 'wpseokit' ), __( 'Pick the pages assistants should know about. Fewer, better links work best.', 'wpseokit' ), $body );
	}

	private static function preview(): string {
		$body = Ui::row(
			esc_html__( 'File', 'wpseokit' ),
			'<pre class="wps-pre" tabindex="0" aria-label="' . esc_attr__( 'llms.txt preview', 'wpseokit' ) . '" data-llms-out>' . esc_html__( 'Loading…', 'wpseokit' ) . '</pre><div class="wps-row"><button type="button" class="rbn-btn" data-llms-refresh>' . Ui::icon( 'scan' ) . esc_html__( 'Refresh preview', 'wpseokit' ) . '</button><button type="button" class="rbn-btn" data-copy="" data-llms-copy>' . Ui::icon( 'copy' ) . esc_html__( 'Copy', 'wpseokit' ) . '</button></div>',
			esc_html__( 'Built from the values on this page, even before you save. It works while the file is still off.', 'wpseokit' )
		);
		return Ui::card( __( 'Preview', 'wpseokit' ), __( 'This is what /llms.txt would return.', 'wpseokit' ), $body );
	}
}
