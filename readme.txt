=== WPSeoKit — SEO Toolkit ===
Contributors: rbnbilisim
Tags: seo, sitemap, schema, redirects, open graph
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Link: https://github.com/RbnCore

SEO titles and meta descriptions, canonical and robots, Open Graph, XML sitemap, schema graph, redirects and a 404 log. Connects to GitHub only to check for updates (can be turned off in the settings).

== Description ==

* Settings live under RBN Kit → WPSeoKit; the plugin shares the "RBN Kit" top-level menu with the other RBN plugins.
* Does not run next to Yoast SEO: if Yoast is active, WPSeoKit prints nothing and asks you to remove Yoast first.
* Updates from GitHub are verified with a SHA-256 digest and an Ed25519 signature; the check can be turned off in the Status tab.
* Light: nothing is loaded on the front end except the tags in the page head.

== Installation ==

1. Upload the `wpseokit.zip` file with Plugins → Add New → Upload Plugin and activate it.
2. Open RBN Kit → WPSeoKit to check the status.

== Changelog ==

= 0.1.1 =
* First release: plugin skeleton, settings store, RBN Kit menu entry, signed GitHub updater.
