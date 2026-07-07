<?php
/**
 * Class CB_Ajax_Handler
 *
 * Handles all AJAX requests for the Content Broadcaster plugin.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Ajax_Handler {

    /**
     * Register AJAX hooks.
     */
    public function __construct() {
        add_action( 'wp_ajax_cb_test_connection', [ $this, 'handle_test_connection' ] );
    }

    /**
     * Handle the test connection AJAX request.
     */
    public function handle_test_connection(): void {
        check_ajax_referer( 'cb_settings_nonce', 'nonce' );

        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'zwh-content-broadcaster' ) ] );
        }

        $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
        
        // Load settings to get the environment data
        require_once CB_PLUGIN_DIR . 'includes/class-cb-settings.php';
        $environment = CB_Settings::get_environment( $index );

        if ( ! $environment ) {
            // If the row is new (not saved yet), we get data from POST
            $environment = [
                'site_url' => isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '',
                'api_key'  => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
            ];
        }

        if ( empty( $environment['site_url'] ) || empty( $environment['api_key'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Site URL and API Key are required for testing.', 'zwh-content-broadcaster' ) ] );
        }

        $ping_url = add_query_arg(
            [ 'api_key' => $environment['api_key'] ],
            trailingslashit( $environment['site_url'] ) . 'wp-json/content-broadcaster/v1/ping'
        );

        $response = wp_remote_get( $ping_url, [
            'timeout'   => 15,
            'sslverify' => false, // Allow self-signed for dev/staging if needed
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code === 200 && ! empty( $data['success'] ) ) {
            wp_send_json_success( [ 'message' => $data['message'] ?? __( 'Connection successful!', 'zwh-content-broadcaster' ) ] );
        } else {
            $message = $data['message'] ?? __( 'Connection failed.', 'zwh-content-broadcaster' );
            if ( $code === 401 ) {
                $message = __( 'Invalid API Key.', 'zwh-content-broadcaster' );
            } elseif ( $code === 404 ) {
                $message = __( 'Plugin not active on target site or invalid URL.', 'zwh-content-broadcaster' );
            }
            wp_send_json_error( [ 'message' => "HTTP $code: $message" ] );
        }
    }
}
