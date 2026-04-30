<?php
/**
 * Class CB_Importer
 *
 * The core engine responsible for:
 *   1. Receiving an uploaded .zip file and validating it.
 *   2. Extracting the archive to a temporary directory via unzip_file().
 *   3. Parsing and validating manifest.json.
 *   4. Sideloading bundled images into the Media Library via media_handle_sideload(),
 *      building an old-URL → new-URL map as each image is processed.
 *   5. Rewriting all old image URLs inside post_content with their new local URLs.
 *   6. Inserting the post as a brand-new entry via wp_insert_post() — the original ID
 *      is never reused, ensuring safe coexistence on any target site.
 *   7. Setting the featured image, applying all post meta, and assigning taxonomy terms.
 *   8. Cleaning up the temporary extraction directory.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Importer {

    // ── Private State ────────────────────────────────────────────────────────

    /**
     * Absolute path to the temporary directory created for this import run.
     * Cleaned up in __destruct() regardless of success or failure.
     *
     * @var string
     */
    private string $tmp_dir = '';

    /**
     * Decoded manifest data from manifest.json inside the zip.
     *
     * @var array<string, mixed>
     */
    private array $manifest = [];

    /**
     * Image URL rewrite map built during sideload.
     * Structure: [ 'https://source-site.com/wp-content/uploads/photo.jpg' => 'https://target-site.com/wp-content/uploads/photo.jpg' ]
     *
     * @var array<string, string>
     */
    private array $url_map = [];

    /**
     * The new local attachment ID that corresponds to the exported featured image.
     * 0 means the post had no featured image, or sideload failed for it.
     *
     * @var int
     */
    private int $new_thumbnail_id = 0;

    /**
     * Non-fatal warnings accumulated throughout the import run.
     *
     * @var string[]
     */
    private array $errors = [];

    /**
     * Attachment ID rewrite map built during sideload.
     * Structure: [ old_attachment_id => new_attachment_id ]
     * Used by process_relational_data() to remap IDs embedded in meta fields.
     *
     * @var array<int, int>
     */
    private array $id_map = [];

    /**
     * Optional post status override supplied via the import form.
     * When non-empty this overrides the status stored in the manifest.
     * Accepted values: 'draft', 'publish', '' (empty = use manifest value).
     *
     * @var string
     */
    private string $status_override = '';

    // ── Destructor ───────────────────────────────────────────────────────────

    /**
     * Guarantee that the temporary extraction directory is always removed,
     * even if an exception is thrown partway through the import.
     *
     * @since 1.0.0
     */
    public function __destruct() {
        $this->cleanup_tmp_dir();
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Main import entry-point.
     *
     * Accepts the $_FILES entry for the uploaded zip, runs the full import
     * pipeline, and returns a structured result array suitable for display
     * in the admin UI.
     *
     * @param  array  $file_entry       A single entry from $_FILES, e.g. $_FILES['cb_zip_file'].
     * @param  string $status_override  Optional. Force this post status on the imported post.
     * @return array{
     *     success: bool,
     *     new_post_id: int,
     *     post_title: string,
     *     edit_url: string,
     *     view_url: string,
     *     images_imported: int,
     *     errors: string[]
     * }
     *
     * @since 1.0.0
     */
    public function import( array $file_entry, string $status_override = '' ): array {
        $this->status_override = sanitize_key( $status_override );

        // ── 1. Validate the upload ────────────────────────────────────────
        $validation = $this->validate_upload( $file_entry );
        if ( ! $validation['valid'] ) {
            return $this->fail( $validation['error'] );
        }

        return $this->import_from_path( $file_entry['tmp_name'], $file_entry['name'] );
    }

    /**
     * Import a ZIP file already present on the server.
     *
     * @param string $file_path       Absolute path to the ZIP file.
     * @param string $original_name   Optional. Original filename for validation/logging.
     * @param string $status_override Optional. Force this post status.
     * @return array See import() for return shape.
     *
     * @since 1.0.0
     */
    public function import_file( string $file_path, string $original_name = '', string $status_override = '' ): array {
        $this->status_override = sanitize_key( $status_override );

        if ( ! file_exists( $file_path ) ) {
            return $this->fail( "File not found: {$file_path}" );
        }

        return $this->import_from_path( $file_path, $original_name ?: basename( $file_path ) );
    }

    /**
     * Internal engine shared by import() and import_file().
     *
     * @param string $zip_path      Path to the ZIP file.
     * @param string $original_name Filename for logging.
     * @return array See import() for return shape.
     */
    private function import_from_path( string $zip_path, string $original_name ): array {
        // ── 2. Extract the zip to a temp directory ────────────────────────
        $extract_result = $this->extract_zip( $zip_path );
        if ( ! $extract_result['success'] ) {
            return $this->fail( $extract_result['error'] );
        }

        // ── 3. Read and validate manifest.json ────────────────────────────
        $manifest_result = $this->load_manifest();
        if ( ! $manifest_result['success'] ) {
            return $this->fail( $manifest_result['error'] );
        }

        // ── 4. Sideload images into the Media Library ─────────────────────
        $images_imported = $this->sideload_images();

        // ── 5. Rewrite URLs and insert the post ───────────────────────────
        $new_post_id = $this->insert_post();
        if ( is_wp_error( $new_post_id ) ) {
            return $this->fail( 'wp_insert_post() failed: ' . $new_post_id->get_error_message() );
        }

        // ── 6. Attach featured image ───────────────────────────────────────
        $this->set_featured_image( $new_post_id );

        // ── 7. Apply post meta ────────────────────────────────────────────
        $this->apply_post_meta( $new_post_id );

        // ── 8. Assign taxonomy terms ──────────────────────────────────────
        $this->apply_taxonomies( $new_post_id );

        return [
            'success'        => true,
            'new_post_id'    => $new_post_id,
            'post_title'     => get_the_title( $new_post_id ),
            'edit_url'       => get_edit_post_link( $new_post_id, 'raw' ),
            'view_url'       => get_permalink( $new_post_id ),
            'images_imported'=> $images_imported,
            'errors'         => $this->errors,
        ];
    }

    // ── Step 1: Upload Validation ─────────────────────────────────────────────

    /**
     * Validates the uploaded file array before touching the filesystem.
     *
     * Checks:
     *  - No PHP upload error occurred.
     *  - The file has a .zip extension.
     *  - The MIME type reported by PHP is for a zip archive.
     *
     * @param  array $file_entry  Entry from $_FILES.
     * @return array{valid: bool, error?: string}
     *
     * @since 1.0.0
     */
    private function validate_upload( array $file_entry ): array {
        // PHP upload error codes (0 = UPLOAD_ERR_OK).
        if ( ! isset( $file_entry['error'] ) || $file_entry['error'] !== UPLOAD_ERR_OK ) {
            $code = $file_entry['error'] ?? 'unknown';
            return [ 'valid' => false, 'error' => "File upload error (code: {$code}). Check your PHP upload_max_filesize and post_max_size settings." ];
        }

        if ( empty( $file_entry['tmp_name'] ) || ! is_uploaded_file( $file_entry['tmp_name'] ) ) {
            return [ 'valid' => false, 'error' => 'No valid uploaded file was found.' ];
        }

        // Extension check.
        $ext = strtolower( pathinfo( $file_entry['name'] ?? '', PATHINFO_EXTENSION ) );
        if ( $ext !== 'zip' ) {
            return [ 'valid' => false, 'error' => "Expected a .zip file, received '.{$ext}'." ];
        }

        // MIME check using WordPress's own MIME type detection.
        $allowed_mime = [ 'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'multipart/x-zip' ];
        $filetype     = wp_check_filetype( $file_entry['name'] );

        // wp_check_filetype() may return 'application/octet-stream' for zips on some systems;
        // fall back to finfo if available for a second opinion.
        $finfo_mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $finfo      = finfo_open( FILEINFO_MIME_TYPE );
            $finfo_mime = finfo_file( $finfo, $file_entry['tmp_name'] );
            finfo_close( $finfo );
        }

        $detected_mime = $finfo_mime ?: ( $filetype['type'] ?? '' );

        if ( ! empty( $detected_mime ) && ! in_array( $detected_mime, $allowed_mime, true ) && $detected_mime !== 'application/octet-stream' ) {
            return [ 'valid' => false, 'error' => "MIME type '{$detected_mime}' does not look like a zip archive." ];
        }

        return [ 'valid' => true ];
    }

    // ── Step 2: Extraction ────────────────────────────────────────────────────

    /**
     * Creates a uniquely named temporary directory inside the WP uploads folder
     * and extracts the zip archive into it using WP's unzip_file() wrapper.
     *
     * unzip_file() uses ZipArchive or PclZip depending on what PHP supports,
     * so we lean on core rather than calling ZipArchive directly.
     *
     * @param  string $zip_tmp_path  Absolute path to the uploaded temp file.
     * @return array{success: bool, error?: string}
     *
     * @since 1.0.0
     */
    private function extract_zip( string $zip_tmp_path ): array {
        // We need WP_Filesystem for unzip_file().
        if ( ! function_exists( 'unzip_file' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        // Build a unique extraction directory so parallel imports can't collide.
        $upload_tmp    = trailingslashit( wp_upload_dir()['basedir'] ) . 'cb-import-tmp/';
        $this->tmp_dir = $upload_tmp . 'import-' . wp_generate_password( 12, false ) . '/';

        if ( ! wp_mkdir_p( $this->tmp_dir ) ) {
            return [ 'success' => false, 'error' => "Could not create temporary directory: {$this->tmp_dir}" ];
        }

        $result = unzip_file( $zip_tmp_path, $this->tmp_dir );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'error' => 'unzip_file() failed: ' . $result->get_error_message() ];
        }

        return [ 'success' => true ];
    }

    // ── Step 3: Manifest ──────────────────────────────────────────────────────

    /**
     * Reads and decodes manifest.json from the extracted temporary directory.
     *
     * Validates that the file exists, is readable, contains valid JSON, and
     * has the required top-level keys.
     *
     * @return array{success: bool, error?: string}
     *
     * @since 1.0.0
     */
    private function load_manifest(): array {
        $manifest_path = $this->tmp_dir . 'manifest.json';

        if ( ! file_exists( $manifest_path ) ) {
            return [ 'success' => false, 'error' => 'manifest.json not found inside the zip. This archive may not be a valid Content Broadcaster export.' ];
        }

        $raw = file_get_contents( $manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( $raw === false ) {
            return [ 'success' => false, 'error' => 'Could not read manifest.json.' ];
        }

        $decoded = json_decode( $raw, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [ 'success' => false, 'error' => 'manifest.json is not valid JSON: ' . json_last_error_msg() ];
        }

        // Verify required top-level keys are present.
        $required = [ 'post', 'post_meta', 'taxonomies', 'images' ];
        foreach ( $required as $key ) {
            if ( ! array_key_exists( $key, $decoded ) ) {
                return [ 'success' => false, 'error' => "manifest.json is missing required key: '{$key}'." ];
            }
        }

        $this->manifest = $decoded;

        return [ 'success' => true ];
    }

    // ── Step 4: Image Sideloading ─────────────────────────────────────────────

    /**
     * Iterates over the image map in the manifest and sideloads every bundled
     * (non-external) image into the target site's Media Library.
     *
     * For each successfully sideloaded image:
     *  - Stores old URL → new URL in $this->url_map for content rewriting.
     *  - Identifies the featured image and stores its new attachment ID.
     *
     * Requires wp-admin/includes/image.php and media.php for
     * media_handle_sideload() and wp_generate_attachment_metadata().
     *
     * @return int  Number of images successfully imported.
     *
     * @since 1.0.0
     */
    private function sideload_images(): int {
        // Bring in the Media Library helpers — they're not loaded on every admin request.
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $images         = $this->manifest['images'] ?? [];
        $images_imported = 0;

        // We need to know the original featured image URL to recognise it in the loop.
        // The exporter records featured image data as the first entry with attachment_id.
        // The cleanest signal is stored in post_meta: _thumbnail_id holds the old attachment ID.
        // We'll resolve this after all images are loaded (see set_featured_image()).

        foreach ( $images as $image_entry ) {
            $original_url = $image_entry['original_url'] ?? '';
            $archive_path = $image_entry['archive_path'] ?? null; // null = external
            $is_external  = ! empty( $image_entry['external'] );

            if ( empty( $original_url ) ) {
                continue;
            }

            // External images are not bundled — leave their URLs in the content as-is.
            if ( $is_external || empty( $archive_path ) ) {
                $this->errors[] = "Skipping external image (not bundled): {$original_url}";
                continue;
            }

            $local_file_path = $this->tmp_dir . $archive_path;

            if ( ! file_exists( $local_file_path ) ) {
                $this->errors[] = "Bundled image not found in archive: {$archive_path} (expected for URL: {$original_url}).";
                continue;
            }

            // media_handle_sideload() expects a file array in the shape of $_FILES.
            // We must provide a 'name' key so WP can infer the MIME type and apply
            // the correct file extension on the target side.
            $file_array = [
                'name'     => basename( $local_file_path ),
                'tmp_name' => $local_file_path,
                // Do NOT set 'error' — letting it default to UPLOAD_ERR_OK (0).
            ];

            // Sideload the file. Passing 0 as post ID means the attachment will initially
            // be unattached; we'll attach it to the new post via set_post_thumbnail / content.
            $new_attachment_id = media_handle_sideload( $file_array, 0 );

            if ( is_wp_error( $new_attachment_id ) ) {
                $this->errors[] = "media_handle_sideload() failed for '{$original_url}': " . $new_attachment_id->get_error_message();
                continue;
            }

            // Resolve the new public URL for this attachment.
            $new_url = wp_get_attachment_url( $new_attachment_id );

            if ( ! $new_url ) {
                $this->errors[] = "Could not resolve URL for new attachment ID {$new_attachment_id}.";
                continue;
            }

            // Register the URL mapping — used later to rewrite post_content.
            $this->url_map[ $original_url ] = $new_url;

            // Register the ID mapping — used by process_relational_data() to
            // replace old attachment IDs embedded inside meta fields and block JSON.
            $old_id = (int) ( $image_entry['source_attachment_id'] ?? 0 );
            if ( $old_id > 0 ) {
                $this->id_map[ $old_id ] = $new_attachment_id;
            }

            $images_imported++;
        }

        return $images_imported;
    }

    // ── Step 5 & 6: Post Insertion ────────────────────────────────────────────

    /**
     * Inserts the post as a brand new entry on the target site.
     *
     * Key decisions:
     *  - We deliberately omit 'ID' from the wp_insert_post() args so WP
     *    auto-assigns a new ID — this is safe on any target site.
     *  - post_content is rewritten with new image URLs before insertion.
     *  - We import as 'draft' if the source status was 'auto-draft' to
     *    avoid accidentally publishing incomplete content.
     *  - post_author defaults to the currently logged-in user, since the
     *    original author ID likely doesn't exist on the target site.
     *
     * @return int|WP_Error  New post ID on success, WP_Error on failure.
     *
     * @since 1.0.0
     */
    private function insert_post(): int|WP_Error {
        $post_data = $this->manifest['post'] ?? [];

        if ( empty( $post_data ) ) {
            return new WP_Error( 'cb_missing_post', 'manifest.json contains no post data.' );
        }

        // Rewrite image URLs in post_content before insertion.
        $rewritten_content = $this->rewrite_image_urls(
            $post_data['post_content'] ?? ''
        );

        // Sanitise the post status: never import 'auto-draft' or 'inherit'.
        // If the admin form supplied an override, use that instead.
        if ( ! empty( $this->status_override ) ) {
            $safe_status = $this->status_override;
        } else {
            $safe_status = $post_data['post_status'] ?? 'draft';
            if ( in_array( $safe_status, [ 'auto-draft', 'inherit' ], true ) ) {
                $safe_status = 'draft';
            }
        }

        // Build the args array for wp_insert_post().
        // Note: post_parent is intentionally set to 0 — the parent likely doesn't
        // exist on the target site and trying to mirror it would cause silent failures.
        $insert_args = [
            // ── Core fields ────────────────────────────────────────────────
            'post_title'            => sanitize_text_field( $post_data['post_title'] ?? '' ),
            'post_name'             => sanitize_title( $post_data['post_name'] ?? '' ),
            'post_content'          => $rewritten_content,
            'post_excerpt'          => wp_kses_post( $post_data['post_excerpt'] ?? '' ),
            'post_status'           => $safe_status,
            'post_type'             => sanitize_key( $post_data['post_type'] ?? 'post' ),
            'post_author'           => get_current_user_id(), // Safe default for the target site.
            'post_date'             => $post_data['post_date'] ?? '',
            'post_date_gmt'         => $post_data['post_date_gmt'] ?? '',
            'comment_status'        => $post_data['comment_status'] ?? 'closed',
            'ping_status'           => $post_data['ping_status'] ?? 'closed',
            'post_password'         => $post_data['post_password'] ?? '',
            'to_ping'               => $post_data['to_ping'] ?? '',
            'pinged'                => $post_data['pinged'] ?? '',
            'post_content_filtered' => $post_data['post_content_filtered'] ?? '',
            'menu_order'            => (int) ( $post_data['menu_order'] ?? 0 ),
            'post_parent'           => 0, // See note above.
        ];

        // wp_insert_post() returns 0 or WP_Error on failure.
        $new_post_id = wp_insert_post( $insert_args, true );

        return $new_post_id;
    }

    /**
     * Rewrites all old source-site image URLs in a content string with their
     * new target-site equivalents, using the URL map built during sideloading.
     *
     * We use str_replace() with arrays rather than looping, so all replacements
     * happen in a single pass — this is O(n) with no regex overhead.
     *
     * @param  string $content  Raw post_content from the manifest.
     * @return string           Content with all registered URLs rewritten.
     *
     * @since 1.0.0
     */
    private function rewrite_image_urls( string $content ): string {
        if ( empty( $this->url_map ) || empty( $content ) ) {
            return $content;
        }

        $old_urls = array_keys( $this->url_map );
        $new_urls = array_values( $this->url_map );

        return str_replace( $old_urls, $new_urls, $content );
    }

    // ── Step 6: Featured Image ────────────────────────────────────────────────

    /**
     * Resolves the new local attachment ID for the featured image and attaches
     * it to the newly created post via set_post_thumbnail().
     *
     * Strategy:
     *  The manifest's post_meta includes _thumbnail_id = <old attachment ID>.
     *  During sideloading we built $url_map: old_url → new_url.
     *  We also know which archive_path entry in the manifest was for the featured
     *  image because the exporter registered it first. However, the most reliable
     *  approach here is to find the new attachment whose source URL matches the
     *  old featured-image URL stored in the manifest images array.
     *
     * @param int $new_post_id  The newly inserted post ID.
     *
     * @since 1.0.0
     */
    private function set_featured_image( int $new_post_id ): void {
        // Retrieve the old _thumbnail_id from the manifest's post_meta.
        $old_thumbnail_id = 0;
        $post_meta        = $this->manifest['post_meta'] ?? [];

        if ( isset( $post_meta['_thumbnail_id'][0] ) ) {
            $old_thumbnail_id = (int) $post_meta['_thumbnail_id'][0];
        }

        if ( $old_thumbnail_id <= 0 ) {
            return; // Original post had no featured image.
        }

        // Find the manifest image entry that matches the old attachment ID.
        // The exporter stores the original URL for each image in the manifest.
        // We resolve: old_attachment_id → old_url → new_url → new_attachment_id.
        $old_featured_url = '';
        foreach ( $this->manifest['images'] as $image_entry ) {
            // The exporter stores source_attachment_id in the manifest for images
            // registered via register_image_by_attachment_id().
            if ( isset( $image_entry['source_attachment_id'] ) && (int) $image_entry['source_attachment_id'] === $old_thumbnail_id ) {
                $old_featured_url = $image_entry['original_url'];
                break;
            }
        }

        // Fallback: if attachment_id wasn't stored in manifest images (older export format),
        // try resolving via the _thumbnail_id → wp_get_attachment_url pattern won't work
        // across sites, so we skip gracefully.
        if ( empty( $old_featured_url ) ) {
            $this->errors[] = "Could not resolve featured image: old attachment ID {$old_thumbnail_id} not found in manifest image map.";
            return;
        }

        // Look up the new local URL in the URL map.
        $new_featured_url = $this->url_map[ $old_featured_url ] ?? '';

        if ( empty( $new_featured_url ) ) {
            $this->errors[] = "Featured image was not successfully sideloaded (old URL: {$old_featured_url}).";
            return;
        }

        // Resolve the new URL back to an attachment ID.
        $new_attachment_id = attachment_url_to_postid( $new_featured_url );

        if ( ! $new_attachment_id ) {
            $this->errors[] = "Could not resolve attachment ID from new URL: {$new_featured_url}.";
            return;
        }

        $this->new_thumbnail_id = $new_attachment_id;
        set_post_thumbnail( $new_post_id, $new_attachment_id );
    }

    // ── Step 7: Post Meta ─────────────────────────────────────────────────────

    /**
     * Applies all post meta from the manifest to the newly inserted post.
     *
     * Rules:
     *  - _thumbnail_id is skipped — it was handled by set_featured_image() and
     *    must reference the new local attachment ID, not the old one.
     *  - Any internal WordPress meta keys beginning with '_wp_' that reference
     *    attachment IDs (e.g. _wp_attached_file) are also skipped to avoid
     *    carrying over source-site IDs that have no meaning on the target.
     *  - All other keys are written with update_post_meta(), which creates the
     *    key if it doesn't exist or updates it if it does.
     *
     * @param int $new_post_id  The newly inserted post ID.
     *
     * @since 1.0.0
     */
    private function apply_post_meta( int $new_post_id ): void {
        $post_meta = $this->manifest['post_meta'] ?? [];

        /**
         * Filter: cb_skip_meta_keys
         * Allows developers to add additional meta keys that should not be
         * copied from the source manifest to the target post.
         *
         * @param string[] $keys
         * @since 1.0.0
         */
        $skip_keys = apply_filters( 'cb_skip_meta_keys', [
            '_thumbnail_id',           // Handled separately — new ID resolved from sideload.
            '_wp_attached_file',        // Attachment-specific — not meaningful on target.
            '_wp_attachment_metadata',  // Same.
            '_edit_lock',
            '_edit_last',
        ] );

        /**
         * Filter: cb_relational_meta_keys
         *
         * Meta keys in this list are passed through process_relational_data() before
         * being written to the target post. This performs deep ID remapping and URL
         * rewriting inside serialized PHP arrays, JSON blobs, and plain strings.
         *
         * Add your own keys here for any plugin that stores attachment IDs or
         * site-absolute URLs inside structured meta values.
         *
         * Example usage in a theme or plugin:
         *   add_filter( 'cb_relational_meta_keys', function( $keys ) {
         *       $keys[] = '_my_custom_gallery_field';
         *       return $keys;
         *   });
         *
         * @param string[] $keys  Meta keys subject to deep relational processing.
         * @since 1.0.0
         */
        $relational_keys = apply_filters( 'cb_relational_meta_keys', [
            // ── Page builders ─────────────────────────────────────────────
            '_elementor_data',              // JSON blob containing widget IDs & image URLs
            '_elementor_page_settings',     // Page-level Elementor settings (may contain URLs)
            '_elementor_controls_usage',
            '_et_pb_use_builder',           // Divi builder flag
            '_et_builder_version',

            // ── Block editor extras ───────────────────────────────────────
            '_kadence_starter_templates_record', // Kadence Blocks

            // ── ACF complex fields ────────────────────────────────────────
            // ACF stores scalar IDs as plain integers (handled in post_meta),
            // but repeater / flexible-content fields are serialized arrays:
            '_acf_changed',

            // ── Ultimate Addons / Spectra ────────────────────────────────
            '_uagb_page_settings',
        ] );

        foreach ( $post_meta as $meta_key => $meta_values ) {
            if ( in_array( $meta_key, $skip_keys, true ) ) {
                continue;
            }

            if ( ! is_array( $meta_values ) ) {
                continue;
            }

            delete_post_meta( $new_post_id, $meta_key );

            $needs_deep_processing = in_array( $meta_key, $relational_keys, true );

            foreach ( $meta_values as $value ) {
                if ( $needs_deep_processing ) {
                    // For relational meta we pass the RAW string (before maybe_unserialize)
                    // into process_relational_data() so it can detect PHP serialization,
                    // JSON encoding, and Gutenberg block JSON internally and handle each
                    // format correctly before WP re-saves it.
                    $processed = $this->process_relational_data(
                        $value,
                        $this->id_map,
                        $this->url_map
                    );
                    // If the result is still a string (e.g. re-encoded JSON), WP saves it
                    // as a string. If it's an array (deserialized PHP), WP auto-serializes.
                    add_post_meta( $new_post_id, $meta_key, $processed );
                } else {
                    // Plain meta: unserialize, then do a lightweight URL-only rewrite.
                    $unserialized = maybe_unserialize( $value );
                    // Run URL replacement even on plain meta — catches fields that store
                    // absolute URLs like custom link fields or OG image strings.
                    if ( is_string( $unserialized ) && ! empty( $this->url_map ) ) {
                        $unserialized = str_replace(
                            array_keys( $this->url_map ),
                            array_values( $this->url_map ),
                            $unserialized
                        );
                    }
                    add_post_meta( $new_post_id, $meta_key, $unserialized );
                }
            }
        }
    }

    // ── Step 8: Taxonomy Terms ────────────────────────────────────────────────

    /**
     * Assigns taxonomy terms from the manifest to the newly inserted post.
     *
     * For each taxonomy, we find-or-create each term by slug (not ID, since
     * term IDs are site-specific). wp_set_object_terms() is idempotent and
     * handles term creation automatically.
     *
     * @param int $new_post_id  The newly inserted post ID.
     *
     * @since 1.0.0
     */
    private function apply_taxonomies( int $new_post_id ): void {
        $taxonomies = $this->manifest['taxonomies'] ?? [];

        foreach ( $taxonomies as $taxonomy => $terms ) {
            // Validate the taxonomy exists on the target site.
            if ( ! taxonomy_exists( $taxonomy ) ) {
                $this->errors[] = "Taxonomy '{$taxonomy}' does not exist on this site — terms skipped.";
                continue;
            }

            if ( empty( $terms ) || ! is_array( $terms ) ) {
                continue;
            }

            $term_ids = [];

            foreach ( $terms as $term_data ) {
                $slug = $term_data['slug'] ?? '';
                $name = $term_data['name'] ?? $slug;

                if ( empty( $slug ) ) {
                    continue;
                }

                // Check if the term already exists by slug.
                $existing_term = get_term_by( 'slug', $slug, $taxonomy );

                if ( $existing_term instanceof WP_Term ) {
                    // Reuse the existing term.
                    $term_ids[] = $existing_term->term_id;
                } else {
                    // Create the term with the original name, slug, and description.
                    $new_term = wp_insert_term(
                        $name,
                        $taxonomy,
                        [
                            'slug'        => $slug,
                            'description' => $term_data['description'] ?? '',
                        ]
                    );

                    if ( is_wp_error( $new_term ) ) {
                        $this->errors[] = "Could not create term '{$name}' in taxonomy '{$taxonomy}': " . $new_term->get_error_message();
                        continue;
                    }

                    $term_ids[] = $new_term['term_id'];
                }
            }

            if ( ! empty( $term_ids ) ) {
                // wp_set_object_terms() replaces all existing terms for this taxonomy
                // on the post, which is the correct behaviour for a fresh import.
                $result = wp_set_object_terms( $new_post_id, $term_ids, $taxonomy );

                if ( is_wp_error( $result ) ) {
                    $this->errors[] = "wp_set_object_terms() failed for taxonomy '{$taxonomy}': " . $result->get_error_message();
                }
            }
        }
    }

    // ── Relational Data Processor ─────────────────────────────────────────

    /**
     * Universal recursive rewriter for any PHP value that may contain
     * site-specific attachment IDs or absolute source-site URLs.
     *
     * Handles all data shapes produced by WordPress meta storage:
     *   - PHP integers        → swapped via $id_map
     *   - PHP arrays          → each element recursed
     *   - PHP objects         → cast to array, recursed, cast back
     *   - PHP-serialized strings → unserialized, recursed, re-serialized
     *   - JSON strings        → decoded, recursed, re-encoded
     *   - Gutenberg content   → block attribute JSON extracted and recursed
     *   - Plain strings       → URL str_replace() only
     *
     * Called from apply_post_meta() for keys listed in cb_relational_meta_keys.
     * Can also be called directly by external code (public visibility).
     *
     * @param  mixed              $data     The raw value to process (any type).
     * @param  array<int, int>    $id_map   Map of old_attachment_id => new_attachment_id.
     * @param  array<string, string> $url_map  Map of old_url => new_url.
     * @return mixed  The processed value — same type as input.
     *
     * @since 1.0.0
     */
    public function process_relational_data( mixed $data, array $id_map, array $url_map ): mixed {

        // ── Integer: direct ID lookup ─────────────────────────────────────
        // Only replace exact-match integers (not floats, not integers inside strings).
        // This handles ACF image/post-object fields that store a bare attachment ID.
        if ( is_int( $data ) ) {
            return $id_map[ $data ] ?? $data;
        }

        // ── Array: recurse into every element ─────────────────────────────
        if ( is_array( $data ) ) {
            return $this->process_array( $data, $id_map, $url_map );
        }

        // ── Object: convert ↔ array for uniform recursion ─────────────────
        if ( is_object( $data ) ) {
            $processed = $this->process_array( (array) $data, $id_map, $url_map );
            return (object) $processed;
        }

        // ── String: detect encoding format, then process ──────────────────
        if ( is_string( $data ) ) {
            return $this->process_string( $data, $id_map, $url_map );
        }

        // Booleans, floats, null — return untouched.
        return $data;
    }

    /**
     * Recursively processes every value inside an array.
     * Keys are preserved; only values are rewritten.
     *
     * @param  array              $data
     * @param  array<int, int>    $id_map
     * @param  array<string, string> $url_map
     * @return array
     *
     * @since 1.0.0
     */
    private function process_array( array $data, array $id_map, array $url_map ): array {
        $result = [];
        foreach ( $data as $key => $value ) {
            // Array keys are never remapped — they are structural, not referential.
            $result[ $key ] = $this->process_relational_data( $value, $id_map, $url_map );
        }
        return $result;
    }

    /**
     * Processes a string value through four sequential strategies:
     *
     *   A. PHP-serialized string  → unserialize → recurse → serialize
     *   B. JSON object/array      → json_decode → recurse → json_encode
     *   C. Gutenberg block content → extract block attribute JSON
     *                               → recurse each payload → re-inject
     *   D. Plain string           → str_replace() URLs (IDs skipped —
     *                               replacing bare numbers in text is too risky)
     *
     * Only one strategy is applied (the first match wins), preventing
     * double-encoding. Gutenberg detection runs after URL replacement so that
     * any remaining old-domain URLs in block HTML are also caught.
     *
     * @param  string             $data
     * @param  array<int, int>    $id_map
     * @param  array<string, string> $url_map
     * @return string
     *
     * @since 1.0.0
     */
    private function process_string( string $data, array $id_map, array $url_map ): string {

        // ── A. PHP Serialized ─────────────────────────────────────────────
        // is_serialized() is WP core — safe, handles edge cases like 'b:0;'.
        if ( is_serialized( $data ) ) {
            // The @ suppressor is acceptable here: we guard the false return below.
            $unserialized = @unserialize( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            // unserialize( serialize(false) ) returns false — check the edge case.
            if ( $unserialized !== false || $data === serialize( false ) ) {
                $processed = $this->process_relational_data( $unserialized, $id_map, $url_map );
                return serialize( $processed );
            }
            // If unserialize() genuinely failed, fall through to plain-string handling
            // rather than silently dropping the data.
        }

        // ── B. JSON Object or Array ───────────────────────────────────────
        // We only attempt json_decode on strings that LOOK like JSON containers
        // ('{' or '[') to avoid wasting cycles on plain strings like "true" or "42".
        $trimmed = ltrim( $data );
        if ( ! empty( $trimmed ) && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
            $decoded = json_decode( $data, true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                $processed = $this->process_array( $decoded, $id_map, $url_map );
                return (string) wp_json_encode(
                    $processed,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        // ── C. Gutenberg Block Editor Content ────────────────────────────
        // Block attributes are stored as inline JSON inside HTML comments:
        //   <!-- wp:image {"id":123,"sizeSlug":"large"} -->
        // We extract each JSON payload, recurse into it (replacing IDs and URLs),
        // and re-inject it — preserving the surrounding HTML exactly.
        if ( str_contains( $data, '<!-- wp:' ) ) {
            $data = (string) preg_replace_callback(
                '/<!--\s*(wp:[a-zA-Z0-9\/\-\/]+)\s*(\{(?:[^<>]*?)\})\s*-->/s',
                function ( array $matches ) use ( $id_map, $url_map ): string {
                    $block_name = $matches[1];  // e.g. "wp:image"
                    $json_str   = $matches[2];  // e.g. {"id":123,...}

                    $decoded = json_decode( $json_str, true );
                    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
                        // Malformed block JSON — return the original comment untouched.
                        return $matches[0];
                    }

                    $processed = $this->process_array( $decoded, $id_map, $url_map );
                    $new_json  = (string) wp_json_encode(
                        $processed,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );

                    return "<!-- {$block_name} {$new_json} -->";
                },
                $data
            );
        }

        // ── D. Plain string — URL replacement ────────────────────────────
        // This also catches any old-domain URLs left in Gutenberg block HTML
        // (like <img src=...>) after the attribute JSON was already rewritten above.
        // We deliberately skip integer search-replace here: replacing bare digits
        // in free text would corrupt content unrelated to attachment IDs.
        if ( ! empty( $url_map ) ) {
            $data = str_replace(
                array_keys( $url_map ),
                array_values( $url_map ),
                $data
            );
        }

        return $data;
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────

    /**
     * Recursively deletes the temporary extraction directory.
     *
     * Uses WP_Filesystem where possible; falls back to a manual recursive
     * unlink for environments where WP_Filesystem isn't initialised.
     *
     * @since 1.0.0
     */
    private function cleanup_tmp_dir(): void {
        if ( empty( $this->tmp_dir ) || ! is_dir( $this->tmp_dir ) ) {
            return;
        }

        // Sanity check: only delete paths inside the uploads directory.
        $upload_base = wp_upload_dir()['basedir'];
        if ( ! str_starts_with( realpath( $this->tmp_dir ) ?: '', $upload_base ) ) {
            return; // Refuse to delete anything outside uploads — safety net.
        }

        $this->recursive_rmdir( $this->tmp_dir );
    }

    /**
     * Recursively removes a directory and all its contents.
     *
     * @param string $dir  Absolute path to the directory to remove.
     *
     * @since 1.0.0
     */
    private function recursive_rmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getRealPath() );
            } else {
                unlink( $item->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }

        rmdir( $dir );
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Builds a standardised failure response.
     *
     * @param  string $message  Human-readable error description.
     * @return array{success: bool, new_post_id: int, post_title: string, edit_url: string, view_url: string, images_imported: int, errors: string[]}
     *
     * @since 1.0.0
     */
    private function fail( string $message ): array {
        $this->errors[] = $message;

        return [
            'success'         => false,
            'new_post_id'     => 0,
            'post_title'      => '',
            'edit_url'        => '',
            'view_url'        => '',
            'images_imported' => 0,
            'errors'          => $this->errors,
        ];
    }
}
