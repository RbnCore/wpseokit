<?php
/**
 * Feed (RSS) tool: content mode, items count, source link and copyright footer,
 * closing unnecessary feeds (404/301), X-Robots-Tag header, head link cleanup.
 *
 * Safe defaults: by default all settings leave WordPress' native behaviour unchanged,
 * ensuring the `/feed/` output SHA256 is 100% identical before and after.
 *
 * Policy foundations:
 * - google-arama/rss-besleme.md (RSS 2.0, canonical links, pubDate RFC 822, unique guid, title/description, charset=UTF-8)
 * - google-arama/tarih-kurallari.md (pubDate from datePublished in RFC 822 format; lastBuildDate RFC 822)
 * - google-arama/kanonik-url.md (canonical absolute URLs matching page canonical)
 * - araclar-ve-botlar/robots-meta-ve-x-robots-tag.md (X-Robots-Tag: noindex, follow header for XML feeds)
 *
 * Contract: docs/sozlesme.md §2.1, §4.5, §6.
 * Architecture: docs/kapsam-ve-mimari.md §3.8.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Services;

use AdminKit\Core\Security\Guard;

use Wpseokit\Plugin;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;

defined( 'ABSPATH' ) || exit;

final class FeedService {

	public const FEED_SLUG = 'feed';

	/**
	 * Registers all hooks and filters.
	 */
	public static function init(): void {
		if ( ! Plugin::output_enabled() ) {
			return;
		}

		// Handle closed feeds and X-Robots-Tag header early.
		add_action( 'template_redirect', array( self::class, 'handle_feed_request' ), 1 );

		// Header filter for WordPress HTTP responses.
		add_filter( 'wp_headers', array( self::class, 'filter_headers' ) );

		// Content mode (full vs summary) and items count.
		add_filter( 'pre_option_rss_use_excerpt', array( self::class, 'filter_rss_use_excerpt' ) );
		add_filter( 'pre_option_posts_per_rss', array( self::class, 'filter_posts_per_rss' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_feed_query' ) );

		// Content enrichment: source link, copyright, featured image.
		add_filter( 'the_content_feed', array( self::class, 'filter_content_feed' ), 20, 2 );
		add_filter( 'the_excerpt_rss', array( self::class, 'filter_excerpt_feed' ), 20 );

		// <head> feed links cleanup.
		add_action( 'wp_head', array( self::class, 'maybe_clean_head_links' ), 1 );

		// REST routes and status filter.
		add_action( 'wpseokit_register_rest_routes', array( self::class, 'register_routes' ) );
		add_filter( 'wpseokit_status', array( self::class, 'filter_status' ) );
	}

	/**
	 * Public URL of the main feed.
	 */
	public static function url(): string {
		return home_url( '/feed/' );
	}

	/* ------------------------------------------------------------------ request & closed feeds */

	/**
	 * Determines if the current feed request is disabled by settings.
	 * Returns the feed kind string if disabled, or null if open/allowed.
	 */
	public static function is_feed_disabled(): ?string {
		if ( ! is_feed() ) {
			return null;
		}

		$cfg = SettingsRepository::section( 'feed' );

		// 1. Single post comments feed.
		if ( is_comment_feed() && is_singular() ) {
			if ( ! empty( $cfg['disable_post_comments_feed'] ) || ! empty( $cfg['disable_comments_feed'] ) ) {
				return 'post_comments';
			}
			return null;
		}

		// 2. Site-wide comments feed.
		if ( is_comment_feed() && ! is_singular() ) {
			if ( ! empty( $cfg['disable_comments_feed'] ) ) {
				return 'comments';
			}
			return null;
		}

		// 3. Author feed.
		if ( is_author() ) {
			if ( ! empty( $cfg['disable_author_feed'] ) ) {
				return 'author';
			}
			return null;
		}

		// 4. Taxonomy feed (category, tag, custom taxonomy).
		if ( is_category() || is_tag() || is_tax() ) {
			if ( ! empty( $cfg['disable_taxonomy_feed'] ) ) {
				return 'taxonomy';
			}
			return null;
		}

		// 5. Search feed.
		if ( is_search() ) {
			if ( ! empty( $cfg['disable_search_feed'] ) ) {
				return 'search';
			}
			return null;
		}

		// 6. Attachment feed.
		if ( is_attachment() ) {
			if ( ! empty( $cfg['disable_attachment_feed'] ) ) {
				return 'attachment';
			}
			return null;
		}

		// 7. Main posts feed.
		if ( ! is_singular() && isset( $cfg['main_enabled'] ) && ! (bool) $cfg['main_enabled'] ) {
			return 'main';
		}

		return null;
	}

	/**
	 * Resolves the 301 canonical redirect target for a closed feed.
	 */
	public static function get_redirect_target( string $feed_type ): string {
		switch ( $feed_type ) {
			case 'post_comments':
			case 'attachment':
				$post_id = get_queried_object_id();
				if ( $post_id > 0 ) {
					$target = get_permalink( $post_id );
					if ( is_string( $target ) && '' !== $target ) {
						return $target;
					}
				}
				break;

			case 'author':
				$author_id = (int) get_query_var( 'author' );
				if ( $author_id > 0 ) {
					$target = get_author_posts_url( $author_id );
					if ( is_string( $target ) && '' !== $target ) {
						return $target;
					}
				}
				break;

			case 'taxonomy':
				$obj = get_queried_object();
				if ( $obj instanceof \WP_Term ) {
					$target = get_term_link( $obj );
					if ( is_string( $target ) && '' !== $target ) {
						return $target;
					}
				}
				break;

			case 'search':
				$s = (string) get_query_var( 's' );
				if ( '' !== $s ) {
					return home_url( '/?s=' . rawurlencode( $s ) );
				}
				break;

			case 'comments':
			case 'main':
			default:
				return home_url( '/' );
		}

		return home_url( '/' );
	}

	/**
	 * `template_redirect` (priority 1): handles closed feeds (404/301)
	 * and adds the X-Robots-Tag header on open feeds if enabled.
	 */
	public static function handle_feed_request(): void {
		if ( ! is_feed() ) {
			return;
		}

		$disabled_type = self::is_feed_disabled();
		if ( null === $disabled_type ) {
			// Feed is allowed. Emit X-Robots-Tag header if enabled.
			if ( (bool) SettingsRepository::get( 'feed.noindex_feed', false ) && ! headers_sent() ) {
				header( 'X-Robots-Tag: noindex, follow' );
			}
			return;
		}

		// Feed is disabled.
		$redirect = (bool) SettingsRepository::get( 'feed.redirect_closed', false );
		if ( $redirect ) {
			$target = self::get_redirect_target( $disabled_type );
			wp_safe_redirect( $target, 301 );
			exit;
		}

		// Serve 404 response.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		$template = get_404_template();
		if ( $template && is_readable( $template ) ) {
			include $template;
		}
		exit;
	}

	/**
	 * Filter on `wp_headers`: adds `X-Robots-Tag: noindex, follow` on open feeds.
	 *
	 * @param array<string, string> $headers Headers array.
	 * @return array<string, string>
	 */
	public static function filter_headers( array $headers ): array {
		if ( is_feed() && (bool) SettingsRepository::get( 'feed.noindex_feed', false ) ) {
			$headers['X-Robots-Tag'] = 'noindex, follow';
		}
		return $headers;
	}

	/* ------------------------------------------------------------------ content mode & query */

	/**
	 * Overrides `rss_use_excerpt` option during feed requests.
	 *
	 * @param mixed $value Incoming option value.
	 * @return mixed
	 */
	public static function filter_rss_use_excerpt( $value ) {
		if ( ! is_feed() ) {
			return false;
		}
		$mode = (string) SettingsRepository::get( 'feed.content_mode', 'inherit' );
		if ( 'full' === $mode ) {
			return 0;
		}
		if ( 'summary' === $mode ) {
			return 1;
		}
		return false;
	}

	/**
	 * Overrides `posts_per_rss` option during feed requests.
	 *
	 * @param mixed $value Incoming option value.
	 * @return mixed
	 */
	public static function filter_posts_per_rss( $value ) {
		if ( ! is_feed() ) {
			return false;
		}
		$count = (int) SettingsRepository::get( 'feed.items_count', 0 );
		if ( $count > 0 ) {
			return $count;
		}
		return false;
	}

	/**
	 * Adjusts query limits for feed queries if `items_count` is configured.
	 *
	 * @param \WP_Query $query Main query.
	 */
	public static function filter_feed_query( \WP_Query $query ): void {
		if ( ! is_admin() && $query->is_main_query() && $query->is_feed() ) {
			$count = (int) SettingsRepository::get( 'feed.items_count', 0 );
			if ( $count > 0 ) {
				$query->set( 'posts_per_rss', $count );
				$query->set( 'posts_per_page', $count );
			}
		}
	}

	/* ------------------------------------------------------------------ content enrichment */

	/**
	 * Resolves template variables for feed items.
	 * Supported: %title%, %permalink%, %sitename%, %sitedesc%, %author%, %date%, %siteurl%.
	 */
	public static function resolve_variables( string $template, \WP_Post $post ): string {
		$vars = array(
			'%permalink%' => esc_url( (string) get_permalink( $post ) ),
			'%siteurl%'   => esc_url( home_url( '/' ) ),
			'%author%'    => esc_html( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
			'%title%'     => esc_html( (string) $post->post_title ),
			'%sitename%'  => esc_html( (string) get_option( 'blogname' ) ),
			'%sitedesc%'  => esc_html( (string) get_option( 'blogdescription' ) ),
			'%date%'      => esc_html( (string) mysql2date( (string) get_option( 'date_format' ), $post->post_date ) ),
		);
		$text = strtr( $template, $vars );
		return Variables::replace( $text, array( 'post' => $post, 'type' => 'post' ) );
	}

	/**
	 * Builds source link and copyright lines for a post.
	 */
	public static function build_item_footer( \WP_Post $post ): string {
		$parts = array();

		// Source link.
		if ( (bool) SettingsRepository::get( 'feed.source_link_enabled', false ) ) {
			$tpl = (string) SettingsRepository::get( 'feed.footer_text', '' );
			if ( '' !== trim( $tpl ) ) {
				$rendered = self::resolve_variables( $tpl, $post );
				if ( '' !== $rendered ) {
					$parts[] = '<p>' . $rendered . '</p>';
				}
			}
		}

		// Copyright line.
		if ( (bool) SettingsRepository::get( 'feed.copyright_enabled', false ) ) {
			$tpl = (string) SettingsRepository::get( 'feed.copyright_text', '' );
			if ( '' !== trim( $tpl ) ) {
				$rendered = self::resolve_variables( $tpl, $post );
				if ( '' !== $rendered ) {
					$parts[] = '<p><small>' . $rendered . '</small></p>';
				}
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Filters full post content in RSS/Atom feeds.
	 *
	 * @param string $content   Original content.
	 * @param string $feed_type Feed type (rss2, atom, etc).
	 */
	public static function filter_content_feed( string $content, string $feed_type = '' ): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		$prefix = '';
		if ( (bool) SettingsRepository::get( 'feed.featured_image_enabled', false ) && has_post_thumbnail( $post ) ) {
			$img_html = get_the_post_thumbnail( $post, 'large', array( 'style' => 'display:block;margin-bottom:1em;max-width:100%;height:auto;' ) );
			if ( '' !== $img_html ) {
				$prefix = '<p>' . $img_html . '</p>';
			}
		}

		$suffix = self::build_item_footer( $post );
		return $prefix . $content . $suffix;
	}

	/**
	 * Filters excerpt in RSS feeds.
	 *
	 * @param string $excerpt Original excerpt.
	 */
	public static function filter_excerpt_feed( string $excerpt ): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $excerpt;
		}

		$suffix = self::build_item_footer( $post );
		return $excerpt . $suffix;
	}

	/* ------------------------------------------------------------------ head cleanup */

	/**
	 * `wp_head` (priority 1): removes extra feed links if configured.
	 */
	public static function maybe_clean_head_links(): void {
		if ( (bool) SettingsRepository::get( 'feed.clean_head_links', false ) ) {
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
		if ( ! (bool) SettingsRepository::get( 'feed.main_enabled', true ) ) {
			add_filter( 'feed_links_show_posts_feed', '__return_false' );
		}
		if ( (bool) SettingsRepository::get( 'feed.disable_comments_feed', false ) ) {
			add_filter( 'feed_links_show_comments_feed', '__return_false' );
		}
	}

	/* ------------------------------------------------------------------ REST & status */

	/**
	 * Registers REST routes.
	 */
	public static function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/feed/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_status' ),
					'permission_callback' => Guard::rest(),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/feed/preview',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_preview' ),
					'permission_callback' => Guard::rest(),
					'args'                => array(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_preview' ),
					'permission_callback' => Guard::rest(),
					'args'                => array(
						'feed'    => array(
							'type'     => 'object',
							'required' => false,
						),
						'post_id' => array(
							'type'     => 'integer',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * REST handler for `/feed/status`.
	 */
	public static function rest_status( \WP_REST_Request $request ): \WP_REST_Response {
		$cfg = SettingsRepository::section( 'feed' );
		$data = array(
			'enabled'                => (bool) ( $cfg['main_enabled'] ?? true ),
			'url'                    => self::url(),
			'content_mode'           => (string) ( $cfg['content_mode'] ?? 'inherit' ),
			'items_count'            => (int) ( $cfg['items_count'] ?? 0 ),
			'source_link_enabled'    => (bool) ( $cfg['source_link_enabled'] ?? false ),
			'copyright_enabled'      => (bool) ( $cfg['copyright_enabled'] ?? false ),
			'featured_image_enabled' => (bool) ( $cfg['featured_image_enabled'] ?? false ),
			'noindex_feed'           => (bool) ( $cfg['noindex_feed'] ?? false ),
			'redirect_closed'        => (bool) ( $cfg['redirect_closed'] ?? false ),
			'clean_head_links'       => (bool) ( $cfg['clean_head_links'] ?? false ),
			'disabled_feeds'         => array(
				'comments'      => (bool) ( $cfg['disable_comments_feed'] ?? false ),
				'post_comments' => (bool) ( $cfg['disable_post_comments_feed'] ?? false ),
				'author'        => (bool) ( $cfg['disable_author_feed'] ?? false ),
				'taxonomy'      => (bool) ( $cfg['disable_taxonomy_feed'] ?? false ),
				'search'        => (bool) ( $cfg['disable_search_feed'] ?? false ),
				'attachment'    => (bool) ( $cfg['disable_attachment_feed'] ?? false ),
			),
		);
		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * REST handler for `/feed/preview`.
	 */
	public static function rest_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = null;
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof \WP_Post ) {
			$latest = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'publish',
				)
			);
			if ( ! empty( $latest ) ) {
				$post = $latest[0];
			}
		}

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_REST_Response( array( 'error' => __( 'No post found', 'wpseokit' ) ), 404 );
		}

		$raw_feed = $request->get_param( 'feed' );
		$feed_cfg = SettingsRepository::section( 'feed' );
		if ( is_array( $raw_feed ) ) {
			$feed_cfg = array_merge( $feed_cfg, $raw_feed );
		}

		$footer_tpl = (string) ( $feed_cfg['footer_text'] ?? '' );
		$cr_tpl     = (string) ( $feed_cfg['copyright_text'] ?? '' );

		$footer_rendered = '' !== trim( $footer_tpl ) ? self::resolve_variables( $footer_tpl, $post ) : '';
		$cr_rendered     = '' !== trim( $cr_tpl ) ? self::resolve_variables( $cr_tpl, $post ) : '';

		$sample_xml = sprintf(
			"<item>\n  <title>%s</title>\n  <link>%s</link>\n  <description>%s</description>\n</item>",
			esc_xml( (string) $post->post_title ),
			esc_url( (string) get_permalink( $post ) ),
			esc_xml( wp_trim_words( (string) $post->post_content, 25 ) . ( '' !== $footer_rendered ? "\n    " . $footer_rendered : '' ) )
		);

		$data = array(
			'post_id'            => $post->ID,
			'title'              => (string) $post->post_title,
			'url'                => (string) get_permalink( $post ),
			'footer_rendered'    => $footer_rendered,
			'copyright_rendered' => $cr_rendered,
			'sample_item_xml'    => $sample_xml,
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Adds feed status to the general `/status` endpoint payload.
	 *
	 * @param mixed $status Status payload.
	 * @return mixed
	 */
	public static function filter_status( $status ) {
		if ( is_array( $status ) ) {
			$status['feed'] = array(
				'enabled' => (bool) SettingsRepository::get( 'feed.main_enabled', true ),
				'url'     => self::url(),
			);
		}
		return $status;
	}
}
