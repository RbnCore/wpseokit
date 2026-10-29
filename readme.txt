=== WPSeoKit — SEO Toolkit ===
Contributors: rbnbilisim
Tags: seo, sitemap, schema, redirects, open graph
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Requires Plugins: adminkit
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Link: https://github.com/RbnCore

SEO titles and meta descriptions, canonical and robots, Open Graph, XML sitemap, schema graph, redirects and a 404 log. Connects to GitHub only to check for updates (can be turned off in the settings).

== Description ==

WPSeoKit does the daily work of an SEO plugin on one site: it writes the tags search engines read, keeps an XML sitemap that carries the same URLs as before, closes the addresses that should not be indexed, and shows what changed.

* Settings live under RBN Kit → WPSeoKit; the plugin shares the "RBN Kit" top-level menu with the other RBN plugins.
* Needs the AdminKit plugin (shared cache, logs and the RBN Kit menu): without it WPSeoKit switches itself off and says so in the admin.
* Does not run next to Yoast SEO: if Yoast is active, WPSeoKit prints nothing and asks you to remove Yoast first.
* Updates from GitHub are verified with a SHA-256 digest and an Ed25519 signature; the check can be turned off in the Status tab.
* Light: nothing is loaded on the front end except the tags in the page head.

**Titles and descriptions.** A template per content type (post, page, category, tag, search, 404, home, archive) with the usual variables such as `%title%`, `%sitename%`, `%sep%` and `%page%`; every post can override its own SEO title and meta description. A preview of the Google result and a character counter sit in the post editor box.

**Canonical and robots.** One canonical per page, self-referencing on posts. Tag archives, search results, 404 pages and date archives are set to noindex; paginated archives stay indexable. Each post also has its own robots and canonical fields.

**Open Graph and Twitter.** `og:locale`, `og:type`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:image` with its size and type, `article:published_time` and `article:modified_time`; Twitter card with author and reading time.

**XML sitemap.** `/sitemap_index.xml` with post, page and category sitemaps, `lastmod` from the content date and image entries for posts. The robots.txt file gets the `Sitemap:` line, and the WordPress core sitemap is turned off so no page is listed twice. A plain HTML view of each sitemap is served under `/sitemap/` for people; it is noindex and search engines still read the XML.

**Schema graph.** A single `@graph` with WebSite, Organization, WebPage, Article, BreadcrumbList, ImageObject and Person nodes connected by `@id`. On request, a Movie or TVSeries node can be added from the post editor.

**Redirects and 404 log.** 301, 302, 307 and 410 redirects with loop and chain checks, a hit counter, and CSV import and export. The 404 log records the address, the referrer and a salted hash of the IP; a broken address can become a redirect in one click.

**SEO score and recommendations.** A site score and a post score from a fixed rule table, the weight of every rule, what each rule measured, and how the score is calculated. Rules you do not want to see can be hidden; critical failures cap the score.

**Reports.** One snapshot of the numbers each day (score, redirect hits, 404 addresses, sitemap size, title and description coverage, schema types) with a 7, 30 or 90 day trend, a table view, printing and CSV export.

**Feed (RSS) and llms.txt.** Optional: set the item count and content mode of the feed, add a source link and a copyright line to every item, close the feeds nobody reads with 404 or send them to the main feed. `/llms.txt` is generated when it is turned on and returns 404 while it is off.

**Preferred address.** Checks the four versions of the site address (http/https, with and without `www`), says which one answers and how many hops the others need, and writes the Apache or nginx rule you copy into your server; the plugin never edits your server files.

**Yoast import.** Reads your Yoast titles, descriptions, primary categories and settings, shows a dry run and a count first, writes nothing until you confirm, and leaves the Yoast data where it is.

**Site verification.** Meta tag fields for Google Search Console, Bing Webmaster Tools, Yandex Webmaster and Baidu Webmaster.

== Installation ==

1. Upload the `wpseokit.zip` file with Plugins → Add New → Upload Plugin and activate it.
2. Open RBN Kit → WPSeoKit and run the setup check.
3. Review the title and description templates in Templates, then save them.

If Yoast SEO is active, remove and delete it before you activate WPSeoKit; the plugin prints nothing while Yoast is active.

== Frequently Asked Questions ==

= Does it slow down the front end? =
No. On a normal page request it only adds the tags in the head; the sitemap, the feed and `llms.txt` are built when they are requested. The score, the address check, the snapshot and the update check run on demand or on WP-Cron.

= Can I run it next to Yoast SEO or Rank Math? =
Not next to Yoast SEO: while Yoast is active WPSeoKit prints nothing and asks you to remove Yoast first, so no page gets two titles or two canonical tags. Rank Math is not supported either; use only one SEO plugin on a site.

= Do I lose my Yoast data? =
No. The importer is read-only on the Yoast side: it copies titles, descriptions, primary categories and settings into WPSeoKit fields, shows a dry run and a count before writing anything, and can run again. Yoast tables, `postmeta` rows and options are left in place.

= Does it change my .htaccess or my server configuration? =
No. The preferred address screen only writes the Apache or nginx rule for you to copy; WPSeoKit does not edit server files. The optional PHP fallback redirect is off by default and can be turned on for hosts where you cannot add a server rule.

= Does it send anything to an external service? =
Only a release check: at most once a day the plugin asks GitHub for the newest version, and the check can be turned off. No analytics, no account, no API key. The score, the audits and the address check run on your own site.

= What happens when I delete the plugin? =
Settings, tables, post meta and the temporary update records are removed only if you turned on "Delete all WPSeoKit data when the plugin is deleted" in Settings → Data and uninstall. Without that, the plugin files are removed and your data stays.

== Changelog ==

= 0.1.2 =
* Updates are now done by the AdminKit core (one central updater, AdminKit 0.1.1 or newer); the plugin's own updater is removed. The package check (SHA-256 and Ed25519 signature) is unchanged.
* Update checks are logged in the shared `adminkit.updates` channel.

= 0.1.1 =
* First release.
* Titles, meta descriptions and templates per content type, with variables and a per-post override; Google result preview and character counter in the post editor box.
* Canonical and robots per page and per post; tag archives, search, 404 and date archives are noindex.
* Open Graph and Twitter tags.
* XML sitemap with post, page and category sitemaps, `lastmod`, images, robots.txt line, core sitemap off, and an HTML view under `/sitemap/`.
* Schema graph: WebSite, Organization, WebPage, Article, BreadcrumbList, ImageObject, Person; optional Movie and TVSeries nodes.
* Redirects (301, 302, 307, 410) with loop and chain checks, hit counter, CSV import and export; 404 log with a salted IP hash and one-click redirect.
* SEO score and recommendations: site score, post score, rule weights, measured values, hidden recommendations, critical cap.
* Reports: a daily snapshot, 7/30/90 day trend, table view, print and CSV export.
* Feed (RSS) tool: item count, content mode, source and copyright line, closing unused feeds. llms.txt generation, off by default.
* Preferred address: check of the four address versions and Apache/nginx rule text to copy.
* Yoast import with dry run, count report and verification.
* Site verification meta tags for Google, Bing, Yandex and Baidu.
* Signed updates from GitHub (SHA-256 digest and Ed25519 signature).
* English source texts with a Turkish (tr_TR) translation.
