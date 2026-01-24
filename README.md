# Custom XML Sitemap

[![Test](https://github.com/xwp/custom-xml-sitemap/actions/workflows/test.yml/badge.svg)](https://github.com/xwp/custom-xml-sitemap/actions/workflows/test.yml)

A WordPress plugin that generates taxonomy-filtered, hierarchical XML sitemaps with configurable time-based granularity.

## How it Works

The plugin creates a custom post type for defining sitemap configurations. Each sitemap can target a specific post type, filter by taxonomy terms, and split content by year, month, or day granularity. Generated XML is cached and served via custom rewrite rules with XSL stylesheets for browser-friendly display.

Key features:
- **Taxonomy Filtering** - Filter sitemap content by categories, tags, or custom taxonomies
- **Configurable Granularity** - Split sitemaps by year, month, or day
- **Hierarchical Structure** - Index sitemap links to year sitemaps, which link to month/day sitemaps
- **Auto-Regeneration** - Automatic updates via Action Scheduler when content changes
- **robots.txt Integration** - Automatically adds sitemap references

## Requirements

- PHP 8.4+
- WordPress 6.0+
- [Action Scheduler](https://actionscheduler.org/) (bundled with the plugin)

### WordPress VIP

This plugin is designed for WordPress VIP environments. Action Scheduler must be actively running to handle automatic sitemap regeneration when content changes. On VIP, Action Scheduler runs via the cron system which is managed by the platform.

For local development or non-VIP environments, ensure Action Scheduler jobs are being processed either via WP-Cron or by running:

```bash
wp action-scheduler run
```

## Installation

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Custom Sitemaps** in the admin menu to create sitemaps.

## Sitemap URLs

Once a sitemap is published, it's accessible at:

```
/sitemaps/{slug}/index.xml              # Main index
/sitemaps/{slug}/{year}.xml             # Year index
/sitemaps/{slug}/{year}/{month}.xml     # Month sitemap (monthly granularity)
/sitemaps/{slug}/{year}/{month}/{day}.xml  # Day sitemap (daily granularity)
```

## WP-CLI Commands

### List Sitemaps
```bash
wp cxs list [--format=<table|json|csv>]
```

### Generate Sitemaps
```bash
wp cxs generate [<sitemap-slug>] [--all] [--year=<year>] [--dry-run]
```

### Show Statistics
```bash
wp cxs stats [<sitemap-slug>] [--format=<table|json|csv>]
```

### Validate Sitemaps
```bash
wp cxs validate <sitemap-slug> [--verbose]
```

## Developer Hooks

### `cxs_sitemap_post_types`
Filter available post types for sitemap configuration.

```php
add_filter( 'cxs_sitemap_post_types', function( $post_types ) {
    unset( $post_types['page'] );
    return $post_types;
} );
```

### `cxs_sitemap_url_entry`
Modify individual URL entries in the sitemap.

```php
add_filter( 'cxs_sitemap_url_entry', function( $entry, $post ) {
    // Add custom elements to each URL entry
    return $entry;
}, 10, 2 );
```

### `cxs_sitemap_generated`
Triggered after a sitemap is regenerated.

```php
add_action( 'cxs_sitemap_generated', function( $sitemap_id, $stats ) {
    // Custom logic after sitemap generation
}, 10, 2 );
```

## Local Development & Testing

### Installation
```bash
composer install
npm install
```

### Running Tests
```bash
# Start wp-env Docker environment
npm run env:start

# Run PHPUnit tests
npm run test:php

# Run PHP Code Sniffer
composer lint

# Run PHPStan
composer phpstan
```

### Build Assets
```bash
npm run build
```

## License

GPLv2 or later.
