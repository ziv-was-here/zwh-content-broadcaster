<?php
/**
 * Class CB_Send_Metabox
 *
 * Adds metabox to post edit screens for sending content via API.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Send_Metabox {

    /**
     * Register hooks for the send metabox.
     */
    public static function register(): void {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_cb_send_post', array( __CLASS__, 'handle_ajax_send' ) );
    }

    /**
     * Add the metabox to post edit screens.
     */
    public static function add_metabox(): void {
        $post_types = get_post_types( array( 'public' => true ) );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'cb_send_metabox',
                'Send via API',
                array( __CLASS__, 'render_metabox' ),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Enqueue admin styles and scripts.
     *
     * @param string $hook_suffix The current admin page.
     */
    public static function enqueue_admin_assets( string $hook_suffix ): void {
        // Only on post/page edit screens
        if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        // Inline styles for the metabox
        wp_add_inline_style(
            'wp-admin',
            '
            #cb_send_metabox .cb-environments-list {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            #cb_send_metabox .cb-environments-list li {
                margin: 8px 0;
            }
            #cb_send_metabox .cb-environments-list input[type="checkbox"] {
                margin-right: 6px;
            }
            #cb_send_metabox .cb-send-button {
                width: 100%;
                margin-top: 12px;
            }
            #cb_send_metabox .cb-results {
                margin-top: 12px;
                padding: 10px;
                border-radius: 4px;
                display: none;
            }
            #cb_send_metabox .cb-results.show {
                display: block;
            }
            #cb_send_metabox .cb-results.success {
                background-color: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
            }
            #cb_send_metabox .cb-results.error {
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
            }
            #cb_send_metabox .cb-result-item {
                margin: 6px 0;
                font-size: 13px;
            }
            #cb_send_metabox .cb-spinner {
                display: none;
            }
            #cb_send_metabox .cb-spinner.show {
                display: inline-block;
                margin-right: 6px;
            }
            '
        );

        // Enqueue the external JavaScript file
        wp_enqueue_script(
            'cb-send-metabox',
            CB_PLUGIN_URL . 'admin/js/cb-send.js',
            [],
            CB_VERSION,
            true
        );

        wp_localize_script( 'cb-send-metabox', 'cbSendArgs', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cb_send_nonce' ),
        ] );
    }

    /**
     * Render the metabox content.
     *
     * @param WP_Post $post The current post object.
     */
    public static function render_metabox( WP_Post $post ): void {
        require_once CB_PLUGIN_DIR . 'includes/class-cb-settings.php';
        $environments = CB_Settings::get_environments();

        // Check user capability
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            echo '<p><em>You do not have permission to use this feature.</em></p>';
            return;
        }

        if ( empty( $environments ) ) {
            echo '<p><em>No target environments configured yet. <a href="' . esc_url( admin_url( 'admin.php?page=cb-settings' ) ) . '">Add one now</a>.</em></p>';
            return;
        }

        ?>
        <div id="cb_send_form">
            <p><strong>Select environments to send to:</strong></p>
            <ul class="cb-environments-list">
                <?php foreach ( $environments as $index => $env ) : ?>
                    <li>
                        <input type="checkbox" id="cb_env_<?php echo esc_attr( $index ); ?>"
                               name="cb_target_env[]" value="<?php echo esc_attr( $index ); ?>">
                        <label for="cb_env_<?php echo esc_attr( $index ); ?>">
                            <?php echo esc_html( $env['nickname'] ); ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>

            <input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>">

            <button type="button" class="button button-primary cb-send-button">
                <span class="cb-spinner"><span class="spinner" style="float:none;"></span></span>
                Send via API
            </button>

            <div class="cb-results"></div>
        </div>
        <?php
    }

    /**
     * Handle AJAX send request.
     */
    public static function handle_ajax_send(): void {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cb_send_nonce' ) ) {
            wp_send_json_error( 'Security check failed' );
        }

        // Verify capability
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        // Get post ID
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( 'Invalid post ID' );
        }

        // Get selected environments
        $env_ids = isset( $_POST['cb_target_env'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['cb_target_env'] ) ) : array();
        if ( empty( $env_ids ) ) {
            wp_send_json_error( 'No environments selected' );
        }

        // Send to environments
        require_once CB_PLUGIN_DIR . 'includes/class-cb-api-sender.php';
        $results = CB_API_Sender::send_to_environments( $post_id, $env_ids );

        // Build response message
        $messages = array();
        $has_error = false;

        foreach ( $results as $env_id => $result ) {
            if ( ! isset( $result['success'] ) ) {
                // This might be an error array from send_to_environments
                if ( isset( $result['message'] ) ) {
                    $messages[] = $result['message'];
                    $has_error = true;
                }
            } else {
                $messages[] = $result['message'];
                if ( ! $result['success'] ) {
                    $has_error = true;
                }
            }
        }

        if ( $has_error ) {
            wp_send_json_error( $messages );
        } else {
            // Clear any stray warnings/notices to ensure pure JSON response
            if ( ob_get_length() ) {
                ob_clean();
            }
            wp_send_json_success( $messages );
        }
    }
}
