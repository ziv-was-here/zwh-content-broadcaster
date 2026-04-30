<?php
/**
 * Class CB_Received_Page
 *
 * Admin page for viewing and managing received content from other environments.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Received_Page {

    /**
     * Register hooks for the received files page.
     */
    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
        add_action( 'admin_notices', array( __CLASS__, 'show_admin_notices' ) );
        add_action( 'wp_ajax_cb_import_received', array( __CLASS__, 'handle_ajax_import' ) );
    }

    /**
     * Add the page to the admin menu.
     */
    public static function add_menu_page(): void {
        add_submenu_page(
            'content-broadcaster',
            'Received Content',
            'Received Content',
            CB_CAPABILITY,
            'cb-received',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Handle form actions (delete received file).
     */
    public static function handle_actions(): void {
        if ( ! isset( $_POST['cb_action'] ) ) {
            return;
        }

        if ( ! isset( $_POST['cb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cb_nonce'] ) ), 'cb_received_action' ) ) {
            return;
        }

        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $action = sanitize_text_field( wp_unslash( $_POST['cb_action'] ) );
        $file_id = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( $_POST['file_id'] ) ) : '';

        if ( $action === 'delete' && $file_id ) {
            require_once CB_PLUGIN_DIR . 'includes/class-cb-api-receiver.php';
            if ( CB_API_Receiver::delete_received_file( $file_id ) ) {
                set_transient( 'cb_received_notice', 'File deleted successfully', 30 );
            } else {
                set_transient( 'cb_received_error', 'Failed to delete file', 30 );
            }

            wp_safe_redirect( admin_url( 'admin.php?page=cb-received' ) );
            exit;
        }
    }

    /**
     * Show admin notices.
     */
    public static function show_admin_notices(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'cb-received' ) {
            return;
        }

        $notice = get_transient( 'cb_received_notice' );
        $error = get_transient( 'cb_received_error' );

        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
            delete_transient( 'cb_received_notice' );
        }

        if ( $error ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
            delete_transient( 'cb_received_error' );
        }
    }

    /**
     * Render the received files page.
     */
    public static function render_page(): void {
        require_once CB_PLUGIN_DIR . 'includes/class-cb-api-receiver.php';
        require_once CB_PLUGIN_DIR . 'includes/class-cb-settings.php';
        $received_files = CB_API_Receiver::get_received_files();
        $environments = CB_Settings::get_environments();

        ?>
        <div class="wrap">
            <h1>Received Content</h1>
            <p>Content sent to you from other environments. Import to add to your site or delete to remove.</p>

            <?php if ( empty( $received_files ) ) : ?>
                <div style="background: #f9f9f9; padding: 20px; border-radius: 4px; border: 1px solid #ddd;">
                    <p><em>No received content yet. When other environments send you content via API, it will appear here.</em></p>
                </div>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Source Environment</th>
                            <th>Received Date</th>
                            <th>Original Filename</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $received_files as $file ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $file['source'] ); ?></strong></td>
                                <td><?php echo esc_html( $file['received'] ); ?></td>
                                <td><code><?php echo esc_html( $file['filename'] ); ?></code></td>
                                <td>
                                    <button type="button" class="button button-primary cb-import-btn"
                                            data-file-id="<?php echo esc_attr( $file['id'] ); ?>"
                                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'cb_import_nonce_' . $file['id'] ) ); ?>">
                                        Import
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <?php wp_nonce_field( 'cb_received_action', 'cb_nonce' ); ?>
                                        <input type="hidden" name="cb_action" value="delete">
                                        <input type="hidden" name="file_id" value="<?php echo esc_attr( $file['id'] ); ?>">
                                        <button type="submit" class="button button-link-delete"
                                                onclick="return confirm('Delete this received file? This cannot be undone.')">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener( 'DOMContentLoaded', function() {
            const importBtns = document.querySelectorAll( '.cb-import-btn' );

            importBtns.forEach( btn => {
                btn.addEventListener( 'click', function( e ) {
                    e.preventDefault();

                    const fileId = this.dataset.fileId;
                    const nonce = this.dataset.nonce;
                    const originalText = this.textContent;

                    this.disabled = true;
                    this.textContent = 'Importing...';

                    const formData = new FormData();
                    formData.append( 'action', 'cb_import_received' );
                    formData.append( 'file_id', fileId );
                    formData.append( 'nonce', nonce );

                    fetch( ajaxurl, {
                        method: 'POST',
                        body: formData
                    } )
                    .then( response => response.json() )
                    .then( data => {
                        if ( data.success ) {
                            alert( 'Import successful! Content has been imported to your site.' );
                            location.reload();
                        } else {
                            alert( 'Import failed: ' + (data.data || 'Unknown error') );
                            this.disabled = false;
                            this.textContent = originalText;
                        }
                    } )
                    .catch( error => {
                        alert( 'Network error: ' + error.message );
                        this.disabled = false;
                        this.textContent = originalText;
                    } );
                } );
            } );
        } );
        </script>
        <?php
    }

    /**
     * Handle AJAX import request.
     */
    public static function handle_ajax_import(): void {
        // Get file ID
        $file_id = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( $_POST['file_id'] ) ) : '';
        if ( ! $file_id ) {
            wp_send_json_error( 'Invalid file ID' );
        }

        // Verify capability
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cb_import_nonce_' . $file_id ) ) {
            wp_send_json_error( 'Security check failed' );
        }

        // Get the received file
        require_once CB_PLUGIN_DIR . 'includes/class-cb-api-receiver.php';
        $file = CB_API_Receiver::get_received_file( $file_id );

        if ( ! $file || ! file_exists( $file['path'] ) ) {
            wp_send_json_error( 'File not found' );
        }

        // Import the file
        require_once CB_PLUGIN_DIR . 'includes/class-cb-importer.php';
        $importer = new CB_Importer();

        try {
            $result = $importer->import_file( $file['path'] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }

            // Import successful - optionally delete the received file after import
            // Uncomment to auto-delete after successful import:
            // CB_API_Receiver::delete_received_file( $file_id );

            wp_send_json_success( array(
                'message' => 'Content imported successfully',
                'imported_items' => $result,
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Import error: ' . $e->getMessage() );
        }
    }
}
