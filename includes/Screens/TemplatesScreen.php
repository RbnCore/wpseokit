<?php
/**
 * Titles & descriptions tool: home page, posts, pages, categories and tags, archives and search, separator and variables.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

defined( 'ABSPATH' ) || exit;

final class TemplatesScreen {

	public static function init(): void {
		Shell::register(
			'templates',
			static fn(): string => __( 'Titles & descriptions', 'wpseokit' ),
			'doc',
			static fn(): string => __( 'Write the title and description pattern for each kind of page.', 'wpseokit' ),
			10,
			array( self::class, 'render' )
		);
	}

	/** @return array<int, string> */
	private static function post_vars(): array {
		return array( '%title%', '%sitename%', '%sep%', '%page%', '%excerpt%', '%category%', '%primary_category%', '%date%', '%name%' );
	}

	/** @return array<int, string> */
	private static function term_vars(): array {
		return array( '%term_title%', '%sitename%', '%sep%', '%page%' );
	}

	public static function render(): void {
		Shell::page(
			'templates',
			__( 'Titles & descriptions', 'wpseokit' ),
			array(
				'home'       => array( __( 'Home', 'wpseokit' ), self::home() ),
				'posts'      => array( __( 'Posts', 'wpseokit' ), self::post_types( true ) ),
				'pages'      => array( __( 'Pages', 'wpseokit' ), self::post_types( false ) ),
				'taxonomies' => array( __( 'Categories & tags', 'wpseokit' ), self::taxonomies() ),
				'archives'   => array( __( 'Archives & search', 'wpseokit' ), self::archives() ),
				'separator'  => array( __( 'Separator & variables', 'wpseokit' ), self::separator() ),
			),
			array(
				'form' => 'templates',
				'aria' => __( 'Titles and descriptions sections', 'wpseokit' ),
			)
		);
	}

	private static function home(): string {
		$body  = Ui::text( 'templates.home_title', 'tpl-home-t', __( 'Title template', 'wpseokit' ), array( 'mono' => true, 'vars' => array( '%sitename%', '%sitedesc%', '%sep%', '%page%' ) ) );
		$body .= Ui::text( 'templates.home_description', 'tpl-home-d', __( 'Meta description', 'wpseokit' ), array( 'counter' => true, 'vars' => array( '%sitename%', '%sitedesc%' ), 'help' => __( 'Shown under the title in search results. Aim for 70–155 characters. Leave empty to use the tagline.', 'wpseokit' ) ) );
		return Ui::card( __( 'Home page', 'wpseokit' ), __( 'How the front page looks in search results.', 'wpseokit' ), $body );
	}

	/** Cards for posts (and custom types) or for pages. */
	private static function post_types( bool $posts ): string {
		$out = '';
		foreach ( Ui::post_types() as $name => $label ) {
			if ( ( 'page' === $name ) === $posts ) {
				continue;
			}
			$count = (int) ( wp_count_posts( $name )->publish ?? 0 );
			/* translators: %d: number of published items. */
			$desc  = sprintf( _n( '%d published item.', '%d published items.', $count, 'wpseokit' ), $count );
			$body  = Ui::text( 'templates.post_types.' . $name . '.title', 'tpl-pt-' . $name . '-t', __( 'Title template', 'wpseokit' ), array( 'mono' => true, 'vars' => self::post_vars() ) );
			$body .= Ui::text(
				'templates.post_types.' . $name . '.description',
				'tpl-pt-' . $name . '-d',
				__( 'Meta description', 'wpseokit' ),
				array(
					'counter'  => true,
					'vars'     => self::post_vars(),
					'help'     => __( 'Used when an item has no description of its own. An empty template falls back to the excerpt, then to the first 155 characters of the content.', 'wpseokit' ),
				)
			);
			$body .= Ui::row(
				esc_html__( 'Search results', 'wpseokit' ),
				'<label class="rbn-switch"><input type="checkbox" id="tpl-pt-' . esc_attr( $name ) . '-i" name="robots.noindex_post_types" value="' . esc_attr( $name ) . '" data-multi data-invert' . ( in_array( $name, (array) Ui::val( 'robots.noindex_post_types', array() ), true ) ? '' : ' checked' ) . '><span class="rbn-switch__x"></span><span><b>' . esc_html( sprintf( /* translators: %s: post type name. */ __( 'Show %s in search results', 'wpseokit' ), $label ) ) . '</b><span class="rbn-help">' . esc_html__( 'Turn off to add noindex to every item of this type.', 'wpseokit' ) . '</span></span></label>'
			);
			$out  .= Ui::card( $label, $desc, $body );
		}
		return $out;
	}

	private static function taxonomies(): string {
		$body = '';
		foreach ( Ui::taxonomies() as $name => $label ) {
			$body .= Ui::text( 'templates.taxonomies.' . $name . '.title', 'tpl-tx-' . $name . '-t', sprintf( /* translators: %s: taxonomy name. */ __( '%s title', 'wpseokit' ), $label ), array( 'mono' => true, 'vars' => self::term_vars() ) );
		}
		$body .= Ui::row( esc_html__( 'Search results', 'wpseokit' ), Ui::multi( 'robots.noindex_taxonomies', 'tpl-nitx', Ui::taxonomies() ), esc_html__( 'Switched on = the archive asks search engines not to list it, and it leaves the sitemap.', 'wpseokit' ) );
		return Ui::card( __( 'Categories and tags', 'wpseokit' ), __( 'Category descriptions are edited on each category.', 'wpseokit' ), $body );
	}

	private static function archives(): string {
		$body  = Ui::text( 'templates.author_title', 'tpl-author', __( 'Author archives', 'wpseokit' ), array( 'mono' => true, 'vars' => array( '%name%', '%page%', '%sitename%', '%sep%' ), 'help' => __( 'Used for pages that list one author’s posts.', 'wpseokit' ) ) );
		$body .= Ui::toggle_row( __( 'Empty author pages', 'wpseokit' ), 'robots.noindex_author_noposts', 'tpl-niauth', __( 'Add noindex to authors without posts', 'wpseokit' ) );
		$body .= Ui::text( 'templates.date_title', 'tpl-date', __( 'Date archives', 'wpseokit' ), array( 'mono' => true, 'vars' => array( '%date%', '%page%', '%sitename%', '%sep%' ) ) );
		$body .= Ui::toggle_row( __( 'Date archives', 'wpseokit' ), 'robots.noindex_date', 'tpl-nidate', __( 'Add noindex to date archives', 'wpseokit' ) );
		$body .= Ui::text( 'templates.search_title', 'tpl-search', __( 'Search results page', 'wpseokit' ), array( 'mono' => true, 'vars' => array( '%searchphrase%', '%sitename%', '%sep%' ), 'help' => __( 'Always noindex, so this only sets the browser tab text.', 'wpseokit' ) ) );
		$body .= Ui::text( 'templates.notfound_title', 'tpl-404', __( '404 page', 'wpseokit' ), array( 'mono' => true, 'vars' => array( '%sitename%', '%sep%' ) ) );
		return Ui::card( __( 'Archives and search', 'wpseokit' ), __( 'Pages WordPress makes by itself.', 'wpseokit' ), $body );
	}

	private static function separator(): string {
		$seps = array(
			'-' => '-',
			'–' => '–',
			'·' => '·',
			'|' => '|',
			'»' => '»',
		);
		$sep  = Ui::card( __( 'Separator', 'wpseokit' ), __( 'Used between the page title and the site name.', 'wpseokit' ), Ui::seg( 'templates.sep', 'tpl-sep', __( 'Title separator', 'wpseokit' ), $seps, __( 'Replaces %sep% in every template.', 'wpseokit' ) ) );
		$vars = array(
			'%title%'            => array( __( 'The title of the post or page.', 'wpseokit' ), __( 'Gentle Shadows', 'wpseokit' ) ),
			'%sitename%'         => array( __( 'The site title.', 'wpseokit' ), get_bloginfo( 'name' ) ),
			'%sitedesc%'         => array( __( 'The site tagline.', 'wpseokit' ), get_bloginfo( 'description' ) ),
			'%sep%'              => array( __( 'The separator chosen above.', 'wpseokit' ), (string) Ui::val( 'templates.sep', '-' ) ),
			'%page%'             => array( __( 'Page number on paged lists, empty on page one.', 'wpseokit' ), __( 'Page 2', 'wpseokit' ) ),
			'%excerpt%'          => array( __( 'The excerpt of the post.', 'wpseokit' ), __( 'A short summary…', 'wpseokit' ) ),
			'%category%'         => array( __( 'The categories of the post.', 'wpseokit' ), __( 'Crime', 'wpseokit' ) ),
			'%primary_category%' => array( __( 'The primary category of the post.', 'wpseokit' ), __( 'Crime', 'wpseokit' ) ),
			'%date%'             => array( __( 'The publish date.', 'wpseokit' ), wp_date( get_option( 'date_format' ) ) ),
			'%name%'             => array( __( 'The author name.', 'wpseokit' ), __( 'Jane Doe', 'wpseokit' ) ),
			'%term_title%'       => array( __( 'The name of the category or tag.', 'wpseokit' ), __( 'Crime', 'wpseokit' ) ),
			'%searchphrase%'     => array( __( 'What the visitor searched for.', 'wpseokit' ), __( 'gentle', 'wpseokit' ) ),
		);
		$rows = '';
		foreach ( $vars as $tok => $v ) {
			$rows .= '<tr><th scope="row" data-label="' . esc_attr__( 'Variable', 'wpseokit' ) . '"><code>' . esc_html( $tok ) . '</code></th><td data-label="' . esc_attr__( 'Meaning', 'wpseokit' ) . '">' . esc_html( $v[0] ) . '</td><td data-label="' . esc_attr__( 'Example', 'wpseokit' ) . '">' . esc_html( $v[1] ) . '</td></tr>';
		}
		$table = '<div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Template variables', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Variable', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Meaning', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Example', 'wpseokit' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
		return $sep . '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Variables', 'wpseokit' ) . '</h2><p>' . esc_html__( 'Use these in any template. Click “Insert a variable” in a field to add one.', 'wpseokit' ) . '</p></div>' . $table . '</section>';
	}
}
