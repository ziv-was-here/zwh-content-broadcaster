<?php
/**
 * Class CB_API_Settings
 *
 * Helper class for API-related operations.
 * Uses CB_Settings as the authoritative source for environment configuration.
 * Provides API key validation and received files directory management.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_API_Settings {

    /**
     * Validate an API key against all configured environments.
     *
     * @param string $api_key The API key to validate.
     * @return array{valid: bool, environment_id: int|null}
     */
    public static function validate_api_key( string $api_key ): array {
        require_once CB_PLUGIN_DIR . 'includes/class-cb-settings.php';
        $environments = CB_Settings::get_environments();

        foreach ( $environments as $index => $env ) {
            if ( ! empty( $env['api_key'] ) && hash_equals( $env['api_key'], $api_key ) ) {
                return array(
                    'valid'          => true,
                    'environment_id' => $index,
                );
            }
        }

        return array(
            'valid'          => false,
            'environment_id' => null,
        );
    }

    /**
     * Get the received updates directory.
     * Creates it if it doesn't exist.
     *
     * @return string The path to the received updates directory.
     */
    public static function get_received_dir(): string {
        $upload_dir = wp_upload_dir();
        $received_dir = trailingslashit( $upload_dir['basedir'] ) . 'content-broadcaster-received/';

        if ( ! is_dir( $received_dir ) ) {
            wp_mkdir_p( $received_dir );
        }

        // Ensure there's an index.php for security
        $index_file = $received_dir . 'index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
        }

        return $received_dir;
    }
}
