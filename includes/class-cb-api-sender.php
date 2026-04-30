<?php
/**
 * Class CB_API_Sender
 *
 * Handles sending exported content to target environments via REST API.
 * Exports post/page to ZIP, sends to each selected environment.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_API_Sender {

    /**
     * Send a post to selected environments.
     *
     * @param int   $post_id The post ID to export and send.
     * @param array $environment_ids Array of environment IDs to send to.
     * @return array<string, array{success: bool, message: string}>
     */
    public static function send_to_environments( int $post_id, array $environment_ids ): array {
        // Validate post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array(
                'error' => array(
                    'success' => false,
                    'message' => 'Post not found',
                ),
            );
        }

        // Load CB_Settings for environment retrieval
        require_once CB_PLUGIN_DIR . 'includes/class-cb-settings.php';

        // Export post to ZIP
        require_once CB_PLUGIN_DIR . 'includes/class-cb-exporter.php';
        $exporter = new CB_Exporter();
        $export_result = $exporter->export( $post_id );

        if ( ! $export_result['success'] || empty( $export_result['zip_path'] ) ) {
            return array(
                'error' => array(
                    'success' => false,
                    'message' => 'Failed to export post: ' . implode( ', ', $export_result['errors'] ),
                ),
            );
        }

        $zip_path = $export_result['zip_path'];

        // Send to each environment
        $results = array();
        foreach ( $environment_ids as $env_index ) {
            $environment = CB_Settings::get_environment( (int) $env_index );

            if ( ! $environment ) {
                $results[ $env_index ] = array(
                    'success' => false,
                    'message' => 'Environment not found',
                );
                continue;
            }

            $result = self::send_to_environment( $zip_path, $environment, $post );
            $results[ $env_index ] = $result;
        }

        // Clean up temporary ZIP file
        if ( file_exists( $zip_path ) ) {
            wp_delete_file( $zip_path );
        }

        return $results;
    }

    /**
     * Send ZIP file to a single environment.
     *
     * @param string $zip_path Path to the ZIP file.
     * @param array  $environment The environment config {nickname, type, site_url, api_key, sync_method}.
     * @param WP_Post $post The post being sent.
     * @return array{success: bool, message: string}
     */
    private static function send_to_environment( string $zip_path, array $environment, WP_Post $post ): array {
        // Build the target API URL
        $api_url = rtrim( $environment['site_url'], '/' ) . '/wp-json/content-broadcaster/v1/receive';

        // Read ZIP file for upload
        $zip_contents = file_get_contents( $zip_path );
        if ( ! $zip_contents ) {
            return array(
                'success' => false,
                'message' => 'Failed to read ZIP file',
            );
        }

        // Create multipart form data request
        $boundary = 'boundary_' . time();
        $body = self::build_multipart_body( $zip_contents, basename( $zip_path ), $environment['api_key'], $boundary );

        $response = wp_remote_post(
            $api_url,
            array(
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => array(
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ),
                'body'        => $body,
                'sslverify'   => false, // Allow local to live even if CA bundles are outdated
            )
        );

        // Handle response
        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => 'API request failed: ' . $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $response_data = json_decode( $response_body, true );

        if ( 200 === $status_code && isset( $response_data['success'] ) && $response_data['success'] ) {
            return array(
                'success' => true,
                'message' => 'Content sent successfully to ' . esc_html( $environment['nickname'] ),
            );
        }

        $error_msg = isset( $response_data['message'] ) ? $response_data['message'] : 'Unknown error';
        return array(
            'success' => false,
            'message' => 'Failed to send to ' . esc_html( $environment['nickname'] ) . ': ' . $error_msg,
        );
    }

    /**
     * Build multipart form data for file upload.
     *
     * @param string $file_contents The file contents.
     * @param string $filename The filename.
     * @param string $api_key The API key.
     * @param string $boundary The multipart boundary.
     * @return string The multipart body.
     */
    private static function build_multipart_body( string $file_contents, string $filename, string $api_key, string $boundary ): string {
        $eol = "\r\n";

        $body = '';

        // API key field
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="api_key"' . $eol . $eol;
        $body .= $api_key . $eol;

        // Source environment field
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="source_env"' . $eol . $eol;
        $body .= get_bloginfo( 'name' ) . $eol;

        // File field
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
        $body .= 'Content-Type: application/zip' . $eol . $eol;
        $body .= $file_contents . $eol;

        // Closing boundary
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }
}
