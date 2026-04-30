# Changelog

All notable changes to Content Broadcaster will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-29

### Added
- Initial public release
- REST API endpoint for receiving content from other WordPress sites
- One-click "Send via API" metabox on post/page editor
- Environment management interface (EC Settings) with support for unlimited target environments
- API key generator with secure key format (`kb_` prefix + random alphanumeric)
- Portable .zip archive export with all post metadata, taxonomies, and images
- Received content admin page with import/delete functionality
- ZipArchive validation for received files
- Comprehensive security: nonce verification, permission checks, sanitization/escaping, timing-attack-resistant key validation
- Support for all post types (posts, pages, products, custom types)
- Featured image and gallery image inclusion in exports
- Database options storage for environment configuration
- Received files directory management with `.htaccess` protection

### Security
- Nonce verification on all form submissions
- Permission checks (manage_options capability required)
- Input sanitization via `sanitize_text_field()`, `esc_url_raw()`
- Output escaping via `esc_html()`, `esc_attr()`
- API key validation using timing-attack-resistant `hash_equals()`
- Directory protection with `.htaccess` rules to prevent direct HTTP access

### Infrastructure
- Multipart form data handling for file uploads via REST API
- WordPress REST API v2 compliant endpoint routing
- Proper HTTP status codes and error responses
- Transient-based flash messages for admin feedback

---

## Future Roadmap

### Planned Features
- Schedule broadcasts for future delivery
- Content transformation hooks for extensibility
- Batch broadcasting UI
- REST API webhooks for automated triggers
- Advanced logging and audit trail
- Scheduled sync between environments
- Content versioning and rollback
- Selective field broadcasting (e.g., publish only specific metabox data)

### Under Consideration
- WooCommerce integration with product variants and stock sync
- Gravity Forms response broadcasting
- Custom post type template matching
- Automatic image optimization on receive
- Content transformation rules (find/replace URLs, etc.)
