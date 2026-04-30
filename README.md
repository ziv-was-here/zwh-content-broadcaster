# Content Broadcaster

**Contributors:** zivrozenberg  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Requires at least:** 5.8  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.0  
**Tested up to:** 6.5

Export and broadcast posts, pages, and custom content between WordPress environments via REST API or portable .zip archives.

## Description

Content Broadcaster is a powerful content syndication and migration plugin that enables seamless content distribution between WordPress sites. Whether you're managing multiple WordPress environments, syndicating content across sites, or needing to migrate posts between staging and production, Content Broadcaster makes it simple.

### Key Features

**REST API Broadcasting** — Send content from one WordPress site to another with a single click, without leaving your post editor.

**Portable .zip Archives** — Export posts as self-contained .zip files with all metadata, taxonomies, featured images, and gallery images included.

**Environment Management** — Configure multiple target environments (production, staging, development) with secure API key authentication.

**Full Content Support** — Works with posts, pages, custom post types, metadata, taxonomies, and attached images.

**API Key Generator** — Securely generate API keys with one click; no manual key creation needed.

**Received Content Management** — View, import, or delete content sent from other environments.

**No Database Bloat** — Temporary exports are cleaned up automatically; received files are stored in your uploads directory.

## Installation

### From WordPress.org Plugin Directory

1. Go to **Plugins → Add New**
2. Search for "Content Broadcaster"
3. Click **Install Now**
4. Click **Activate**

### Manual Installation

1. Download the plugin from WordPress.org
2. Extract to `/wp-content/plugins/`
3. Go to **Plugins** and click **Activate**

## Getting Started

### 1. Configure Target Environments

1. Go to **Tools → Broadcaster → EC Settings**
2. Click **Add Environment**
3. Enter:
   - **Environment Name** — e.g., "Production" or "Staging"
   - **Environment Type** — dev, qa, staging, or production
   - **Site URL** — The target WordPress site URL
   - **API Key** — Click the **🔑 Gen** button to auto-generate
4. Save

### 2. Send Content to Another Site

1. Edit any post or page
2. Find **"Send via API"** in the right sidebar
3. Check the environments you want to send to
4. Click **"Send via API"**
5. Go to the target site's **Tools → Broadcaster → Received Content**
6. Click **Import** to add the content

### 3. Manual Export and Import (ZIP)

If you don't want to use the API or are moving content between disconnected sites:

**To Export:**
1. Go to **Broadcaster → Broadcaster** (Top-level menu).
2. On the **Export** tab, select the post you want to package.
3. Click **Export to Zip**.
4. Once ready, click **Download Zip Archive**.

**To Import:**
1. Go to the target site's **Broadcaster → Broadcaster**.
2. Click the **Import** tab.
3. Upload the `.zip` file you downloaded.
4. (Optional) Choose to override the post status (e.g., force to Draft).
5. Click **Import Archive**.

### 4. Import Received Content (API)

## How It Works

### Broadcasting via REST API

- **Sender:** Exports post content (including featured image and all metadata) as a .zip file
- **Transmission:** Uses `wp_remote_post()` to send the file to the target site's REST API endpoint
- **Authentication:** Validates via API key stored in target environment settings
- **Receiver:** Accepts the file at `/wp-json/content-broadcaster/v1/receive`
- **Storage:** Saves received files to `/wp-content/uploads/content-broadcaster-received/`
- **Import:** User can import received content as draft posts

### Portable .zip Archives

Export posts as .zip files containing:
- `post-data.json` — Post content, metadata, and taxonomy assignments
- `images/` — Featured image and gallery images
- `README.md` — Export metadata and instructions

Perfect for:
- Manual site-to-site transfers
- Content archiving
- Backup workflows
- Email distribution

## Architecture

The plugin consists of four main components:

**CB_Exporter** — Packages post content into .zip archives  
**CB_Importer** — Unpacks .zip files and imports posts  
**CB_API_Sender** — Broadcasts content via REST API using `wp_remote_post()`  
**EC_Settings** — Central environment and API key management  

### REST API Endpoint

**Endpoint:** `POST /wp-json/content-broadcaster/v1/receive`  
**Required Headers:** `X-CB-API-Key: {api_key}`  
**Body:** Multipart form data with `file` and metadata

## Security

**API Key Validation** — Uses PHP's `hash_equals()` for timing-attack-resistant comparison  
**Nonce Verification** — All admin actions protected with WordPress nonces  
**File Validation** — Received .zip files validated before import  
**Permission Checks** — Only users with `manage_options` can broadcast or import  
**Sanitization** — All user input sanitized; output escaped  
**No Credentials Stored** — API keys stored securely in `wp_options`; no passwords transmitted  

## Multisite Support

Content Broadcaster is **not** a multisite-specific plugin but works on multisite installations. Each site maintains its own environments and API keys.

## Troubleshooting

### "Templates not loading" or "400 Bad Request"

This is usually a theme or plugin conflict. Try:
1. Switch to a default WordPress theme temporarily
2. Disable non-essential plugins
3. Check **Tools → Site Health** for REST API errors

### API Key validation failing

- Ensure the API key is copied exactly (including the `kb_` prefix)
- Verify the key hasn't been modified in the database
- Regenerate the key using the **🔑 Gen** button

### Received content not importing

- Check that the .zip file wasn't corrupted during transmission
- Verify user permissions (importer needs `manage_options`)
- Review browser console for JavaScript errors

### Images not sending

- Ensure image files exist in the source post
- Check that the target site's `/wp-content/uploads/` directory is writable
- Verify image file sizes don't exceed server limits

## Frequently Asked Questions

**Can I use this for WooCommerce products?**

Yes! Content Broadcaster works with any post type, including WooCommerce products. Product metadata and images are included in the export.

**Is there a limit to content size?**

No hard limit, but very large exports (100+ MB) may hit server timeouts. For large migrations, consider splitting into smaller batches.

**Can I schedule broadcasts?**

Not built-in, but you can trigger broadcasts via the REST API and use a cron job scheduler.

**Does this replace WordPress core import/export?**

No, it complements it. Use WordPress's importer for one-time bulk migrations; use Content Broadcaster for ongoing syndication and API-based workflows.

**Can I use this for content syndication?**

Yes! Content Broadcaster is ideal for:
- Syndicating posts from a central hub to branch sites
- Pushing news/updates across multiple WordPress sites
- Distributing content to topic-specific subsites

**Is there a limit to how many environments I can configure?**

No, you can configure as many target environments as needed.

**What post types are supported?**

All post types (posts, pages, products, custom types) are supported, including metadata, taxonomies, and images.

## API for Developers

### Sending Content Programmatically

```php
require_once CB_PLUGIN_DIR . 'includes/class-cb-api-sender.php';

$post_id = 42;
$environment_ids = [ 0, 1 ]; // Array indices from EC_Settings

CB_API_Sender::send_to_environments( $post_id, $environment_ids );
```

### REST API Endpoint

**POST** `/wp-json/content-broadcaster/v1/receive`

**Headers:**
```
X-CB-API-Key: kb_xxxxxxxxxxxx_xxxxxxxxxxxx_xxxxxxxx
```

**Body:** Multipart form data
```
api_key: kb_xxxxxxxxxxxx_xxxxxxxxxxxx_xxxxxxxx
source_env: Production
file: <binary .zip file>
```

**Response:** `200 OK`
```json
{
  "success": true,
  "message": "File received and stored.",
  "file_id": "file_1234567890"
}
```

## Support

For issues, questions, or feature requests:
- **GitHub Issues:** [Report bugs](https://github.com/Roziv/content-broadcaster/issues)
- **Support Forum:** [WordPress.org Plugin Forum](https://wordpress.org/support/plugin/content-broadcaster/)
- **Documentation:** See the plugin's admin pages for detailed guides

## Contributing

Contributions are welcome! Please submit pull requests on [GitHub](https://github.com/Roziv/content-broadcaster).

## Changelog

### 1.0.0 (2026-04-29)
- Initial release
- REST API broadcasting
- Portable .zip archive export/import
- Environment management with API keys
- Received content admin page
- Send via API metabox on post editor

## License

This plugin is licensed under the GPL-2.0-or-later license. See `LICENSE.md` for details.

---

Made with ❤️ by [zivwashere](https://zivwashere.com)
