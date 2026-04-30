<?php
/**
 * Class CB_Exporter
 *
 * The core engine responsible for:
 *   1. Collecting all post data, meta, and taxonomy terms.
 *   2. Locating every image referenced in the post (featured + inline).
 *   3. Building a structured manifest.json.
 *   4. Packaging everything into a single .zip archive via ZipArchive.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Exporter {

    // ── Private State ────────────────────────────────────────────────────────

    /**
     * The WP_Post object being exported.
     *
     * @var WP_Post
     */
    private WP_Post $post;

    /**
     * All custom meta fields for the post (raw, unfiltered).
     *
     * @var array<string, array<mixed>>
     */
    private array $post_meta = [];

    /**
     * Taxonomy → terms map collected during export.
     * e.g. [ 'category' => [ ['term_id' => 1, 'name' => 'News', 'slug' => 'news'] ] ]
     *
     * @var array<string, array<array<string, mixed>>>
     */
    private array $taxonomies = [];

    /**
     * Collected images: each entry is [ 'url' => string, 'path' => string, 'archive_name' => string ]
     * Keyed by original URL to avoid duplicates.
     *
     * @var array<string, array<string, string>>
     */
    private array $images = [];

    /**
     * Errors accumulated during the export run.
     *
     * @var string[]
     */
    private array $errors = [];

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Core export entry-point.
     *
     * Orchestrates collection → manifest generation → zip creation and
     * returns a result array consumed by the admin controller.
     *
     * @param  int $post_id  The ID of the post to export.
     * @return array{
     *     success: bool,
     *     zip_path: string,
     *     zip_url: string,
     *     filename: string,
     *     errors: string[]
     * }
     */
    public function export( int $post_id ): array {

        // ── 1. Load the post ──────────────────────────────────────────────
        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post ) {
            return $this->fail( "Post ID {$post_id} not found." );
        }

        $this->post = $post;

        // ── 2. Collect all data ───────────────────────────────────────────
        $this->collect_post_meta();
        $this->collect_taxonomies();
        $this->collect_featured_image();
        $this->collect_content_images();

        // ── 3. Build manifest ─────────────────────────────────────────────
        $manifest = $this->build_manifest();

        // ── 4. Package into zip ───────────────────────────────────────────
        $zip_result = $this->build_zip( $manifest );

        if ( ! $zip_result['success'] ) {
            return $this->fail( $zip_result['error'] ?? 'Unknown zip error.' );
        }

        return [
            'success'  => true,
            'zip_path' => $zip_result['zip_path'],
            'zip_url'  => $zip_result['zip_url'],
            'filename' => $zip_result['filename'],
            'errors'   => $this->errors, // Non-fatal warnings.
        ];
    }

    // ── Private Collection Methods ────────────────────────────────────────────

    /**
     * Retrieves all custom metadata for the post.
     *
     * We store the raw array from get_post_meta() so the importer
     * has full fidelity — including serialized values — and can decide
     * how to handle them on the target site.
     *
     * @since 1.0.0
     */
    private function collect_post_meta(): void {
        // get_post_meta( $id, '', true ) is NOT the right call.
        // Passing '' as meta key with $single = false returns ALL keys.
        $raw_meta = get_post_meta( $this->post->ID );

        if ( ! is_array( $raw_meta ) ) {
            $this->errors[] = 'get_post_meta() returned a non-array — post meta skipped.';
            return;
        }

        // Flatten single-value arrays: WP always wraps values in an outer array.
        // We keep the outer array so the importer can call add_post_meta() reliably.
        $this->post_meta = $raw_meta;
    }

    /**
     * Collects all taxonomies and their attached terms for the post.
     *
     * We query get_object_taxonomies() dynamically so the export works with
     * any CPT that registers custom taxonomies — no hard-coding of 'category'
     * or 'post_tag'.
     *
     * @since 1.0.0
     */
    private function collect_taxonomies(): void {
        $taxonomies = get_object_taxonomies( $this->post->post_type, 'names' );

        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $this->post->ID, $taxonomy );

            if ( is_wp_error( $terms ) ) {
                $this->errors[] = "Could not retrieve terms for taxonomy '{$taxonomy}': " . $terms->get_error_message();
                continue;
            }

            $this->taxonomies[ $taxonomy ] = array_map(
                static function ( WP_Term $term ): array {
                    return [
                        'term_id'     => $term->term_id,
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'description' => $term->description,
                        'parent'      => $term->parent,
                    ];
                },
                $terms
            );
        }
    }

    /**
     * Resolves the featured image (post thumbnail) to a physical server path.
     *
     * Uses get_post_thumbnail_id() → wp_get_attachment_metadata() to follow
     * WordPress's own attachment data rather than parsing URLs manually.
     *
     * @since 1.0.0
     */
    private function collect_featured_image(): void {
        $thumbnail_id = (int) get_post_thumbnail_id( $this->post->ID );

        if ( $thumbnail_id <= 0 ) {
            return; // Post has no featured image — nothing to do.
        }

        $this->register_image_by_attachment_id( $thumbnail_id, 'featured-image' );
    }

    /**
     * Scans post_content for <img> tags and extracts every image that
     * belongs to this WordPress installation (i.e. lives under the upload dir).
     *
     * External CDN images are recorded in the manifest but NOT bundled,
     * since we don't control those files.
     *
     * @since 1.0.0
     */
    private function collect_content_images(): void {
        $content = $this->post->post_content;

        if ( empty( $content ) ) {
            return;
        }

        // Use DOMDocument for reliable HTML parsing — regex-based parsing is fragile.
        $dom = new DOMDocument();

        // Suppress warnings from malformed HTML fragments (very common in post_content).
        libxml_use_internal_errors( true );
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $content,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $imgs = $dom->getElementsByTagName( 'img' );

        /** @var DOMElement $img */
        foreach ( $imgs as $img ) {
            $src = $img->getAttribute( 'src' );
            if ( empty( $src ) ) {
                continue;
            }
            $this->maybe_register_image_by_url( $src );
        }
    }

    // ── Private Image Helpers ─────────────────────────────────────────────────

    /**
     * Attempts to resolve a WP attachment ID to a physical path and registers
     * the image in the $images collection.
     *
     * @param int    $attachment_id  WP attachment post ID.
     * @param string $context        Short label used in error messages.
     *
     * @since 1.0.0
     */
    private function register_image_by_attachment_id( int $attachment_id, string $context = '' ): void {
        $url = wp_get_attachment_url( $attachment_id );

        if ( ! $url ) {
            $this->errors[] = "Attachment ID {$attachment_id} ({$context}): could not resolve URL.";
            return;
        }

        // get_attached_file() returns the absolute server path to the original file.
        $path = get_attached_file( $attachment_id );

        if ( ! $path || ! file_exists( $path ) ) {
            $this->errors[] = "Attachment ID {$attachment_id} ({$context}): file not found at '{$path}'.";
            return;
        }

        $archive_name = 'images/' . basename( $path );
        $this->images[ $url ] = [
            'url'          => $url,
            'path'         => $path,
            'archive_name' => $archive_name,
            'attachment_id'=> $attachment_id,
        ];
    }

    /**
     * Given an image URL discovered in post_content, checks whether it
     * belongs to this WP installation and, if so, maps it to a server path.
     *
     * @param string $url  The raw src attribute value from an <img> tag.
     *
     * @since 1.0.0
     */
    private function maybe_register_image_by_url( string $url ): void {
        // Normalise protocol-relative URLs (//example.com/…).
        if ( str_starts_with( $url, '//' ) ) {
            $url = 'https:' . $url;
        }

        // Skip data URIs (base64-encoded images embedded inline).
        if ( str_starts_with( $url, 'data:' ) ) {
            return;
        }

        // Skip if we've already registered this URL.
        if ( isset( $this->images[ $url ] ) ) {
            return;
        }

        $upload_base_url = wp_upload_dir()['baseurl'];

        // Only process images hosted on this installation.
        if ( ! str_contains( $url, $upload_base_url ) ) {
            // External image — note it in meta but don't attempt to bundle.
            $this->images[ $url ] = [
                'url'          => $url,
                'path'         => null,
                'archive_name' => null,
                'external'     => true,
            ];
            return;
        }

        // Convert URL → absolute server path by replacing the base URL with the base dir.
        $upload_base_dir = wp_upload_dir()['basedir'];
        $relative        = str_replace( $upload_base_url, '', $url );
        $path            = $upload_base_dir . $relative;

        // Strip any query string that may have been appended (e.g. cache-busters).
        $path = strtok( $path, '?' );

        // SMART FIX: If the path doesn't exist, it might be a resized version (e.g., photo-1024x768.jpg).
        // We attempt to find the original full-size image.
        if ( ! file_exists( $path ) ) {
            $path_without_dimensions = preg_replace( '/-\d+x\d+(?=\.(?:jpe?g|png|gif|webp)$)/i', '', $path );
            if ( $path_without_dimensions !== $path && file_exists( $path_without_dimensions ) ) {
                $path = $path_without_dimensions;
            } else {
                $this->errors[] = "Content image file not found on disk: '{$path}' (URL: {$url}).";
                return;
            }
        }

        // Use a unique archive name to avoid collisions from images in different sub-folders.
        $archive_name = 'images/' . $this->unique_filename_for( $path );

        $this->images[ $url ] = [
            'url'          => $url,
            'path'         => $path,
            'archive_name' => $archive_name,
            'external'     => false,
        ];
    }

    /**
     * Generates a unique, collision-safe filename for a given path by prefixing
     * a partial MD5 of the full path.  This handles images that share a filename
     * across different upload sub-directories (e.g. 2023/01/photo.jpg vs 2024/01/photo.jpg).
     *
     * @param  string $path  Absolute path to the image file.
     * @return string        e.g. a3f8b1_photo.jpg
     *
     * @since 1.0.0
     */
    private function unique_filename_for( string $path ): string {
        return substr( md5( $path ), 0, 6 ) . '_' . basename( $path );
    }

    // ── Manifest Builder ──────────────────────────────────────────────────────

    /**
     * Assembles the complete data manifest as a PHP array.
     *
     * This array is serialised to manifest.json inside the zip.
     * It is designed to be self-contained: any importer that receives the zip
     * must need only this file to understand what to recreate.
     *
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    private function build_manifest(): array {
        $p = $this->post;

        // Capture the original permalink so the importer can match / redirect.
        $original_permalink = get_permalink( $p->ID );

        // Build a clean image map: original URL → archive path inside zip.
        $image_map = [];
        foreach ( $this->images as $url => $data ) {
            $image_map[] = [
                'original_url'          => $url,
                'archive_path'          => $data['archive_name'] ?? null, // null = external
                'external'              => $data['external'] ?? false,
                'source_attachment_id'  => $data['attachment_id'] ?? 0,
            ];
        }

        return [
            'broadcaster_version' => CB_VERSION,
            'export_date'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'source_site_url'     => get_bloginfo( 'url' ),
            'source_site_name'    => get_bloginfo( 'name' ),

            // ── Core post fields ─────────────────────────────────────────
            'post' => [
                'ID'                    => $p->ID,
                'post_author'           => $p->post_author,
                'post_date'             => $p->post_date,
                'post_date_gmt'         => $p->post_date_gmt,
                'post_content'          => $p->post_content,
                'post_title'            => $p->post_title,
                'post_excerpt'          => $p->post_excerpt,
                'post_status'           => $p->post_status,
                'comment_status'        => $p->comment_status,
                'ping_status'           => $p->ping_status,
                'post_password'         => $p->post_password,
                'post_name'             => $p->post_name,
                'to_ping'               => $p->to_ping,
                'pinged'                => $p->pinged,
                'post_modified'         => $p->post_modified,
                'post_modified_gmt'     => $p->post_modified_gmt,
                'post_content_filtered' => $p->post_content_filtered,
                'post_parent'           => $p->post_parent,
                'guid'                  => $p->guid,
                'menu_order'            => $p->menu_order,
                'post_type'             => $p->post_type,
                'post_mime_type'        => $p->post_mime_type,
                'comment_count'         => $p->comment_count,
                'original_permalink'    => $original_permalink,
            ],

            // ── Custom metadata ───────────────────────────────────────────
            // Values are kept in the raw WP format (each key → array of values).
            'post_meta'  => $this->post_meta,

            // ── Taxonomy terms ────────────────────────────────────────────
            'taxonomies' => $this->taxonomies,

            // ── Image reference map ───────────────────────────────────────
            'images'     => $image_map,
        ];
    }

    // ── Zip Builder ───────────────────────────────────────────────────────────

    /**
     * Creates the .zip archive containing:
     *   - manifest.json  (full data manifest)
     *   - images/        (all locally-resolvable images)
     *
     * @param  array<string, mixed> $manifest  The manifest array from build_manifest().
     * @return array{success: bool, zip_path?: string, zip_url?: string, filename?: string, error?: string}
     *
     * @since 1.0.0
     */
    private function build_zip( array $manifest ): array {

        // Ensure ZipArchive is available on this PHP installation.
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [
                'success' => false,
                'error'   => 'ZipArchive class is not available. Please enable the php-zip extension.',
            ];
        }

        // Ensure the export staging directory exists and is writable.
        if ( ! file_exists( CB_EXPORT_DIR ) ) {
            wp_mkdir_p( CB_EXPORT_DIR );
        }

        if ( ! is_writable( CB_EXPORT_DIR ) ) {
            return [
                'success' => false,
                'error'   => 'Export directory is not writable: ' . CB_EXPORT_DIR,
            ];
        }

        // Build a descriptive filename: post-slug--YYYYMMDD-HHmmss.zip
        $filename = sanitize_file_name(
            $this->post->post_name . '--' . gmdate( 'Ymd-His' ) . '.zip'
        );
        $zip_path = CB_EXPORT_DIR . $filename;

        $zip = new ZipArchive();
        $status = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

        if ( $status !== true ) {
            return [
                'success' => false,
                'error'   => "ZipArchive::open() failed with error code: {$status}.",
            ];
        }

        // ── 1. Write manifest.json ────────────────────────────────────────
        $json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        if ( $json === false ) {
            $zip->close();
            return [
                'success' => false,
                'error'   => 'json_encode() failed — manifest could not be serialised.',
            ];
        }

        $zip->addFromString( 'manifest.json', $json );

        // ── 2. Add locally-available images ───────────────────────────────
        foreach ( $this->images as $data ) {
            // Skip external images or entries where the path couldn't be resolved.
            if ( empty( $data['path'] ) || empty( $data['archive_name'] ) ) {
                continue;
            }

            if ( ! file_exists( $data['path'] ) ) {
                $this->errors[] = "Skipping missing image file: {$data['path']}";
                continue;
            }

            $zip->addFile( $data['path'], $data['archive_name'] );
        }

        // ── 3. Finalise ───────────────────────────────────────────────────
        $closed = $zip->close();

        if ( ! $closed ) {
            return [
                'success' => false,
                'error'   => 'ZipArchive::close() failed — the archive may be corrupt.',
            ];
        }

        // Derive the public download URL from the upload dir URL.
        $upload_base_url = trailingslashit( wp_upload_dir()['baseurl'] ) . 'content-broadcaster/';
        $zip_url         = $upload_base_url . $filename;

        return [
            'success'  => true,
            'zip_path' => $zip_path,
            'zip_url'  => $zip_url,
            'filename' => $filename,
        ];
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Builds a standardised failure response and optionally appends a message
     * to the internal error log.
     *
     * @param  string $message  Human-readable error description.
     * @return array{success: bool, zip_path: string, zip_url: string, filename: string, errors: string[]}
     *
     * @since 1.0.0
     */
    private function fail( string $message ): array {
        $this->errors[] = $message;

        return [
            'success'  => false,
            'zip_path' => '',
            'zip_url'  => '',
            'filename' => '',
            'errors'   => $this->errors,
        ];
    }
}
