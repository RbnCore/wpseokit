<?php
/**
 * Admin UI helpers shared by every screen: kit assets, boot data, icons and form-row builders.
 * Output of each helper is escaped here; callers only concatenate finished fragments.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

namespace Wpseokit\Screens;

use AdminKit\Core\Security\Host;

use Wpseokit\Admin;
use Wpseokit\Plugin;
use Wpseokit\Repositories\SettingsRepository;
use Wpseokit\Rest;
use Wpseokit\Settings;

defined( 'ABSPATH' ) || exit;

final class Ui {

	/** Handle of the shared AdminKit UI kit (style + tab script). */
	private const KIT = 'rbn-admin-ui';

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
		wp_enqueue_style( self::KIT ); // The shared AdminKit kit (CSS + tab script, registered by AdminKit); this plugin ships no copy of it.
		wp_enqueue_script( self::KIT );
		wp_enqueue_style( 'wpseokit-ui', WPSEOKIT_URL . 'assets/admin/wpseokit-ui.css', array( self::KIT ), WPSEOKIT_VERSION );
		wp_enqueue_script( 'wpseokit-ui', WPSEOKIT_URL . 'assets/admin/wpseokit-admin.js', array( self::KIT ), WPSEOKIT_VERSION, array( 'in_footer' => true ) );
		if ( $page ) { // The Logs tab uses AdminKit's shared log list (registered by AdminKit on every admin screen).
			wp_enqueue_style( 'adminkit-core-logs' );
			wp_enqueue_script( 'adminkit-core-logs' );
			wp_enqueue_media(); // The image choosers (logo, default sharing image) open the WordPress media frame, which only loads when asked for.
		}
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
			'siteHost' => Host::host( home_url() ),
			'i18n'     => self::strings(),
		);
	}

	/** @return array<string, mixed> */
	private static function strings(): array {
		return array(
			'saved'          => __( 'Settings saved.', 'wpseokit' ),
			'saveFailed'     => __( 'Settings were not saved.', 'wpseokit' ),
			'saving'         => __( 'Saving…', 'wpseokit' ),
			'loading'        => __( 'Loading…', 'wpseokit' ),
			'loadFailed'     => __( 'This list could not be loaded.', 'wpseokit' ),
			'retry'          => __( 'Try again', 'wpseokit' ),
			'scoreOutOf'     => __( 'SEO score: %1$d out of 100, %2$s', 'wpseokit' ),
			'scoreHead'      => __( 'SEO score', 'wpseokit' ),
			'scoreNone'      => __( 'The score has not been calculated yet.', 'wpseokit' ),
			'scoreNoneText'  => __( 'WPSeoKit checks the site settings and your recent posts. It changes nothing on your site.', 'wpseokit' ),
			'calcNow'        => __( 'Calculate now', 'wpseokit' ),
			'recalc'         => __( 'Recalculate', 'wpseokit' ),
			'calculating'    => __( 'Calculating…', 'wpseokit' ),
			'calcOn'         => __( 'Calculated %s', 'wpseokit' ),
			'calcStale'      => __( 'Out of date: calculated %s. Recalculate to refresh it.', 'wpseokit' ),
			'calcFailed'     => __( 'The score could not be calculated.', 'wpseokit' ),
			'siteScore'      => __( 'Site settings', 'wpseokit' ),
			'postsScore'     => __( 'Average of posts', 'wpseokit' ),
			'postsSampled'   => __( '%1$d of %2$d recent posts scored', 'wpseokit' ),
			'noPosts'        => __( 'No scored posts yet.', 'wpseokit' ),
			'capNote'        => __( 'A critical rule fails, so the score cannot go above 79 until it is fixed.', 'wpseokit' ),
			'recsCount'      => __( '%d recommendations to review', 'wpseokit' ),
			'recsNone'       => __( 'Nothing to fix', 'wpseokit' ),
			'recsNoneText'   => __( 'Every rule WPSeoKit checks is in place.', 'wpseokit' ),
			'failingOn'      => __( 'Failing on %1$d of %2$d sampled posts.', 'wpseokit' ),
			'weight'         => __( 'Weight %s', 'wpseokit' ),
			'gain'           => __( 'Up to +%s points', 'wpseokit' ),
			'hide'           => __( 'Hide', 'wpseokit' ),
			'restore'        => __( 'Restore', 'wpseokit' ),
			'hidden'         => __( 'Hidden', 'wpseokit' ),
			'hiddenHead'     => __( 'Hidden recommendations (%d)', 'wpseokit' ),
			'noHide'         => __( 'Cannot be hidden.', 'wpseokit' ),
			'fixIn'          => __( 'Fix in %s', 'wpseokit' ),
			'fixSettings'    => __( 'Settings', 'wpseokit' ),
			'fixEditor'      => __( 'the post editor', 'wpseokit' ),
			'fixFor'         => __( 'for: %s', 'wpseokit' ),
			'lvCritical'     => __( 'Critical', 'wpseokit' ),
			'lvWarning'      => __( 'Warning', 'wpseokit' ),
			'lvInfo'         => __( 'Info', 'wpseokit' ),
			'scopeSite'      => __( 'Site', 'wpseokit' ),
			'scopePosts'     => __( 'Posts', 'wpseokit' ),
			'bandGood'       => __( 'Good', 'wpseokit' ),
			'bandFair'       => __( 'Needs improvement', 'wpseokit' ),
			'bandPoor'       => __( 'Poor', 'wpseokit' ),
			'allBands'       => __( 'All scores', 'wpseokit' ),
			'colPost'        => __( 'Post', 'wpseokit' ),
			'colScore'       => __( 'Score', 'wpseokit' ),
			'colBand'        => __( 'Band', 'wpseokit' ),
			'colFailing'     => __( 'Failing rules', 'wpseokit' ),
			'colRule'        => __( 'Rule', 'wpseokit' ),
			'colApplies'     => __( 'Applies to', 'wpseokit' ),
			'colLevel'       => __( 'Level', 'wpseokit' ),
			'colWeight'      => __( 'Weight', 'wpseokit' ),
			'colStart'       => __( 'Start', 'wpseokit' ),
			'colEnd'         => __( 'End', 'wpseokit' ),
			'colChange'      => __( 'Change', 'wpseokit' ),
			'colDate'        => __( 'Date', 'wpseokit' ),
			'colMetric'      => __( 'Measure', 'wpseokit' ),
			'noFailing'      => __( 'None', 'wpseokit' ),
			'editPost'       => __( 'Edit post', 'wpseokit' ),
			'rulesHead'      => __( 'Rules', 'wpseokit' ),
			'howHead'        => __( 'How the score is calculated', 'wpseokit' ),
			'howLead'        => __( 'The score is a weighted average of the rules. A rule that passes earns its full weight, a rule that is partly met earns a share of it, a rule that fails earns nothing, and a rule that does not apply is left out.', 'wpseokit' ),
			'howStep'        => __( 'Step %d', 'wpseokit' ),
			'howS1'          => __( 'Every rule gets a value', 'wpseokit' ),
			'howS1Text'      => __( 'WPSeoKit checks each rule and gives it one of four values.', 'wpseokit' ),
			'stPass'         => __( 'Passed', 'wpseokit' ),
			'stPartial'      => __( 'Partly met', 'wpseokit' ),
			'stFail'         => __( 'Failed', 'wpseokit' ),
			'stNa'           => __( 'Not applicable', 'wpseokit' ),
			'stPassVal'      => __( '1', 'wpseokit' ),
			'stPartialVal'   => __( 'between 0 and 1', 'wpseokit' ),
			'stFailVal'      => __( '0', 'wpseokit' ),
			'stNaVal'        => __( 'not counted', 'wpseokit' ),
			'howS2'          => __( 'Weights are averaged', 'wpseokit' ),
			'howS2Text'      => __( 'Each value is multiplied by the weight of its rule. The sum is divided by the weight of the rules that apply, scaled to 100 and rounded.', 'wpseokit' ),
			'howFormulaLabel'=> __( 'Formula', 'wpseokit' ),
			'howFormula'     => __( 'score = round( 100 × Σ(weight × value) ÷ Σ(weight of applicable rules) )', 'wpseokit' ),
			'howS3'          => __( 'The site score has two parts', 'wpseokit' ),
			'howS3Text'      => __( 'The site score blends the site settings with the average of your latest posts.', 'wpseokit' ),
			'howSample'      => __( 'The post average uses your newest %d posts. With no posts, the site settings score is the site score.', 'wpseokit' ),
			'capHead'        => __( 'Critical rules limit the score', 'wpseokit' ),
			'capText'        => __( 'While a critical rule fails, the score cannot go above %d. This limits the band; it does not change the formula.', 'wpseokit' ),
			'capRules'       => __( 'Critical rules (weight in brackets)', 'wpseokit' ),
			'bandsHead'      => __( 'Score bands', 'wpseokit' ),
			'bandsText'      => __( 'The score falls into one of three bands.', 'wpseokit' ),
			'bandsAria'      => __( 'Score bands: %s', 'wpseokit' ),
			'bandsYou'       => __( 'You: %d', 'wpseokit' ),
			'repOne'         => __( 'One snapshot so far. A trend needs at least two days; the numbers of that day are below.', 'wpseokit' ),
			'repMeta'        => __( 'Last snapshot: %s', 'wpseokit' ),
			'repTable'       => __( 'View as table', 'wpseokit' ),
			'repTaking'      => __( 'Taking a snapshot…', 'wpseokit' ),
			'repTaken'       => __( 'Snapshot saved.', 'wpseokit' ),
			'repFailed'      => __( 'The snapshot could not be taken.', 'wpseokit' ),
			'repGap'         => __( 'Gaps in a line are days without a snapshot.', 'wpseokit' ),
			'repTotal'       => __( '%s (total)', 'wpseokit' ),
			'repScore'       => __( 'SEO score', 'wpseokit' ),
			'repRedirects'   => __( 'Redirects and 404 errors', 'wpseokit' ),
			'repActive'      => __( 'Active redirects', 'wpseokit' ),
			'repHits404'     => __( '404 hits', 'wpseokit' ),
			'repSitemap'     => __( 'Sitemap size', 'wpseokit' ),
			'repUrls'        => __( 'URLs in the sitemap', 'wpseokit' ),
			'repCoverage'    => __( 'Custom title and description coverage', 'wpseokit' ),
			'repCoverageHelp' => __( 'Posts without a custom title use the title template (for example post title + site name); that is normal. The percentage shows only the custom titles and descriptions written by hand or imported.', 'wpseokit' ),
			'repTitles'      => __( 'Posts with a custom SEO title', 'wpseokit' ),
			'repDescs'       => __( 'Posts with a custom description', 'wpseokit' ),
			'repSchema'      => __( 'Schema types', 'wpseokit' ),
			'repSummary'     => __( 'Summary', 'wpseokit' ),
			'repLine'        => __( 'Solid line', 'wpseokit' ),
			'repDash'        => __( 'Dashed line', 'wpseokit' ),
			'repNoValue'     => __( 'No data', 'wpseokit' ),
			'repRedirHits'   => __( 'Redirect hits', 'wpseokit' ),
			'repAddresses'   => __( 'Logged 404 addresses', 'wpseokit' ),
			'repNew24'       => __( 'New 404 addresses (24 h)', 'wpseokit' ),
			'repRulesTotal'  => __( 'Rules checked', 'wpseokit' ),
			'repRulesPassed' => __( 'Rules passed', 'wpseokit' ),
			'repRulesLeft'   => __( 'Rules to fix', 'wpseokit' ),
			'repRulesNa'     => __( 'Rules not applicable', 'wpseokit' ),
			'repPostsCount'  => __( 'Posts counted', 'wpseokit' ),
			'repSitemapOf'   => __( 'Sitemap: %s', 'wpseokit' ),
			'repSchemaOf'    => __( 'Schema: %s', 'wpseokit' ),
			'schemaTypes'    => array(
				'BlogPosting' => __( 'Blog post', 'wpseokit' ),
				'Article'     => __( 'Article', 'wpseokit' ),
				'NewsArticle' => __( 'News article', 'wpseokit' ),
				'Movie'       => __( 'Movie', 'wpseokit' ),
				'TVSeries'    => __( 'TV series', 'wpseokit' ),
			),
			'repValue'       => __( 'Value', 'wpseokit' ),
			'reviewPosts'    => __( 'Review posts', 'wpseokit' ),
			/* translators: %d: number of addresses. */
			'logNew404' => __( '%d new 404 errors', 'wpseokit' ),
			/* translators: %d: number of addresses (one). */
			'logNew404One' => __( '%d new 404 error', 'wpseokit' ),
			'logNew404Text' => __( 'in the last 24 hours. Visits are counted in the 404 log, not here.', 'wpseokit' ),
			'logOpen404' => __( 'Open the 404 log', 'wpseokit' ),
			'toolNames'      => array(
				'schema'    => __( 'Schema', 'wpseokit' ),
				'sitemap'   => __( 'Sitemap', 'wpseokit' ),
				'feed'      => __( 'Feed', 'wpseokit' ),
				'llms'      => __( 'llms.txt', 'wpseokit' ),
				'redirects' => __( 'Redirects', 'wpseokit' ),
				'log404'    => __( '404 log', 'wpseokit' ),
				'settings'  => __( 'Settings', 'wpseokit' ),
			),
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
			/* translators: %d: number of selected 404 rows. */
			'delete404N'     => __( 'Delete %d 404 log entries?', 'wpseokit' ),
			/* translators: %d: number of selected 404 rows. */
			'delete404NBtn'  => __( 'Delete %d entries', 'wpseokit' ),
			'delete404Body'  => __( 'This removes the logged 404 visits. If visitors request these addresses again, new log entries will appear.', 'wpseokit' ),
			'keep'           => __( 'Keep them', 'wpseokit' ),
			/* translators: %d: number of redirects. */
			'importN'        => __( 'Import %d redirects', 'wpseokit' ),
			'csvChecked'     => __( 'checked', 'wpseokit' ),
			'csvNeedsFile'   => __( 'Choose a CSV file first.', 'wpseokit' ),
			'line'           => __( 'Line', 'wpseokit' ),
			'problem'        => __( 'Problem', 'wpseokit' ),
			'invalid'        => __( 'Invalid', 'wpseokit' ),
			'csvAttention'   => __( 'Lines that need attention', 'wpseokit' ),
			'csvTooBig'      => __( 'Up to 1 MB.', 'wpseokit' ),
			'csvNoSlash'     => __( '“From” does not start with a slash', 'wpseokit' ),
			/* translators: %s: redirect type found in the CSV line, such as 308. */
			'csvBadType'     => __( 'Type %s is not supported', 'wpseokit' ),
			'csvToEmpty'     => __( '“To” is empty', 'wpseokit' ),
			/* translators: %d: line number of the first occurrence. */
			'csvDuplicate'   => __( 'Duplicate of line %d', 'wpseokit' ),
			'otherAddress'   => __( 'Or type another address', 'wpseokit' ),
			'createRedirect' => __( 'Create redirect', 'wpseokit' ),
			'details'        => __( 'Score details', 'wpseokit' ),
			'dlFile' => __( 'File', 'wpseokit' ),
			'dlUrls' => __( 'URLs', 'wpseokit' ),
			'dlLastmod' => __( 'Last modified', 'wpseokit' ),
			'dlStatus' => __( 'Status', 'wpseokit' ),
			'dlSelect' => __( 'Select', 'wpseokit' ),
			'dlFrom' => __( 'From', 'wpseokit' ),
			'dlTo' => __( 'To', 'wpseokit' ),
			'dlType' => __( 'Type', 'wpseokit' ),
			'dlLastHit' => __( 'Last hit', 'wpseokit' ),
			'dlActions' => __( 'Actions', 'wpseokit' ),
			'dlAddress' => __( 'Address', 'wpseokit' ),
			'dlReferrer' => __( 'Referrer', 'wpseokit' ),
			'dlLastSeen' => __( 'Last seen', 'wpseokit' ),
			'dlOk' => __( 'OK', 'wpseokit' ),
			'redirectTo'     => __( 'Redirect to…', 'wpseokit' ),
			'bestMatch'      => __( 'Best match', 'wpseokit' ),
			'redirected'     => __( 'Redirect', 'wpseokit' ),
			'rebuilt'        => __( 'Sitemap rebuilt.', 'wpseokit' ),
			'rebuilding'     => __( 'Rebuilding the sitemap…', 'wpseokit' ),
			'copied'         => __( 'Copied', 'wpseokit' ),
			'pagePrev'       => __( 'Previous page', 'wpseokit' ),
			'pageNext'       => __( 'Next page', 'wpseokit' ),
			/* translators: 1: current page number, 2: number of pages */
			'pageOf'         => __( 'Page %1$d of %2$d', 'wpseokit' ),
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
			'cancel'         => __( 'Cancel', 'wpseokit' ),
			'import'         => __( 'Import', 'wpseokit' ),
			'importTitle'    => __( 'Import Yoast data?', 'wpseokit' ),
			'importMsg'      => __( 'WPSeoKit will write titles, descriptions, primary categories and settings. Your Yoast data stays where it is.', 'wpseokit' ),
			'applyBtn'       => __( 'Apply this address', 'wpseokit' ),
			'applyConfirmMsg' => __( 'You will have to log in again.', 'wpseokit' ),
			'undoBtn'        => __( 'Go back to the previous address', 'wpseokit' ),
			'undoConfirmMsg' => __( 'The previous address is in effect again.', 'wpseokit' ),
			'importData' => __( 'Data', 'wpseokit' ),
			'importWritten' => __( 'Imported', 'wpseokit' ),
			'importSame' => __( 'Already the same', 'wpseokit' ),
			'importNote' => __( 'Note', 'wpseokit' ),
			'importRowsCap' => __( 'What the import did', 'wpseokit' ),
			/* translators: %s: date and time. */
			'importAppliedOn' => __( 'The Yoast import was applied on %s.', 'wpseokit' ),
			/* translators: %s: date and time. */
			'importVerifiedOn' => __( 'Verified on %s.', 'wpseokit' ),
			/* translators: %s: date and time. */
			'importVerifiedNo' => __( 'The check on %s found a difference. Verify again from the Report tab.', 'wpseokit' ),
			'importViewReport' => __( 'View report', 'wpseokit' ),
			'importCheckAgain' => __( 'Run the check again', 'wpseokit' ),
			'importAppliedTitle' => __( 'The import is already applied.', 'wpseokit' ),
			'importAppliedRun' => __( 'Run the check again to see whether anything new is left to import.', 'wpseokit' ),
			'importAppliedDry' => __( 'The import was applied, so there is no current check. Run it again to see what would change now.', 'wpseokit' ),
			'addr'           => self::address_strings(),
		);
	}

	/**
	 * Strings of the Redirects > Preferred address tab. `verdicts` mirrors RedirectsScreen::verdicts().
	 *
	 * @return array<string, mixed>
	 */
	private static function address_strings(): array {
		$verdicts = array();
		foreach ( RedirectsScreen::verdicts() as $id => $v ) {
			$verdicts[ $id ] = array(
				'label' => $v[0],
				'tone'  => $v[1],
				'icon'  => $v[2],
			);
		}
		return array(
			'verdicts'      => $verdicts,
			/* translators: %s: scheme, https or http. */
			'autoHelp'      => __( 'Automatic follows your WordPress address, which is %s now. Google prefers https pages.', 'wpseokit' ),
			/* translators: %s: scheme, https or http. */
			'autoNow'       => __( 'Automatic (%s)', 'wpseokit' ),
			'auto'          => __( 'Automatic', 'wpseokit' ),
			'prefSaved'     => __( 'Preference saved.', 'wpseokit' ),
			'prefFailed'    => __( 'The preference was not saved.', 'wpseokit' ),
			'statusFailed'   => __( 'The current settings could not be loaded.', 'wpseokit' ),
			'checking'      => __( 'Checking 4 addresses. This can take a few seconds.', 'wpseokit' ),
			'runAgain'      => __( 'Run audit again', 'wpseokit' ),
			'runFailedT'    => __( 'The check could not run.', 'wpseokit' ),
			'runFailedB'    => __( 'Your settings are unchanged. Wait a moment and try again.', 'wpseokit' ),
			/* translators: %s: time of day. */
			'checkedAt'     => __( 'Checked at %s', 'wpseokit' ),
			/* translators: %s: summary such as "3 OK, 1 to fix". */
			'auditDone'     => __( 'Audit finished: %s', 'wpseokit' ),
			/* translators: %d: number of versions. */
			'nOk'           => __( '%d OK', 'wpseokit' ),
			/* translators: %d: number of versions. */
			'nFix'          => __( '%d to fix', 'wpseokit' ),
			/* translators: %d: number of versions. */
			'nUnreach'      => __( '%d unreachable', 'wpseokit' ),
			'attention'     => __( 'Needs attention', 'wpseokit' ),
			'knowThis'      => __( 'One thing to know', 'wpseokit' ),
			'allGood'       => __( 'All four versions end at the preferred address in one step.', 'wpseokit' ),
			'nothingToFix'  => __( 'Nothing to change.', 'wpseokit' ),
			'fourVersions'  => __( 'The four versions of your address', 'wpseokit' ),
			'colAddress'    => __( 'Address', 'wpseokit' ),
			'colStatus'     => __( 'Status code', 'wpseokit' ),
			'colGoes'       => __( 'Goes to', 'wpseokit' ),
			'colRedirects'  => __( 'Redirects', 'wpseokit' ),
			'colVerdict'    => __( 'Verdict', 'wpseokit' ),
			'preferredTag'  => __( 'Preferred', 'wpseokit' ),
			'staysHere'      => __( 'Stays here', 'wpseokit' ),
			'stepOne'        => __( 'Redirect step', 'wpseokit' ),
			'stepMany'       => __( 'Redirect steps', 'wpseokit' ),
			/* translators: %d: step number. */
			'stepN'          => __( 'Step %d:', 'wpseokit' ),
			'stepTo'         => __( 'to', 'wpseokit' ),
			'viaServer'     => __( 'by server', 'wpseokit' ),
			/* translators: %s: who sent the redirect, such as WordPress. */
			'viaOther'      => __( 'by %s', 'wpseokit' ),
			'otherChecks'   => __( 'Other checks', 'wpseokit' ),
			'otherCaption'  => __( 'Other address checks', 'wpseokit' ),
			'colCheck'      => __( 'Check', 'wpseokit' ),
			'colResult'     => __( 'Result', 'wpseokit' ),
			'chkHome'       => __( 'WordPress address (home)', 'wpseokit' ),
			'chkSiteurl'    => __( 'Site address (siteurl)', 'wpseokit' ),
			'chkCanonical'  => __( 'Canonical tag', 'wpseokit' ),
			'chkSitemap'    => __( 'Sitemap links', 'wpseokit' ),
			'chkFeed'       => __( 'Feed links', 'wpseokit' ),
			'chkHsts'       => __( 'HSTS header', 'wpseokit' ),
			'matches'       => __( 'Matches', 'wpseokit' ),
			'differs'       => __( 'Differs', 'wpseokit' ),
			'information'   => __( 'Information', 'wpseokit' ),
			'notAvailable'  => __( 'Could not be read', 'wpseokit' ),
			'missing'       => __( 'Missing', 'wpseokit' ),
			/* translators: 1: address found, 2: preferred address. */
			'canonicalDiff' => __( '%1$s (preferred is %2$s)', 'wpseokit' ),
			/* translators: 1: number of links that point to another host, 2: all links. */
			'linksDiffer'   => __( '%1$d of %2$d links point to another host', 'wpseokit' ),
			'linksFine'     => __( 'All links match', 'wpseokit' ),
			'hstsSet'       => __( 'Set:', 'wpseokit' ),
			'hstsNone'      => __( 'Not set. Optional: consider it only after the redirects are stable.', 'wpseokit' ),
			'noRuleT'       => __( 'No rule yet.', 'wpseokit' ),
			'noRuleB'       => __( 'Enter a valid host name above to create the rule.', 'wpseokit' ),
			'ruleLoading'   => __( 'Creating the rule…', 'wpseokit' ),
			'ruleFailed'    => __( 'The rule could not be created.', 'wpseokit' ),
			'copyApache'    => __( 'Copy Apache rule', 'wpseokit' ),
			'copyNginx'     => __( 'Copy nginx rule', 'wpseokit' ),
			'copiedApache'  => __( 'Apache rule copied.', 'wpseokit' ),
			'copiedNginx'   => __( 'nginx rule copied.', 'wpseokit' ),
			'copyFailed'    => __( 'Copying is blocked here. The rule is selected: press Ctrl+C.', 'wpseokit' ),
			'fbMulti'       => __( 'This is a multisite network. The PHP fallback never runs here. Use the server rule.', 'wpseokit' ),
			'fbLockedT'     => __( 'Locked for now.', 'wpseokit' ),
			'fbLockedB'     => __( 'The audit found a chain or a loop. A PHP redirect would add one more step. Fix the server rule, run the audit again, then come back.', 'wpseokit' ),
			'fbOnChainT'    => __( 'The PHP fallback is on and a chain exists.', 'wpseokit' ),
			'fbOnChainB'    => __( 'Turn it off, then rely on the server rule.', 'wpseokit' ),
			'fbOff'         => __( 'Turn off PHP fallback', 'wpseokit' ),
			'fbNotNeededT'  => __( 'Not needed.', 'wpseokit' ),
			'fbNotNeededB'  => __( 'Your server already redirects every version in one step.', 'wpseokit' ),
			'fbMaybe'       => __( 'If you cannot edit the server, this fallback can send the copies to the preferred address. A server rule is still faster.', 'wpseokit' ),
			'fbFirst'       => __( 'Run the audit first. It shows whether your server already redirects.', 'wpseokit' ),
			'fbSaved'       => __( 'Saved.', 'wpseokit' ),
		);
	}

	/** Prints the icon sprite once per request. */
	public static function sprite(): void {
		\AdminKit\Helpers\Icons::sprite( WPSEOKIT_DIR . 'assets/admin/sprite.svg' );
	}

	public static function icon( string $name ): string {
		return \AdminKit\Helpers\Icons::icon( $name );
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
		return (string) ob_get_clean() . '<div class="wps-screen" data-screen="' . esc_attr( $screen ) . '"><div class="wps-live-region" role="status" aria-live="polite" data-result></div>';
	}

	public static function close(): string {
		return '</div>';
	}

	/** @param string $type ok|warn|err|info */
	public static function note( string $type, string $html, string $role = '' ): string {
		$icons = array( 'ok' => 'check', 'warn' => 'alert', 'err' => 'x', 'info' => 'info' );
		$attr  = '' !== $role ? ' role="' . esc_attr( $role ) . '"' : '';
		return '<div class="rbn-note' . ( 'info' === $type ? '' : ' rbn-note--' . esc_attr( $type ) ) . '"' . $attr . '>' . self::icon( $icons[ $type ] ?? 'info' ) . '<div class="rbn-note__t"><p>' . $html . '</p></div></div>';
	}

	public static function card( string $title, string $desc, string $body, string $extra = '' ): string {
		$head = '<div class="rbn-card__h"><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $desc ? '<p>' . esc_html( $desc ) . '</p>' : '' ) . '</div>';
		return '<section class="rbn-card"' . $extra . '>' . $head . '<div class="rbn-card__b">' . $body . '</div></section>';
	}

	/** One label + control row. */
	public static function row( string $label_html, string $control, string $help = '', string $help_id = '' ): string {
		$h = '' !== $help ? '<p class="rbn-help"' . ( '' !== $help_id ? ' id="' . esc_attr( $help_id ) . '"' : '' ) . '>' . $help . '</p>' : '';
		$l = 0 === strpos( $label_html, '<label' ) ? $label_html : '<span class="rbn-label">' . $label_html . '</span>';
		return '<div class="rbn-field">' . $l . $control . $h . '</div>';
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
	 * @param array<string, mixed> $o help, mono, placeholder, textarea, rows, vars(array), type, min, max, step, list(bool), counter(bool: single line with a live character count)
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
			if ( ! empty( $o['counter'] ) ) {
				$attrs .= ' maxlength="' . Settings::TEXT_MAX . '" data-count';
			}
			$ctl = '<input class="' . $cls . '" type="' . esc_attr( $type ) . '"' . $attrs . ' value="' . esc_attr( (string) $value ) . '">';
			if ( ! empty( $o['counter'] ) ) {
				$ctl .= '<p class="wps-count" data-count-for="' . esc_attr( $id ) . '">' . (int) mb_strlen( (string) $value ) . ' / ' . Settings::TEXT_MAX . '</p>';
			}
		}
		if ( ! empty( $o['unit'] ) ) {
			$ctl = '<span class="wps-unit">' . $ctl . '<span>' . esc_html( (string) $o['unit'] ) . '</span></span>';
		}
		if ( ! empty( $o['vars'] ) ) {
			$ctl .= self::vars( $id, (array) $o['vars'] );
			$ctl .= '<p class="wps-live" data-preview-for="' . esc_attr( $id ) . '"></p>';
		}
		return self::row( self::label( $id, $label ), $ctl, (string) ( $o['help'] ?? '' ), $help_id );
	}

	/** "Insert a variable" disclosure. @param array<int, string> $tokens */
	public static function vars( string $target, array $tokens, bool $open = false ): string {
		$out = '<details class="wps-vars" data-vars-for="' . esc_attr( $target ) . '"' . ( $open ? ' open' : '' ) . '><summary>' . self::icon( 'plus' ) . esc_html__( 'Insert a variable', 'wpseokit' ) . '</summary><div class="wps-toks">';
		foreach ( $tokens as $t ) {
			$out .= '<button type="button" class="wps-tok" data-insert="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</button>';
		}
		return $out . '</div></details>';
	}

	/** Switch bound to a boolean setting. */
	public static function toggle( string $path, string $id, string $title, string $help = '', bool $disabled = false ): string {
		$on = (bool) self::val( $path, false );
		return '<label class="rbn-switch"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $path ) . '" data-type="bool"' . ( $on ? ' checked' : '' ) . ( $disabled ? ' disabled' : '' ) . '><span class="rbn-switch__x"></span><span><b>' . esc_html( $title ) . '</b>' . ( '' !== $help ? '<span class="rbn-help">' . esc_html( $help ) . '</span>' : '' ) . '</span></label>';
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
		$out = '<fieldset class="wps-radios" aria-labelledby="' . esc_attr( $id ) . '-l">';
		foreach ( $options as $value => $text ) {
			$out .= '<label class="rbn-check"><input type="radio" name="' . esc_attr( $path ) . '" value="' . esc_attr( (string) $value ) . '"' . checked( $cur, (string) $value, false ) . '><span>' . esc_html( $text ) . '</span></label>';
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
		$out = '<div class="wps-switchlist">';
		$i   = 0;
		foreach ( $items as $value => $text ) {
			$out .= '<label class="rbn-switch"><input type="checkbox" id="' . esc_attr( $id . '-' . $i ) . '" name="' . esc_attr( $path ) . '" value="' . esc_attr( (string) $value ) . '" data-multi' . ( in_array( (string) $value, $cur, true ) ? ' checked' : '' ) . '><span class="rbn-switch__x"></span><span><b>' . esc_html( $text ) . '</b></span></label>';
			++$i;
		}
		return $out . '</div>';
	}

	/** Image chooser bound to an id + url setting pair. */
	public static function media( string $id_path, string $url_path, string $id, string $help = '' ): string {
		$url   = (string) self::val( $url_path, '' );
		$img   = '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="">' : self::icon( 'image' );
		$ctl   = '<div class="wps-media" data-media="' . esc_attr( $id ) . '"><div class="wps-media__img' . ( '' !== $url ? ' wps-media__img--filled' : '' ) . '">' . $img . '</div><div class="wps-row">';
		$ctl  .= '<button type="button" class="rbn-btn" data-media-pick>' . self::icon( 'image' ) . esc_html( '' !== $url ? __( 'Replace image', 'wpseokit' ) : __( 'Choose image', 'wpseokit' ) ) . '</button>';
		$ctl  .= '<button type="button" class="rbn-btn rbn-btn--danger" data-media-clear' . ( '' === $url ? ' hidden' : '' ) . '>' . self::icon( 'trash' ) . esc_html__( 'Remove', 'wpseokit' ) . '</button></div>';
		$ctl  .= '<input type="hidden" name="' . esc_attr( $id_path ) . '" value="' . (int) self::val( $id_path, 0 ) . '" data-type="int" data-media-id>';
		$ctl  .= '<input type="hidden" name="' . esc_attr( $url_path ) . '" value="' . esc_attr( $url ) . '" data-media-url></div>';
		return $ctl . ( '' !== $help ? '<p class="rbn-help">' . esc_html( $help ) . '</p>' : '' );
	}

	/** Sticky save bar shown only while a form has unsaved changes. */
	public static function bar(): string {
		return '<div class="wps-savebar" data-bar><p>' . esc_html__( 'You have unsaved changes.', 'wpseokit' ) . '</p><div class="wps-savebar__act"><button type="button" class="rbn-btn" data-discard>' . esc_html__( 'Discard', 'wpseokit' ) . '</button><button type="submit" class="rbn-btn rbn-btn--pri" data-save>' . self::icon( 'save' ) . esc_html__( 'Save changes', 'wpseokit' ) . '</button></div></div>';
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
