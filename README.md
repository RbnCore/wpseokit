# WPSeoKit

WPSeoKit is an SEO toolkit for a single WordPress site: it writes the tags search engines read, keeps an XML sitemap, closes the addresses that should not be indexed, redirects the ones that moved, and shows what changed. It does the everyday work of an SEO plugin without an account, an API key or an outside SEO service.

## AdminKit is required

WPSeoKit does not work without the **AdminKit** plugin. Its header declares `Requires Plugins: adminkit`.

- On WordPress 6.5 and later, WordPress refuses to activate WPSeoKit while AdminKit is missing or inactive.
- A second check runs on every request: without AdminKit, or with an AdminKit older than core API level 1, WPSeoKit adds no hook, prints nothing and shows one admin notice with an "Activate AdminKit" or "Get AdminKit" link.
- Install and activate **AdminKit first**, then WPSeoKit.

AdminKit supplies the RBN Kit menu, the admin interface kit, the shared log list, six cache groups (`wpseokit.probe`, `wpseokit.sitemap`, `wpseokit.llms`, `wpseokit.score`, `wpseokit.updates`, `wpseokit.maintenance`), four log channels (`wpseokit.activity`, `wpseokit.errors`, `wpseokit.updates`, `wpseokit.yoast`) and the network switch that decides whether WPSeoKit may ask GitHub for updates.

## Who it is for

Site owners and editors who need SEO titles, descriptions, canonicals, a sitemap and redirects on one WordPress site, and who do not want a SaaS account or a second dashboard for it. It is also for sites leaving Yoast SEO: the importer reads the Yoast data once and leaves it where it is.

## Features

- **Titles and meta descriptions.** A template per content type — post types, categories and tags, home, search, 404, date and author archives — with variables such as `%title%`, `%sitename%`, `%sep%` and `%page%`; every post and term can override its own. The post editor box shows a preview of the Google result and a character counter.
- **Canonical and robots.** One canonical per page, self-referencing on posts; paginated archives canonicalise to their own page and there is no rel prev/next. Tag archives, search, 404 and date archives are noindex, paginated archives stay indexable. Each post has its own robots and canonical fields.
- **Open Graph and Twitter.** `og:*` tags with image size and type and the article times, plus a Twitter card with author and reading time. A default social image can be set.
- **Primary category.** A post keeps one primary category; the permalink and the breadcrumb follow it through the `post_link_category` hook, and the schema lists the post's categories in `articleSection`.
- **XML sitemap.** `/sitemap_index.xml` with post, page and category sitemaps, `lastmod` from the content date and image entries. WordPress's own `/wp-sitemap.xml` is switched off so no page is listed twice, and robots.txt gets the `Sitemap:` line. A plain HTML view of each sitemap is served under `/sitemap/` for people; it is noindex, and the XML stays pure for search engines.
- **Schema graph.** One `@graph` with WebSite, Organization (or Person), WebPage, Article, BreadcrumbList, ImageObject and Person nodes linked by `@id`. The post editor can add a Movie or TVSeries node by hand.
- **Redirects.** 301, 302, 307 and 410 by exact path, with loop and chain checks, a hit counter, and CSV import and export. Wildcards, regular expressions and 451 are not supported.
- **404 log.** The path, the referrer and a salted SHA-256 of the visitor's address — the address itself is never stored — with a hit counter, pruning by age and row count, and a one-click redirect from a logged address. Requests for scanner paths such as `/wp-login.php` are skipped by default.
- **SEO score and recommendations.** A site score and a post score from a fixed rule table, with the weight of every rule, what it measured and how the score is calculated. Rules you do not want to see can be hidden; critical failures cap the score.
- **Reports.** One snapshot of the numbers each day with a 7, 30 or 90 day trend, a table view, printing and CSV export.
- **Feed (RSS) tool.** Item count and content mode, a source link and a copyright line on every item, closing the feeds nobody reads with 404 or a redirect to the main feed, and an optional `noindex` header. The defaults leave WordPress's own feed output unchanged.
- **llms.txt.** A plain-text site summary for AI systems, generated on request. Off by default: the address answers 404 while it is off.
- **Preferred address.** Checks the four versions of the site address (http/https, with and without `www`), says which one answers and how many hops the others need, and writes the Apache or nginx rule for you to copy. It never edits your server files; an optional PHP fallback redirect is off by default.
- **Yoast import.** Reads Yoast titles, descriptions, primary categories and settings, shows a dry run and a count first, writes nothing until you confirm, and leaves the Yoast data where it is.
- **Site verification.** Meta tag fields for Google Search Console, Bing Webmaster Tools, Yandex Webmaster and Baidu Webmaster.
- **Author and attachment archives.** Author archives can be redirected to the home page and attachment pages to their parent post; attachment pages are redirected by default, author archives are not.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later
- The AdminKit plugin (`Requires Plugins: adminkit`)
- A standard WordPress database; the plugin creates its own `{prefix}seokit_redirects`, `{prefix}seokit_404_logs` and `{prefix}seokit_snapshots` tables

## Installation

1. Install and activate **AdminKit** first; WPSeoKit cannot be activated without it.
2. Download `wpseokit-0.1.1.zip` from [Releases](https://github.com/RbnCore/wpseokit/releases).
3. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.
4. Open **RBN Kit → WPSeoKit** and run the setup check.
5. Review the title and description templates in **Templates**, then save them.

If Yoast SEO is active, remove and delete it before you activate WPSeoKit: while Yoast is active the plugin prints nothing and asks you to remove Yoast first.

You can also copy the `wpseokit` folder into `wp-content/plugins/`.

## Updating

Updates come from GitHub and appear on the Plugins page like any WordPress update; automatic updates are supported as well.

- **Source**: only `github.com/RbnCore/wpseokit` releases. The request is unauthenticated, times out after 5 seconds, is cached for 12 hours, and sends only `User-Agent: WPSeoKit/<version>`. If GitHub cannot be reached, no error is shown and the plugin keeps working.
- **Verification is always on**: the package's SHA-256 digest and its Ed25519 signature are checked before installation, and the zip must contain `wpseokit/` and `wpseokit.php`. A package that fails the check is not installed and the installed version stays as it was.
- **Turning it off**: the check follows the central RBN Kit network setting (RBN Kit → AdminKit → General → Network). While it is off, no request goes to GitHub. "Check now" asks again immediately and skips the cache.
- **Manual update**: upload the new `wpseokit-X.Y.Z.zip` with Plugins → Add New → Upload Plugin and choose *Replace current*. Settings, redirects, the 404 log and snapshots are kept: the database schema is additive.

## Privacy and external requests

- The plugin sends nothing to a third-party service and no telemetry. There is no account, no API key and no tracking.
- The only external host it contacts is GitHub, for the update check described above, and that follows the central RBN Kit network setting.
- The score, the preferred address check and the 404 log look at **your own site only**: robots.txt, the sitemap and page addresses are requested over HTTP with a timeout and the answers are cached. No other host is contacted.
- The 404 log stores no raw IP address, only a salted SHA-256; the path and referrer are stored without their query string. Old rows are removed by age and row count.
- On uninstall the data is kept by default. If "Delete all WPSeoKit data when the plugin is deleted" is ticked first, the tables, the settings, the post and term meta and the plugin's log lines are removed.

## Translation

English is the source language; a Turkish (`tr_TR`) translation ships in `languages/`. The language you see follows your profile language (Users → Profile → Language), not only the site language. The shared **RBN Kit** menu is translated through its own text domain, `rbn-kit`.

## RBN Kit menu

The screens live under the shared **RBN Kit** top-level menu, just below Dashboard, as **RBN Kit → WPSeoKit**. The menu itself comes from AdminKit, and the item order is fixed across RBN plugins (Overview → AdminKit → Cookies and Consent → Content Health → SEO Toolkit).

## Known limits

- **No focus keyword or content score.** Readability and keyword analysis are left out; ContentHealth covers content checks separately.
- **No HTML breadcrumb.** The plugin writes the `BreadcrumbList` schema and offers `wpseokit_breadcrumb_items()` for a theme to draw the trail; it prints no breadcrumb of its own.
- **Not next to Yoast SEO or Rank Math.** While Yoast is active the plugin prints nothing; Rank Math is not supported. Use one SEO plugin on a site.
- **Redirects are exact-path only.** No wildcards, no regular expressions, no 451 status.
- **No Search Console, Analytics or WooCommerce integration.** The plugin does not talk to Google services and adds nothing for shop pages.
- **Sitemap coverage**: posts, pages and categories. Tag, author and date archives are not listed.

## Version and license

- Version 0.1.1
- License: GPL-2.0-or-later (see [LICENSE](LICENSE))
- Text domain: `wpseokit`

## Development notes

- This repository is the plugin folder itself. The release package is a git archive of the repository root with the `wpseokit/` prefix, so only files that belong in the package are committed here.
- Structure: `wpseokit.php` holds the header and constants; `includes/` holds the services (one class per job), the admin screens, the score rules, the repositories and models, and the REST routes; `assets/admin/` holds the admin CSS and JS; `languages/` holds the `.pot` and the Turkish translation.
- `includes/Gate.php` is the runtime AdminKit check. It never touches an AdminKit class, so it works while AdminKit is missing.
- The updater is a self-contained class: copy it to another plugin by changing the constants (repository, plugin file, slug, prefix). It offers updates through WordPress's own `update_plugins_github.com` filter and never runs on the front end.
- The signing key is never in the repository or in the package, and tests, documentation and release tooling live in the project tree outside this repository.

## Links

- Repository: https://github.com/RbnCore/wpseokit
- Releases: https://github.com/RbnCore/wpseokit/releases
- Issues: https://github.com/RbnCore/wpseokit/issues
- Author: https://github.com/RbnCore
