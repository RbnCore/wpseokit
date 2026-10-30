<?php
/**
 * Feed (RSS) tool: main feed, content shown in each item, and the extra feeds to close.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class FeedScreen {

	public static function init(): void {
		Shell::register(
			'feed',
			static fn(): string => __( 'Feed (RSS)', 'wpseokit' ),
			'rss',
			static fn(): string => __( 'Choose what your RSS feed shows and close the feeds nobody needs.', 'wpseokit' ),
			40,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		$top = '';
		if ( ! (bool) Ui::val( 'feed.main_enabled', true ) ) {
			$top = Ui::note( 'info', '<b>' . esc_html__( 'The main feed is off.', 'wpseokit' ) . '</b> ' . esc_html__( 'Feed readers and aggregators cannot follow your new posts.', 'wpseokit' ) );
		}
		Shell::page(
			'feed',
			__( 'Feed (RSS)', 'wpseokit' ),
			array(
				'general' => array( __( 'General', 'wpseokit' ), self::general() ),
				'content' => array( __( 'Content', 'wpseokit' ), self::content() ),
				'cleanup' => array( __( 'Cleanup', 'wpseokit' ), self::cleanup() ),
			),
			array(
				'form' => 'feed',
				'aria' => __( 'Feed sections', 'wpseokit' ),
				'top'  => $top,
			)
		);
	}

	private static function general(): string {
		$url   = get_feed_link();
		$body  = Ui::toggle_row( __( 'Status', 'wpseokit' ), 'feed.main_enabled', 'fd-on', __( 'Publish the main feed', 'wpseokit' ), __( 'Readers and some search tools use it to find new posts.', 'wpseokit' ) );
		$copy  = '<div class="wps-copy"><input class="rbn-input rbn-input--mono" id="fd-url" value="' . esc_attr( $url ) . '" readonly aria-label="' . esc_attr__( 'Feed address', 'wpseokit' ) . '">';
		$copy .= '<button type="button" class="rbn-btn" data-copy="fd-url">' . Ui::icon( 'copy' ) . esc_html__( 'Copy', 'wpseokit' ) . '</button>';
		$copy .= '<a class="rbn-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . Ui::icon( 'out' ) . esc_html__( 'Open', 'wpseokit' ) . '</a></div>';
		$copy .= '<p class="rbn-help" data-feed-check>' . esc_html__( 'Checking the feed…', 'wpseokit' ) . '</p>';
		$body .= Ui::row( Ui::label( 'fd-url', __( 'Feed address', 'wpseokit' ) ), $copy );
		$body .= Ui::seg(
			'feed.content_mode',
			'fd-mode',
			__( 'Each item shows', 'wpseokit' ),
			array(
				'inherit' => __( 'WordPress setting', 'wpseokit' ),
				'full'    => __( 'Full text', 'wpseokit' ),
				'summary' => __( 'Summary', 'wpseokit' ),
			),
			__( 'Summary sends readers to your site to read the rest. Full text keeps them in their reader.', 'wpseokit' )
		);
		$body .= Ui::text( 'feed.items_count', 'fd-n', __( 'Items in the feed', 'wpseokit' ), array( 'type' => 'number', 'min' => 0, 'max' => 50, 'unit' => __( 'items', 'wpseokit' ), 'help' => __( 'Between 1 and 50. Use 0 to keep the WordPress setting.', 'wpseokit' ) ) );
		return Ui::card( __( 'Main feed', 'wpseokit' ), __( 'The feed readers and aggregators subscribe to.', 'wpseokit' ), $body );
	}

	private static function content(): string {
		$body  = Ui::toggle_row( __( 'Source link', 'wpseokit' ), 'feed.source_link_enabled', 'fd-src', __( 'Add a link back to the original post', 'wpseokit' ), __( 'Scrapers that copy your feed also copy the link.', 'wpseokit' ) );
		$body .= Ui::text( 'feed.footer_text', 'fd-foot', __( 'Footer text', 'wpseokit' ), array( 'counter' => true, 'vars' => array( '%title%', '%permalink%', '%sitename%', '%author%', '%date%' ) ) );
		$body .= Ui::toggle_row( __( 'Copyright', 'wpseokit' ), 'feed.copyright_enabled', 'fd-cr', __( 'Add a copyright line', 'wpseokit' ), __( 'Printed under the footer text.', 'wpseokit' ) );
		$body .= Ui::text( 'feed.copyright_text', 'fd-crt', __( 'Copyright text', 'wpseokit' ) );
		$body .= Ui::toggle_row( __( 'Featured image', 'wpseokit' ), 'feed.featured_image_enabled', 'fd-img', __( 'Include the featured image in each item', 'wpseokit' ) );
		$body .= Ui::row( esc_html__( 'Example item', 'wpseokit' ), '<pre class="wps-pre" tabindex="0" aria-label="' . esc_attr__( 'Example feed item', 'wpseokit' ) . '" data-feed-sample>' . esc_html__( 'Loading…', 'wpseokit' ) . '</pre>' );
		return Ui::card( __( 'Source link and copyright', 'wpseokit' ), __( 'Added to the end of every item so copied text points back to you.', 'wpseokit' ), $body );
	}

	private static function cleanup(): string {
		$close = '<div class="wps-switchlist">';
		foreach ( array(
			'feed.disable_comments_feed'      => array( __( 'Comments feed', 'wpseokit' ), __( 'All comments of the site.', 'wpseokit' ) ),
			'feed.disable_post_comments_feed' => array( __( 'Comments feed of each post', 'wpseokit' ), __( 'One feed for every post.', 'wpseokit' ) ),
			'feed.disable_author_feed'        => array( __( 'Author feeds', 'wpseokit' ), __( 'One feed for each author.', 'wpseokit' ) ),
			'feed.disable_taxonomy_feed'      => array( __( 'Category and tag feeds', 'wpseokit' ), __( 'One feed for each category and tag.', 'wpseokit' ) ),
			'feed.disable_search_feed'        => array( __( 'Search result feeds', 'wpseokit' ), __( 'Feeds of search queries.', 'wpseokit' ) ),
			'feed.disable_attachment_feed'    => array( __( 'Attachment feeds', 'wpseokit' ), __( 'Feeds of media pages.', 'wpseokit' ) ),
		) as $path => $t ) {
			$close .= Ui::toggle( $path, 'fc-' . str_replace( '.', '-', $path ), $t[0], $t[1] );
		}
		$close .= '</div>';
		$body   = Ui::row( esc_html__( 'Close these feeds', 'wpseokit' ), $close, esc_html__( 'WordPress opens many feeds by default. Most sites need one.', 'wpseokit' ) );
		$body  .= Ui::toggle_row( __( 'Closed feeds', 'wpseokit' ), 'feed.redirect_closed', 'fc-red', __( 'Send visitors of a closed feed to its page (301)', 'wpseokit' ), __( 'Off = a closed feed answers 404.', 'wpseokit' ) );
		$body  .= Ui::toggle_row( __( 'Search engines', 'wpseokit' ), 'feed.noindex_feed', 'fc-noidx', __( 'Tell search engines not to index feeds', 'wpseokit' ) );
		$body  .= Ui::toggle_row( __( 'Page head', 'wpseokit' ), 'feed.clean_head_links', 'fc-head', __( 'Remove extra feed links from the page head', 'wpseokit' ) );
		return Ui::card( __( 'Feeds to close', 'wpseokit' ), __( 'Turn on a switch to close that kind of feed.', 'wpseokit' ), $body );
	}
}
