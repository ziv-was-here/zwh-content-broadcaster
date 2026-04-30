<?php
/**
 * Class CB_API_Receiver
 *
 * Handles receiving content from other environments via REST API.
 * Validates API keys, stores received .zip files, and provides import links.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_API_Receiver {

    /**
     * Register REST API routes and hooks.
     */
    public static function register(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register the API endpoint.
     */
    public static function register_routes(): void {
        register_rest_route(
            'content-broadcaster/v1',
            '/receive',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'handle_receive' ),
                'permission_callback' => function() { return true; }, // Public endpoint, auth via API key
                'args'                => array(
                    'api_key' => array(
                        'type'     => 'string',
                        'required' => true,
                    ),
                    'source_env' => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                ),
            )
        );

        register_rest_route(
            'content-broadcaster/v1',
            '/ping',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'handle_ping' ),
                'permission_callback' => function() { return true; },
                'args'                => array(
                    'api_key' => array(
                        'type'     => 'string',
                        'required' => true,
                    ),
                ),
            )
        );
    }

    /**
     * Handle incoming content via API.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response The response.
     */
    public static function handle_receive( WP_REST_Request $request ): WP_REST_Response {
        $api_key = $request->get_param( 'api_key' );
        $source_env = $request->get_param( 'source_env' ) ?? 'Unknown';

        // Validate API key
        require_once CB_PLUGIN_DIR . 'includes/class-cb-api-settings.php';
        $validation = CB_API_Settings::validate_api_key( $api_key );

        if ( ! $validation['valid'] ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Invalid API key',
                ),
                401
            );
        }

        // Check if file is present — Nonces are not used here as this is a machine-to-machine REST API call authenticated via API Key.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $_FILES ) || ! isset( $_FILES['file'] ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'No file provided',
                ),
                400
            );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file = $_FILES['file'];

        // Validate file
        $validation_error = self::validate_uploaded_file( $file );
        if ( $validation_error ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $validation_error,
                ),
                400
            );
        }

        // Store received file
        $received_dir = CB_API_Settings::get_received_dir();
        $file_id = 'recv_' . wp_generate_uuid4();
        $stored_file = self::store_received_file( $file['tmp_name'], $received_dir, $file_id );

        if ( ! $stored_file ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Failed to store received file',
                ),
                500
            );
        }

        // Save metadata about the received file
        self::save_received_metadata( $file_id, $source_env, $file['name'] );

        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 'Content received successfully',
                'file_id' => $file_id,
                'import_url' => admin_url( "admin.php?page=cb-received&import=$file_id" ),
            ),
            200
        );
    }

    /**
     * Handle ping request to validate API key.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response The response.
     */
    public static function handle_ping( WP_REST_Request $request ): WP_REST_Response {
        $api_key = $request->get_param( 'api_key' );

        require_once CB_PLUGIN_DIR . 'includes/class-cb-api-settings.php';
        $validation = CB_API_Settings::validate_api_key( $api_key );

        if ( ! $validation['valid'] ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Invalid API key',
                ),
                401
            );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 'Connection successful!',
            ),
            200
        );
    }

    /**
     * Validate the uploaded file.
     *
     * @param array $file The $_FILES entry.
     * @return string|null Error message, or null if valid.
     */
    private static function validate_uploaded_file( array $file ): ?string {
        // Check for upload errors
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return 'File upload failed';
        }

        // Check file size (max 50MB)
        if ( $file['size'] > 50 * 1024 * 1024 ) {
            return 'File is too large (max 50MB)';
        }

        // Check MIME type
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( $mime !== 'application/zip' ) {
            return 'File must be a ZIP archive';
        }

        // Validate ZIP structure by attempting to open it
        $zip = new ZipArchive();
        $zip_result = $zip->open( $file['tmp_name'] );
        if ( $zip_result !== true ) {
            return 'Invalid or corrupted ZIP file';
        }
        $zip->close();

        return null;
    }

    /**
     * Store the received file in the received directory.
     *
     * @param string $tmp_path The temporary file path.
     * @param string $received_dir The received files directory.
     * @param string $file_id The unique file ID.
     * @return string|false The full path to the stored file, or false on failure.
     */
    private static function store_received_file( string $tmp_path, string $received_dir, string $file_id ) {
        $filename = "$file_id.zip";
        $destination = $received_dir . $filename;

        // Use WP_Filesystem for better security and compatibility.
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        if ( ! $wp_filesystem->move( $tmp_path, $destination ) ) {
            return false;
        }

        return $destination;
    }

    /**
     * Save metadata about the received file.
     *
     * @param string $file_id The unique file ID.
     * @param string $source_env The source environment name.
     * @param string $original_filename The original filename.
     */
    private static function save_received_metadata( string $file_id, string $source_env, string $original_filename ): void {
        $received_files = get_option( 'cb_received_files', array() );

        $received_files[] = array(
            'id'       => $file_id,
            'source'   => sanitize_text_field( $source_env ),
            'received' => current_time( 'mysql' ),
            'filename' => sanitize_file_name( $original_filename ),
        );

        update_option( 'cb_received_files', $received_files );
    }

    /**
     * Get all received files.
     *
     * @return array<int, array{id: string, source: string, received: string, filename: string}>
     */
    public static function get_received_files(): array {
        $files = get_option( 'cb_received_files', array() );
        return is_array( $files ) ? $files : array();
    }

    /**
     * Get a specific received file.
     *
     * @param string $file_id The file ID.
     * @return array{id: string, source: string, received: string, filename: string, path: string}|false
     */
    public static function get_received_file( string $file_id ) {
        $files = self::get_received_files();

        foreach ( $files as $file ) {
            if ( $file['id'] === $file_id ) {
                $received_dir = CB_API_Settings::get_received_dir();
                $file['path'] = $received_dir . $file_id . '.zip';

                // Check if file still exists
                if ( file_exists( $file['path'] ) ) {
                    return $file;
                }
            }
        }

        return false;
    }

    /**
     * Delete a received file and its metadata.
     *
     * @param string $file_id The file ID.
     * @return bool True if deleted.
     */
    public static function delete_received_file( string $file_id ): bool {
        $received_dir = CB_API_Settings::get_received_dir();
        $file_path = $received_dir . $file_id . '.zip';

        // Delete the file
        if ( file_exists( $file_path ) ) {
            wp_delete_file( $file_path );
        }

        // Remove metadata
        $received_files = self::get_received_files();
        $original_count = count( $received_files );

        $received_files = array_filter(
            $received_files,
            function ( $file ) use ( $file_id ) {
                return $file['id'] !== $file_id;
            }
        );

        if ( count( $received_files ) < $original_count ) {
            update_option( 'cb_received_files', array_values( $received_files ) );
            return true;
        }

        return false;
    }
}
