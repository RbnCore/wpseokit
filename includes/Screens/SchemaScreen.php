<?php
/**
 * Schema tool: organization, website, content types, breadcrumbs and a read-only preview of the page graph.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use Wpseokit\Schema\Organization;

defined( 'ABSPATH' ) || exit;

final class SchemaScreen {

	/** Most categories listed in the mapping table. */
	private const MAP_LIMIT = 30;

	public static function init(): void {
		Shell::register(
			'schema',
			static fn(): string => __( 'Schema', 'wpseokit' ),
			'code',
			static fn(): string => __( 'Tell search engines who runs the site and what each page is.', 'wpseokit' ),
			20,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		$empty = '' === trim( (string) Ui::val( 'schema.organization_name', '' ) ) && '' === trim( (string) Ui::val( 'schema.person_name', '' ) );
		$top   = $empty ? Ui::note( 'warn', '<b>' . esc_html__( 'Organization name and logo are empty.', 'wpseokit' ) . '</b> ' . esc_html__( 'Search engines get an incomplete publisher until you add them.', 'wpseokit' ), 'status' ) : '';
		if ( ! $empty ) {
			$top = self::logo_note( Organization::publisher_status() );
		}
		Shell::page(
			'schema',
			__( 'Schema', 'wpseokit' ),
			array(
				'organization' => array( __( 'Organization', 'wpseokit' ), self::organization() ),
				'website'      => array( __( 'Website', 'wpseokit' ), self::website() ),
				'content'      => array( __( 'Content types', 'wpseokit' ), self::content() ),
				'breadcrumbs'  => array( __( 'Breadcrumbs', 'wpseokit' ), self::breadcrumbs() ),
				'preview'      => array( __( 'Preview', 'wpseokit' ), self::preview() ),
			),
			array(
				'form' => 'schema',
				'aria' => __( 'Schema sections', 'wpseokit' ),
				'top'  => $top,
			)
		);
	}

	/**
	 * Logo status line, from the same source as the dashboard card.
	 *
	 * @param array{key:string,logo:array<string,mixed>,small:bool} $pub Organization::publisher_status().
	 */
	private static function logo_note( array $pub ): string {
		switch ( $pub['key'] ) {
			case 'set':
				return Ui::note( 'ok', '<b>' . esc_html__( 'Organization name and logo are set.', 'wpseokit' ) . '</b>', 'status' );
			case 'icon':
				if ( $pub['small'] ) {
					/* translators: 1: width in pixels, 2: height in pixels. */
					$text = sprintf( __( 'The WordPress site icon (%1$d × %2$d px) is smaller than the 112 × 112 px search engines ask for. Add an organization logo below.', 'wpseokit' ), (int) $pub['logo']['width'], (int) $pub['logo']['height'] );
					return Ui::note( 'warn', '<b>' . esc_html__( 'Logo: site icon too small.', 'wpseokit' ) . '</b> ' . esc_html( $text ), 'status' );
				}
				return Ui::note( 'warn', '<b>' . esc_html__( 'Logo: site icon in use.', 'wpseokit' ) . '</b> ' . esc_html__( 'No organization logo is set, so the WordPress site icon is published instead. Add an organization logo below.', 'wpseokit' ), 'status' );
			default:
				return Ui::note( 'warn', '<b>' . esc_html__( 'Logo missing.', 'wpseokit' ) . '</b> ' . esc_html__( 'No organization logo or site icon is set. Add a logo below.', 'wpseokit' ), 'status' );
		}
	}

	private static function organization(): string {
		$org  = Ui::seg(
			'schema.site_represents',
			'sc-type',
			__( 'Type', 'wpseokit' ),
			array(
				'organization' => __( 'Organization', 'wpseokit' ),
				'person'       => __( 'Person', 'wpseokit' ),
			)
		);
		$org .= Ui::text( 'schema.organization_name', 'sc-org', __( 'Organization name', 'wpseokit' ), array( 'placeholder' => __( 'Your site or company name', 'wpseokit' ), 'help' => __( 'Shown to search engines as the publisher of every page.', 'wpseokit' ) ) );
		$org .= Ui::text( 'schema.person_name', 'sc-person', __( 'Person name', 'wpseokit' ), array( 'help' => __( 'Used instead of the organization name when the site is a person.', 'wpseokit' ) ) );
		$org .= Ui::row( '<span id="sc-logo-l">' . esc_html__( 'Logo', 'wpseokit' ) . '</span>', Ui::media( 'schema.organization_logo_id', 'schema.organization_logo_url', 'sc-logo' ), esc_html__( 'Square or wide, at least 112 × 112 px.', 'wpseokit' ) );
		$prof = '';
		foreach ( array(
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'linkedin'  => 'LinkedIn',
			'youtube'   => 'YouTube',
			'x'         => 'X',
			'pinterest' => 'Pinterest',
			'wikipedia' => 'Wikipedia',
		) as $key => $label ) {
			$prof .= Ui::text( 'social.profiles.' . $key, 'sc-pr-' . $key, $label, array( 'type' => 'url', 'mono' => true, 'placeholder' => 'https://' ) );
		}
		return Ui::card( __( 'Organization', 'wpseokit' ), __( 'Describes who publishes this site.', 'wpseokit' ), $org )
			. Ui::card( __( 'Social profiles', 'wpseokit' ), __( 'Full addresses of your official profiles. Empty ones are left out.', 'wpseokit' ), $prof );
	}

	private static function website(): string {
		$site  = Ui::text( 'schema.website_name', 'sc-site', __( 'Site name', 'wpseokit' ), array( 'help' => __( 'Used for the WebSite schema and the site name in search results.', 'wpseokit' ) ) );
		$site .= Ui::text( 'schema.alternate_website_name', 'sc-alt', __( 'Alternate name', 'wpseokit' ), array( 'help' => __( 'Optional. A shorter or spaced-out spelling people also use.', 'wpseokit' ) ) );
		return Ui::card( __( 'Website', 'wpseokit' ), '', $site );
	}

	private static function content(): string {
		$art = Ui::seg(
			'schema.article_type',
			'sc-art',
			__( 'Article type', 'wpseokit' ),
			array(
				'BlogPosting' => __( 'Blog post', 'wpseokit' ),
				'Article'     => __( 'Article', 'wpseokit' ),
				'NewsArticle' => __( 'News article', 'wpseokit' ),
			),
			__( 'Used for every post that is not a film or a series.', 'wpseokit' )
		);
		$map   = (array) Ui::val( 'schema.type_map', array() );
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => self::MAP_LIMIT,
			)
		);
		$terms = is_array( $terms ) ? $terms : array();
		$mapped = array_diff( array_map( 'intval', array_keys( $map ) ), wp_list_pluck( $terms, 'term_id' ) );
		if ( $mapped ) { // The map is saved as a whole, so a mapped category must stay in the form even if it is not among the busiest.
			$extra = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'include' => $mapped ) );
			$terms = array_merge( $terms, is_array( $extra ) ? $extra : array() );
		}
		$total = (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
		$rows  = '';
		foreach ( $terms as $term ) {
			$cur = (string) ( $map[ $term->term_id ] ?? '' );
			$sel = '<select class="rbn-select" id="sc-map-' . (int) $term->term_id . '" name="schema.type_map.' . (int) $term->term_id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: category name. */ __( 'Schema type for %s', 'wpseokit' ), $term->name ) ) . '">';
			foreach ( array(
				''         => __( 'Article', 'wpseokit' ),
				'Movie'    => __( 'Movie', 'wpseokit' ),
				'TVSeries' => __( 'TVSeries', 'wpseokit' ),
			) as $value => $label ) {
				$sel .= '<option value="' . esc_attr( $value ) . '"' . selected( $cur, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$rows .= '<tr><th scope="row" data-label="' . esc_attr__( 'Category', 'wpseokit' ) . '">' . esc_html( $term->name ) . '</th><td data-label="' . esc_attr__( 'Posts', 'wpseokit' ) . '" class="rbn-num">' . (int) $term->count . '</td><td data-label="' . esc_attr__( 'Default type', 'wpseokit' ) . '">' . $sel . '</select></td></tr>';
		}
		$table = '<div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Default schema type per category', 'wpseokit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Category', 'wpseokit' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Posts', 'wpseokit' ) . '</th><th scope="col">' . esc_html__( 'Default type', 'wpseokit' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
		/* translators: 1: categories listed, 2: all categories. */
		$count = sprintf( __( 'Showing %1$d of %2$d categories, the busiest first.', 'wpseokit' ), count( $terms ), $total );
		$info  = Ui::note( 'info', esc_html__( 'A post gets Movie or TVSeries schema only when its SEO box has the details filled in (director, cast, year). Without them it stays a plain Article. Nothing is guessed.', 'wpseokit' ) );
		return Ui::card( __( 'Articles', 'wpseokit' ), '', $art )
			. '<section class="rbn-card"><div class="rbn-card__h"><h2>' . esc_html__( 'Category mapping', 'wpseokit' ) . '</h2><p>' . esc_html__( 'Sets the starting point for the post SEO box. Each post can still choose its own type.', 'wpseokit' ) . '</p></div><div class="rbn-card__b">' . $info . '</div>' . $table . '<div class="rbn-card__foot"><p class="rbn-help">' . esc_html( $count ) . '</p></div></section>';
	}

	private static function breadcrumbs(): string {
		$body  = Ui::toggle_row( __( 'Primary category', 'wpseokit' ), 'general.primary_category', 'sc-pc', __( 'Use the primary category for posts', 'wpseokit' ), __( 'The primary category sets the post URL and the last step before the title.', 'wpseokit' ) );
		$body .= Ui::row(
			esc_html__( 'Theme function', 'wpseokit' ),
			'<code>wpseokit_breadcrumb_items()</code>',
			esc_html__( 'Your theme draws the visible breadcrumb; WPSeoKit sends the same path to search engines as BreadcrumbList data.', 'wpseokit' )
		);
		return Ui::card( __( 'Breadcrumbs', 'wpseokit' ), __( 'The path shown above a result in search: Home › Movies › Title.', 'wpseokit' ), $body );
	}

	private static function preview(): string {
		$posts = get_posts(
			array(
				'numberposts' => 10,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$opts  = '<option value="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home page', 'wpseokit' ) . '</option>';
		foreach ( $posts as $p ) {
			$opts .= '<option value="' . esc_url( (string) get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</option>';
		}
		$body  = Ui::row( '<label for="pv-post">' . esc_html__( 'Show the schema for', 'wpseokit' ) . '</label>', '<select class="rbn-select" id="pv-post" data-schema-url>' . $opts . '</select>' );
		$body .= Ui::row( esc_html__( 'JSON-LD', 'wpseokit' ), '<pre class="wps-pre" tabindex="0" aria-label="' . esc_attr__( 'Schema JSON-LD of the chosen page', 'wpseokit' ) . '" data-schema-out>' . esc_html__( 'Loading…', 'wpseokit' ) . '</pre><div class="wps-row"><button type="button" class="rbn-btn" data-copy="" data-schema-copy>' . Ui::icon( 'copy' ) . esc_html__( 'Copy JSON-LD', 'wpseokit' ) . '</button><a class="rbn-btn" href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">' . Ui::icon( 'out' ) . esc_html__( 'Open Google Rich Results Test (opens in a new tab)', 'wpseokit' ) . '</a></div>' );
		return Ui::card( __( 'Preview', 'wpseokit' ), __( 'Read-only. Reads the live page, so it reflects the settings you saved.', 'wpseokit' ), $body );
	}
}
