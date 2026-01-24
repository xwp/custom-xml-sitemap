=== Custom XML Sitemap ===
Contributors: xwp
Tags: sitemap, xml, seo, taxonomy, action-scheduler
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

A WordPress plugin that generates taxonomy-filtered, hierarchical XML sitemaps with configurable time-based granularity. Create separate sitemaps for any post type, filter by taxonomy terms, and split content by year, month, or day. Designed for WordPress VIP environments with Action Scheduler integration for automatic regeneration.

Key features:

* **Taxonomy Filtering** - Filter sitemap content by categories, tags, or custom taxonomies
* **Configurable Granularity** - Split sitemaps by year, month, or day
* **Hierarchical Structure** - Index sitemap links to year sitemaps, which link to month/day sitemaps
* **Auto-Regeneration** - Automatic updates via Action Scheduler when content changes
* **robots.txt Integration** - Automatically adds sitemap references
* **WP-CLI Commands** - Full command-line interface for management

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Custom Sitemaps** in the admin menu to create sitemaps.
4. Ensure Action Scheduler is running for automatic sitemap regeneration.

== Frequently Asked Questions ==

= How do I create a sitemap? =
Go to Custom Sitemaps > Add New, enter a title (used as the slug), configure the post type, granularity, and optional taxonomy filters, then publish.

= What URL structure do sitemaps use? =
Sitemaps are accessible at `/sitemaps/{slug}/index.xml` with hierarchical sub-sitemaps based on granularity (year, month, or day).

= Does this work with WordPress VIP? =
Yes, this plugin is designed for WordPress VIP environments. Action Scheduler must be actively running to handle automatic sitemap regeneration when content changes.

= What WP-CLI commands are available? =
The plugin provides `wp cxs list`, `wp cxs generate`, `wp cxs stats`, and `wp cxs validate` commands for sitemap management.

== Changelog ==

= 1.0.0 =
* Initial release.
* Custom post type for sitemap configuration.
* Taxonomy-filtered sitemaps with year/month/day granularity.
* XSL stylesheet support for browser display.
* WP-CLI commands (list, generate, stats, validate).
* Action Scheduler integration for auto-regeneration.
* React-based admin settings panel.
* robots.txt integration.
