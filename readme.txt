=== ZWH Content Broadcaster ===
Contributors: zivrozenberg
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: content-syndication, migration, rest-api, export, import
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4

Export and broadcast posts, pages, and custom content between WordPress environments via REST API or portable .zip archives.

== Description ==

Content Broadcaster is a powerful content syndication and migration plugin that enables seamless content distribution between WordPress sites. Whether you're managing multiple WordPress environments, syndicating content across sites, or needing to migrate posts between staging and production, Content Broadcaster makes it simple.

=== Key Features ===

* **REST API Broadcasting** — Send content from one WordPress site to another with a single click, without leaving your post editor.
* **Portable .zip Archives** — Export posts as self-contained .zip files with all metadata, taxonomies, featured images, and gallery images included.
* **Environment Management** — Configure multiple target environments (production, staging, development) with secure API key authentication.
* **Full Content Support** — Works with posts, pages, custom post types, metadata, taxonomies, and attached images.
* **API Key Generator** — Securely generate API keys with one click; no manual key creation needed.
* **Received Content Management** — View, import, or delete content sent from other environments.

== Installation ==

1. Upload the `content-broadcaster` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your environments under **Tools -> Broadcaster -> EC Settings**.

== Frequently Asked Questions ==

= Can I use this for WooCommerce products? =

Yes! Content Broadcaster works with any post type, including WooCommerce products. Product metadata and images are included in the export.

= Is there a limit to content size? =

No hard limit, but very large exports (100+ MB) may hit server timeouts. For large migrations, consider splitting into smaller batches.

= Does this replace WordPress core import/export? =

No, it complements it. Use WordPress's importer for one-time bulk migrations; use Content Broadcaster for ongoing syndication and API-based workflows.

== Screenshots ==

1. The main Broadcaster page with Export and Import tabs.
2. The Environment Settings page where you configure target sites.
3. The "Send via API" metabox on the post editor screen.
4. The Received Content page showing files sent from other sites.

== Changelog ==

= 1.0.0 =
* Initial release.
* REST API broadcasting.
* Portable .zip archive export/import.
* Environment management with API keys.
* Received content admin page.
* Send via API metabox on post editor.

== Upgrade Notice ==

= 1.0.0 =
This is the initial release. No upgrade needed.
