<?php
/**
 * Class CB_Admin
 *
 * Handles everything that lives in wp-admin:
 *   - Registers the "Content Broadcaster" top-level menu page with Export + Import tabs.
 *   - Enqueues admin scripts and styles.
 *   - Renders the export UI (post selector + download link).
 *   - Renders the import UI (file upload form + result summary).
 *   - Processes form submissions for both export and import.
 *   - Handles the secure download of generated zip files.
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 * @version 1.1.0 Weathered Chronicle admin redesign.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Admin {

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Nonce action / field for the export form. */
    private const EXPORT_NONCE_ACTION = 'cb_export_post';
    private const EXPORT_NONCE_FIELD  = 'cb_export_nonce';

    /** Nonce action / field for the import form. */
    private const IMPORT_NONCE_ACTION = 'cb_import_zip';
    private const IMPORT_NONCE_FIELD  = 'cb_import_nonce';

    // ── Constructor ───────────────────────────────────────────────────────────

    /**
     * Register all WordPress hooks needed by the admin interface.
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_cb_export',  [ $this, 'handle_export_request' ] );
        add_action( 'admin_post_cb_download', [ $this, 'handle_download_request' ] );
        add_action( 'admin_post_cb_import',  [ $this, 'handle_import_request' ] );
    }

    // ── Menu Registration ─────────────────────────────────────────────────────

    /**
     * Adds a top-level "Broadcaster" menu page to the WP admin sidebar.
     *
     * @since 1.0.0
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'Content Broadcaster', 'zwh-content-broadcaster' ),
            __( 'Broadcaster', 'zwh-content-broadcaster' ),
            CB_CAPABILITY,
            'content-broadcaster',
            [ $this, 'render_page' ],
            'dashicons-migrate',
            80
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    /**
     * Enqueues the plugin's admin stylesheet — only on the Broadcaster page.
     *
     * @param string $hook  The current admin page hook suffix.
     *
     * @since 1.0.0
     */
    public function enqueue_assets( string $hook ): void {
        $cb_pages = ( 'toplevel_page_content-broadcaster' === $hook
            || false !== strpos( $hook, 'cb-settings' )
            || false !== strpos( $hook, 'cb-received' ) );

        if ( ! $cb_pages ) {
            return;
        }
        self::enqueue_brand_styles();
    }

    /**
     * Enqueues the Weathered Chronicle admin stylesheet and the Epilogue
     * typeface used across all Content Broadcaster admin screens.
     *
     * @since 1.1.0
     */
    public static function enqueue_brand_styles(): void {
        wp_enqueue_style(
            'content-broadcaster-font',
            'https://fonts.googleapis.com/css2?family=Epilogue:ital,wght@0,400;0,600;0,700;1,700;1,800&display=swap',
            [],
            null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        );
        wp_enqueue_style(
            'content-broadcaster-admin',
            CB_PLUGIN_URL . 'admin/css/admin.css',
            [],
            CB_VERSION
        );
    }

    /**
     * Renders the branded product header shared by all plugin admin screens.
     *
     * @param string $tagline  Short uppercase tagline under the product name.
     *
     * @since 1.1.0
     */
    public static function render_product_header( string $tagline = '' ): void {
        if ( '' === $tagline ) {
            $tagline = __( 'Create. Approve. Deploy.', 'zwh-content-broadcaster' );
        }
        ?>
        <header class="cb-product-header">
            <div class="cb-product-brand">
                <span class="cb-product-mark" aria-hidden="true">
                    <span class="dashicons dashicons-migrate"></span>
                </span>
                <div>
                    <h1 class="cb-product-title"><?php esc_html_e( 'Content Broadcaster', 'zwh-content-broadcaster' ); ?></h1>
                    <p class="cb-product-tagline"><?php echo esc_html( $tagline ); ?></p>
                </div>
            </div>
            <div class="cb-product-meta">
                <span class="cb-version-badge">v<?php echo esc_html( CB_VERSION ); ?></span>
                <a class="cb-brand-link" href="https://zivwashere.com" target="_blank" rel="noopener">zivwashere.com</a>
            </div>
        </header>
        <?php
    }

    // ── Main Page Dispatcher ──────────────────────────────────────────────────

    /**
     * Renders the correct tab (Export or Import) based on the ?tab= query param.
     *
     * @since 1.0.0
     */
    public function render_page(): void {
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'zwh-content-broadcaster' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset( $_GET['tab'] ) && 'import' === $_GET['tab'] ? 'import' : 'export';
        ?>
        <div class="wrap cb-wrap">
            <?php self::render_product_header( __( 'Export & import content between environments', 'zwh-content-broadcaster' ) ); ?>

            <nav class="nav-tab-wrapper cb-tabs" aria-label="<?php esc_attr_e( 'Broadcaster Tabs', 'zwh-content-broadcaster' ); ?>">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=content-broadcaster&tab=export' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'export' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '⬆ Export', 'zwh-content-broadcaster' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=content-broadcaster&tab=import' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '⬇ Import', 'zwh-content-broadcaster' ); ?>
                </a>
            </nav>

            <div class="cb-tab-content">
                <?php
                if ( $active_tab === 'import' ) {
                    $this->render_import_tab();
                } else {
                    $this->render_export_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ── Export Tab ────────────────────────────────────────────────────────────

    /**
     * Renders the Export tab content.
     *
     * @since 1.0.0
     */
    private function render_export_tab(): void {
        $transient_key = 'cb_export_result_' . get_current_user_id();
        $result        = get_transient( $transient_key );
        delete_transient( $transient_key );

        $posts = $this->get_exportable_posts();

        $this->render_notices( $result, 'export' );
        ?>
        <p class="cb-intro">
            <?php esc_html_e( 'Select a post, page, or custom post type entry. The plugin packages its content, metadata, taxonomy terms, and images into a single .zip archive.', 'zwh-content-broadcaster' ); ?>
        </p>

        <div class="cb-card">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="cb_export">
                <?php wp_nonce_field( self::EXPORT_NONCE_ACTION, self::EXPORT_NONCE_FIELD ); ?>

                <table class="form-table cb-form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="cb_post_id"><?php esc_html_e( 'Content to Export', 'zwh-content-broadcaster' ); ?></label>
                        </th>
                        <td>
                            <select name="cb_post_id" id="cb_post_id" class="cb-select" required>
                                <option value="">— <?php esc_html_e( 'Select an item', 'zwh-content-broadcaster' ); ?> —</option>
                                <?php foreach ( $posts as $group_label => $group_posts ) : ?>
                                    <optgroup label="<?php echo esc_attr( $group_label ); ?>">
                                        <?php foreach ( $group_posts as $p ) : ?>
                                            <option value="<?php echo esc_attr( $p->ID ); ?>">
                                                <?php echo esc_html( $p->post_title ); ?>
                                                (ID: <?php echo esc_html( $p->ID ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( '⬆ Export to Zip', 'zwh-content-broadcaster' ), 'primary cb-submit-btn', 'cb_submit' ); ?>
            </form>
        </div>

        <?php if ( ! empty( $result['success'] ) && ! empty( $result['filename'] ) ) : ?>
            <div class="cb-card cb-result-card cb-result-card--success">
                <h2><?php esc_html_e( 'Export Ready', 'zwh-content-broadcaster' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s: filename */
                        esc_html__( 'Archive: %s', 'zwh-content-broadcaster' ),
                        '<code>' . esc_html( $result['filename'] ) . '</code>'
                    );
                    ?>
                </p>
                <?php
                $download_url = add_query_arg(
                    [
                        'action'   => 'cb_download',
                        'filename' => rawurlencode( $result['filename'] ),
                        '_wpnonce' => wp_create_nonce( 'cb_download_' . $result['filename'] ),
                    ],
                    admin_url( 'admin-post.php' )
                );
                ?>
                <a href="<?php echo esc_url( $download_url ); ?>"
                   class="button button-primary cb-action-btn"
                   id="cb-download-btn">
                    <?php esc_html_e( '⬇ Download Zip Archive', 'zwh-content-broadcaster' ); ?>
                </a>
                <?php $this->render_warnings( $result['errors'] ?? [] ); ?>
            </div>
        <?php endif; ?>
        <?php
    }

    // ── Import Tab ────────────────────────────────────────────────────────────

    /**
     * Renders the Import tab content.
     *
     * @since 1.0.0
     */
    private function render_import_tab(): void {
        $transient_key = 'cb_import_result_' . get_current_user_id();
        $result        = get_transient( $transient_key );
        delete_transient( $transient_key );

        $this->render_notices( $result, 'import' );
        ?>
        <p class="cb-intro">
            <?php esc_html_e( 'Upload a .zip archive generated by Content Broadcaster on another site. The plugin will import the content, media, and taxonomy terms into this installation.', 'zwh-content-broadcaster' ); ?>
        </p>

        <div class="cb-card">
            <form method="post"
                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  enctype="multipart/form-data">
                <input type="hidden" name="action" value="cb_import">
                <?php wp_nonce_field( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_FIELD ); ?>

                <table class="form-table cb-form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="cb_zip_file"><?php esc_html_e( 'Broadcaster Archive (.zip)', 'zwh-content-broadcaster' ); ?></label>
                        </th>
                        <td>
                            <input type="file"
                                   name="cb_zip_file"
                                   id="cb_zip_file"
                                   accept=".zip,application/zip"
                                   class="cb-file-input"
                                   required>
                            <p class="description">
                                <?php esc_html_e( 'Only .zip files exported by Content Broadcaster are accepted.', 'zwh-content-broadcaster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cb_import_status"><?php esc_html_e( 'Import as Status', 'zwh-content-broadcaster' ); ?></label>
                        </th>
                        <td>
                            <select name="cb_import_status" id="cb_import_status" class="cb-select">
                                <option value=""><?php esc_html_e( '— Use original status from archive —', 'zwh-content-broadcaster' ); ?></option>
                                <option value="draft"><?php esc_html_e( 'Draft (safe, review before publishing)', 'zwh-content-broadcaster' ); ?></option>
                                <option value="publish"><?php esc_html_e( 'Published', 'zwh-content-broadcaster' ); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Override the post status stored inside the archive.', 'zwh-content-broadcaster' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( '⬇ Import Archive', 'zwh-content-broadcaster' ), 'primary cb-submit-btn', 'cb_import_submit' ); ?>
            </form>
        </div>

        <?php if ( ! empty( $result['success'] ) && ! empty( $result['new_post_id'] ) ) : ?>
            <div class="cb-card cb-result-card cb-result-card--success">
                <h2><?php esc_html_e( 'Import Complete', 'zwh-content-broadcaster' ); ?></h2>
                <table class="cb-summary-table">
                    <tr>
                        <th><?php esc_html_e( 'Post Title', 'zwh-content-broadcaster' ); ?></th>
                        <td><?php echo esc_html( $result['post_title'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'New Post ID', 'zwh-content-broadcaster' ); ?></th>
                        <td><?php echo esc_html( $result['new_post_id'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Images Imported', 'zwh-content-broadcaster' ); ?></th>
                        <td><?php echo esc_html( $result['images_imported'] ); ?></td>
                    </tr>
                </table>
                <div class="cb-result-actions">
                    <?php if ( ! empty( $result['edit_url'] ) ) : ?>
                        <a href="<?php echo esc_url( $result['edit_url'] ); ?>"
                           class="button button-primary cb-action-btn"
                           id="cb-edit-post-btn">
                            <?php esc_html_e( '✏ Edit Post', 'zwh-content-broadcaster' ); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ( ! empty( $result['view_url'] ) ) : ?>
                        <a href="<?php echo esc_url( $result['view_url'] ); ?>"
                           class="button cb-action-btn cb-action-btn--secondary"
                           id="cb-view-post-btn"
                           target="_blank" rel="noopener">
                            <?php esc_html_e( '👁 View Post', 'zwh-content-broadcaster' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php $this->render_warnings( $result['errors'] ?? [] ); ?>
            </div>
        <?php endif; ?>
        <?php
    }

    // ── Shared UI Helpers ─────────────────────────────────────────────────────

    /**
     * Renders a WP-style success or error notice from a result array.
     *
     * @param array|false $result   The result transient value, or false if none set.
     * @param string      $context  'export' or 'import' — used in messages.
     *
     * @since 1.0.0
     */
    private function render_notices( $result, string $context ): void {
        if ( empty( $result ) ) {
            return;
        }

        if ( ! empty( $result['success'] ) ) {
            $msg = $context === 'import'
                ? __( 'Import completed successfully!', 'zwh-content-broadcaster' )
                : __( 'Export completed successfully!', 'zwh-content-broadcaster' );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        } else {
            $errors = $result['errors'] ?? [];
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html( $context === 'import'
                ? __( 'Import failed: ', 'zwh-content-broadcaster' )
                : __( 'Export failed: ', 'zwh-content-broadcaster' )
            );
            echo esc_html( implode( ' | ', $errors ) );
            echo '</p></div>';
        }
    }

    /**
     * Renders non-fatal warnings in a collapsible <details> block.
     *
     * @param string[] $warnings
     *
     * @since 1.0.0
     */
    private function render_warnings( array $warnings ): void {
        if ( empty( $warnings ) ) {
            return;
        }
        ?>
        <details class="cb-warnings">
            <summary><?php esc_html_e( 'Non-fatal warnings', 'zwh-content-broadcaster' ); ?> (<?php echo count( $warnings ); ?>)</summary>
            <ul>
                <?php foreach ( $warnings as $w ) : ?>
                    <li><?php echo esc_html( $w ); ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }

    // ── Request Handlers ──────────────────────────────────────────────────────

    /**
     * Handles the Export form submission.
     * Hook: admin_post_cb_export
     *
     * @since 1.0.0
     */
    public function handle_export_request(): void {
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'zwh-content-broadcaster' ) );
        }

        if ( ! isset( $_POST[ self::EXPORT_NONCE_FIELD ] ) ||
             ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::EXPORT_NONCE_FIELD ] ) ), self::EXPORT_NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Security check failed.', 'zwh-content-broadcaster' ) );
        }

        $post_id = isset( $_POST['cb_post_id'] ) ? (int) $_POST['cb_post_id'] : 0;
        if ( $post_id <= 0 ) {
            wp_die( esc_html__( 'Invalid post ID.', 'zwh-content-broadcaster' ) );
        }

        $exporter = new CB_Exporter();
        $result   = $exporter->export( $post_id );

        set_transient( 'cb_export_result_' . get_current_user_id(), $result, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=content-broadcaster&tab=export' ) );
        exit;
    }

    /**
     * Handles the Import form submission.
     * Hook: admin_post_cb_import
     *
     * @since 1.0.0
     */
    public function handle_import_request(): void {
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'zwh-content-broadcaster' ) );
        }

        if ( ! isset( $_POST[ self::IMPORT_NONCE_FIELD ] ) ||
             ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::IMPORT_NONCE_FIELD ] ) ), self::IMPORT_NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Security check failed.', 'zwh-content-broadcaster' ) );
        }

        // Validate that a file was actually submitted in the request.
        if ( ! isset( $_FILES['cb_zip_file'] ) || empty( $_FILES['cb_zip_file']['tmp_name'] ) ) {
            wp_die( esc_html__( 'No file was uploaded.', 'zwh-content-broadcaster' ) );
        }

        $importer = new CB_Importer();

        // Pass an optional status override from the form.
        $status_override = isset( $_POST['cb_import_status'] )
            ? sanitize_key( wp_unslash( $_POST['cb_import_status'] ) )
            : '';

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $uploaded_file = $_FILES['cb_zip_file'];
        $result = $importer->import( $uploaded_file, $status_override );

        set_transient( 'cb_import_result_' . get_current_user_id(), $result, 120 );
        wp_safe_redirect( admin_url( 'admin.php?page=content-broadcaster&tab=import' ) );
        exit;
    }

    /**
     * Handles a secure download request for a generated zip archive.
     * Hook: admin_post_cb_download
     *
     * @since 1.0.0
     */
    public function handle_download_request(): void {
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'zwh-content-broadcaster' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $filename = isset( $_GET['filename'] ) ? sanitize_file_name( rawurldecode( wp_unslash( $_GET['filename'] ) ) ) : '';

        if ( empty( $filename ) ) {
            wp_die( esc_html__( 'No filename specified.', 'zwh-content-broadcaster' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) ||
             ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cb_download_' . $filename ) ) {
            wp_die( esc_html__( 'Security check failed.', 'zwh-content-broadcaster' ) );
        }

        $zip_path  = CB_EXPORT_DIR . $filename;
        $real_zip  = realpath( $zip_path );
        $real_base = realpath( CB_EXPORT_DIR );

        if ( ! $real_zip || ! $real_base || ! str_starts_with( $real_zip, $real_base ) ) {
            wp_die( esc_html__( 'File not found or access denied.', 'zwh-content-broadcaster' ) );
        }

        if ( ! file_exists( $real_zip ) ) {
            wp_die( esc_html__( 'The requested archive no longer exists. Please re-export.', 'zwh-content-broadcaster' ) );
        }

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $real_zip ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        readfile( $real_zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    // ── Helper: Post Selector Data ────────────────────────────────────────────

    /**
     * Queries all published posts, pages, and public CPTs grouped by post type.
     *
     * @return array<string, WP_Post[]>
     *
     * @since 1.0.0
     */
    private function get_exportable_posts(): array {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $grouped    = [];

        foreach ( $post_types as $type ) {
            if ( $type->name === 'attachment' ) {
                continue;
            }

            $posts = get_posts( [
                'post_type'      => $type->name,
                'post_status'    => [ 'publish', 'draft', 'private' ],
                'posts_per_page' => 200,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ] );

            if ( ! empty( $posts ) ) {
                $grouped[ $type->labels->name ] = $posts;
            }
        }

        return $grouped;
    }
}
