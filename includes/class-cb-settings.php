<?php
/**
 * Class CB_Settings
 *
 * Registers the "Settings" sub-page under the
 * Content Broadcaster top-level menu and manages a repeatable table of
 * remote environment definitions stored in a single wp_options row.
 *
 * Option key : cb_settings
 * Option shape:
 *   [
 *     [
 *       'nickname'    => string,      // Human-readable label, e.g. "Internal Dev 1"
 *       'type'        => string,      // dev | qa | staging | production
 *       'site_url'    => string,      // https://target-site.com
 *       'api_key'     => string,      // WP Application Password or REST API key
 *       'sync_method' => string,      // api_broadcast | manual_zip
 *     ],
 *     …
 *   ]
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Settings {

    // ── Constants ──────────────────────────────────────────────────────────────

    /** wp_options key where the full settings array is stored. */
    const OPTION_KEY = 'cb_settings';

    /** Menu slug for this sub-page. */
    const PAGE_SLUG = 'cb-settings';

    /** Settings-API group name (must match settings_fields() call). */
    const SETTINGS_GROUP = 'cb_settings_group';

    // ── Properties ─────────────────────────────────────────────────────────────

    /** @var string The hook suffix for the settings page. */
    private string $hook_suffix = '';

    // ── Constructor ────────────────────────────────────────────────────────────

    /**
     * Wire up all required WordPress hooks.
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
        add_action( 'admin_init',            [ $this, 'register_setting' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    // ── Menu ───────────────────────────────────────────────────────────────────

    /**
     * Adds a "Settings" sub-menu page under the "Broadcaster" top-level menu.
     *
     * @since 1.0.0
     */
    public function register_submenu(): void {
        $this->hook_suffix = add_submenu_page(
            'content-broadcaster',
            __( 'Content Broadcaster Settings', 'content-broadcaster' ),
            __( 'Settings', 'content-broadcaster' ),
            CB_CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // ── Settings API ───────────────────────────────────────────────────────────

    /**
     * Registers the single option key with WordPress.
     *
     * @since 1.0.0
     */
    public function register_setting(): void {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => [],
            ]
        );
    }

    /**
     * Sanitizes the incoming environments array before it is persisted.
     *
     * @param  mixed $raw  The raw POST value for self::OPTION_KEY.
     * @return array       Clean, typed array of environment definitions.
     *
     * @since 1.0.0
     */
    public function sanitize_settings( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $allowed_types   = [ 'dev', 'qa', 'staging', 'production' ];
        $allowed_methods = [ 'api_broadcast', 'manual_zip' ];
        $clean           = [];

        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $nickname    = sanitize_text_field( $row['nickname']    ?? '' );
            $site_url    = esc_url_raw( trim( $row['site_url']      ?? '' ) );
            $type        = sanitize_key( $row['type']               ?? 'dev' );
            $api_key     = sanitize_text_field( $row['api_key']     ?? '' );
            $sync_method = sanitize_key( $row['sync_method']        ?? 'manual_zip' );

            // Require at minimum a nickname and a URL.
            if ( empty( $nickname ) || empty( $site_url ) ) {
                continue;
            }

            if ( ! in_array( $type, $allowed_types, true ) ) {
                $type = 'dev';
            }
            if ( ! in_array( $sync_method, $allowed_methods, true ) ) {
                $sync_method = 'manual_zip';
            }

            $clean[] = [
                'nickname'    => $nickname,
                'type'        => $type,
                'site_url'    => $site_url,
                'api_key'     => $api_key,
                'sync_method' => $sync_method,
            ];
        }

        return $clean;
    }

    // ── Assets ─────────────────────────────────────────────────────────────────

    /**
     * Enqueues the settings-page JS and supplemental CSS.
     *
     * @param string $hook  Current admin page hook suffix.
     *
     * @since 1.0.0
     */
    public function enqueue_assets( string $hook ): void {
        // Check if we are on our settings page by URL parameter
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
            return;
        }

        wp_enqueue_script(
            'cb-settings-repeater',
            CB_PLUGIN_URL . 'admin/js/cb-settings.js',
            [ 'jquery' ], // Add jquery dependency for AJAX
            CB_VERSION,
            true
        );

        wp_localize_script( 'cb-settings-repeater', 'cbSettings', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cb_settings_nonce' ),
        ] );

        wp_enqueue_style(
            'content-broadcaster-admin',
            CB_PLUGIN_URL . 'admin/css/admin.css',
            [],
            CB_VERSION
        );
    }

    // ── Page Renderer ──────────────────────────────────────────────────────────

    /**
     * Outputs the full HTML for the Settings admin page.
     *
     * @since 1.0.0
     */
    public function render_page(): void {
        if ( ! current_user_can( CB_CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'content-broadcaster' ) );
        }

        $environments = (array) get_option( self::OPTION_KEY, [] );
        $next_index = count( $environments );
        ?>
        <div class="wrap cb-wrap">
            <h1 class="cb-page-title">
                <span class="dashicons dashicons-networking"></span>
                <?php esc_html_e( 'Content Broadcaster — Remote Environments', 'content-broadcaster' ); ?>
            </h1>

            <p class="cb-intro">
                <?php esc_html_e(
                    'Define each remote WordPress environment you want to broadcast content to. Each entry needs a unique nickname, the target site URL, and a valid API key.',
                    'content-broadcaster'
                ); ?>
            </p>

            <?php settings_errors( self::OPTION_KEY ); ?>

            <form method="post" action="options.php" id="cb-settings-form">

                <?php settings_fields( self::SETTINGS_GROUP ); ?>

                <div class="cb-card cb-environments-card">

                    <div class="cb-table-wrapper">
                        <table class="cb-env-table widefat" id="cb-env-table">
                            <thead>
                                <tr>
                                    <th class="cb-col-drag"><?php esc_html_e( '#', 'content-broadcaster' ); ?></th>
                                    <th class="cb-col-nickname"><?php esc_html_e( 'Nickname', 'content-broadcaster' ); ?></th>
                                    <th class="cb-col-type"><?php esc_html_e( 'Type', 'content-broadcaster' ); ?></th>
                                    <th class="cb-col-url"><?php esc_html_e( 'Site URL', 'content-broadcaster' ); ?></th>
                                    <th class="cb-col-key"><?php esc_html_e( 'API Key', 'content-broadcaster' ); ?></th>
                                    <th class="cb-col-sync"><?php esc_html_e( 'Sync Method', 'content-broadcaster' ); ?></th>
                                    <th class="cb-col-actions"></th>
                                </tr>
                            </thead>

                            <tbody id="cb-env-tbody">
                                <?php if ( empty( $environments ) ) : ?>
                                    <tr class="cb-empty-row" id="cb-empty-notice">
                                        <td colspan="7" class="cb-empty-msg">
                                            <?php esc_html_e( 'No environments defined yet. Click "Add Environment" to get started.', 'content-broadcaster' ); ?>
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ( $environments as $index => $env ) : ?>
                                        <?php $this->render_env_row( $index, $env ); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="cb-table-footer">
                        <button type="button"
                                id="cb-add-env-row"
                                class="button cb-add-btn"
                                data-next-index="<?php echo esc_attr( $next_index ); ?>">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e( 'Add Environment', 'content-broadcaster' ); ?>
                        </button>
                    </div>
                </div>

                <?php submit_button(
                    __( 'Save Environments', 'content-broadcaster' ),
                    'primary cb-save-btn',
                    'cb_save'
                ); ?>

            </form>
        </div>

        <template id="cb-row-template">
            <?php $this->render_env_row( '__INDEX__', [], true ); ?>
        </template>
<?php
    }

    // ── Row Renderer ───────────────────────────────────────────────────────────

    /**
     * Outputs a single <tr> for one environment definition.
     *
     * @param int|string $index       Row index (0-based) or '__INDEX__' for the JS template.
     * @param array      $env         Saved environment data.
     * @param bool       $is_template Set to true when rendering the JS cloning template.
     *
     * @since 1.0.0
     */
    private function render_env_row( $index, array $env = [], bool $is_template = false ): void {
        $option  = self::OPTION_KEY;
        $idx     = esc_attr( (string) $index );

        $nickname    = esc_attr( $env['nickname']    ?? '' );
        $site_url    = esc_attr( $env['site_url']    ?? '' );
        $api_key     = esc_attr( $env['api_key']     ?? '' );
        $type        = $env['type']        ?? 'dev';
        $sync_method = $env['sync_method'] ?? 'manual_zip';

        $type_options = [
            'dev'        => __( 'Dev',        'content-broadcaster' ),
            'qa'         => __( 'QA',         'content-broadcaster' ),
            'staging'    => __( 'Staging',    'content-broadcaster' ),
            'production' => __( 'Production', 'content-broadcaster' ),
        ];

        $sync_options = [
            'api_broadcast' => __( 'Direct API Broadcast', 'content-broadcaster' ),
            'manual_zip'    => __( 'Manual Download & Install', 'content-broadcaster' ),
        ];
        ?>
        <tr class="cb-env-row" data-index="<?php echo $idx; ?>">

            <td class="cb-col-drag cb-row-num">
                <span class="cb-row-index"><?php echo is_numeric( $index ) ? (int) $index + 1 : '#'; ?></span>
            </td>

            <td class="cb-col-nickname">
                <input type="text"
                       name="<?php echo esc_attr( "{$option}[{$idx}][nickname]" ); ?>"
                       value="<?php echo $nickname; ?>"
                       placeholder="<?php esc_attr_e( 'e.g. Internal Dev 1', 'content-broadcaster' ); ?>"
                       class="regular-text cb-input"
                       aria-label="<?php esc_attr_e( 'Nickname', 'content-broadcaster' ); ?>"
                       required>
            </td>

            <td class="cb-col-type">
                <select name="<?php echo esc_attr( "{$option}[{$idx}][type]" ); ?>"
                        class="cb-select cb-type-select"
                        aria-label="<?php esc_attr_e( 'Environment Type', 'content-broadcaster' ); ?>">
                    <?php foreach ( $type_options as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>"
                                <?php selected( $type, $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>

            <td class="cb-col-url">
                <input type="url"
                       name="<?php echo esc_attr( "{$option}[{$idx}][site_url]" ); ?>"
                       value="<?php echo $site_url; ?>"
                       placeholder="https://target-site.com"
                       class="regular-text cb-input cb-url-input"
                       aria-label="<?php esc_attr_e( 'Site URL', 'content-broadcaster' ); ?>"
                       required>
            </td>

            <td class="cb-col-key">
                <div class="cb-password-wrap" style="display: flex; gap: 8px;">
                    <input type="password"
                           name="<?php echo esc_attr( "{$option}[{$idx}][api_key]" ); ?>"
                           value="<?php echo $api_key; ?>"
                           placeholder="<?php esc_attr_e( 'xxxx xxxx xxxx xxxx', 'content-broadcaster' ); ?>"
                           class="regular-text cb-input cb-api-key-input"
                           aria-label="<?php esc_attr_e( 'API Key', 'content-broadcaster' ); ?>"
                           autocomplete="new-password">
                    <button type="button"
                            class="button-link cb-toggle-pw"
                            aria-label="<?php esc_attr_e( 'Toggle visibility', 'content-broadcaster' ); ?>"
                            title="<?php esc_attr_e( 'Show / Hide', 'content-broadcaster' ); ?>">
                        <span class="dashicons dashicons-visibility cb-eye-icon"></span>
                    </button>
                    <button type="button"
                            class="button cb-generate-key"
                            title="<?php esc_attr_e( 'Generate random API key', 'content-broadcaster' ); ?>">
                        🔑
                    </button>
                </div>
            </td>

            <td class="cb-col-sync">
                <select name="<?php echo esc_attr( "{$option}[{$idx}][sync_method]" ); ?>"
                        class="cb-select cb-sync-select"
                        aria-label="<?php esc_attr_e( 'Sync Method', 'content-broadcaster' ); ?>">
                    <?php foreach ( $sync_options as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>"
                                <?php selected( $sync_method, $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>

            <td class="cb-col-actions">
                <div class="cb-row-actions">
                    <button type="button"
                            class="button cb-test-connection-btn"
                            title="<?php esc_attr_e( 'Test Connection', 'content-broadcaster' ); ?>"
                            data-index="<?php echo $idx; ?>">
                        <span class="dashicons dashicons-admin-links"></span>
                    </button>
                    <button type="button"
                            class="button cb-remove-row"
                            aria-label="<?php esc_attr_e( 'Remove', 'content-broadcaster' ); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
                <div class="cb-test-status"></div>
            </td>

        </tr>
        <?php
    }

    // ── Static Helpers ─────────────────────────────────────────────────────────

    /**
     * Returns the saved environments array directly.
     *
     * @return array<int, array<string, string>>
     *
     * @since 1.0.0
     */
    public static function get_environments(): array {
        return (array) get_option( self::OPTION_KEY, [] );
    }

    /**
     * Returns a single environment by its index.
     *
     * @param int $index
     * @return array<string, string>|null
     *
     * @since 1.0.0
     */
    public static function get_environment( int $index ): ?array {
        $envs = self::get_environments();
        return $envs[ $index ] ?? null;
    }
}
