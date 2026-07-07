<?php
/**
 * Plugin Name:       ZWH Content Broadcaster
 * Update URI:  https://github.com/ziv-was-here/zwh-content-broadcaster
 * Plugin URI:        https://github.com/ziv-was-here/zwh-content-broadcaster
 * Description:       Export and broadcast posts, pages, and custom content between WordPress environments via REST API or portable .zip archives. Perfect for multi-site workflows, content syndication, and environment synchronization.
 * Version:           1.1.0
 * Author:            Ziv Rozenberg
 * Author URI:        https://zivwashere.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zwh-content-broadcaster
 * Domain Path:       /languages
 * Requires at least: 5.9
 * Requires PHP:      7.4
 *
 * @package ContentBroadcaster
 */

// Exit immediately if accessed directly — no WordPress context.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Plugin Constants ────────────────────────────────────────────────────────

/** Absolute path to the plugin root directory (with trailing slash). */
define( 'CB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );

/** Public URL to the plugin root directory (with trailing slash). */
define( 'CB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/** Plugin version string — bump on each release. */
define( 'CB_VERSION',     '1.1.0' );

/**
 * Writable directory where generated zip archives are temporarily stored
 * before being served for download. Uses the standard WordPress upload path.
 */
define( 'CB_EXPORT_DIR',  trailingslashit( wp_upload_dir()['basedir'] ) . 'content-broadcaster/' );

/**
 * Capability required to use any broadcaster feature.
 * Change to 'edit_posts' to open access to editors.
 */
define( 'CB_CAPABILITY', 'export' );

// ─── Autoload Core Files ─────────────────────────────────────────────────────

require_once CB_PLUGIN_DIR . 'includes/class-cb-exporter.php';
require_once CB_PLUGIN_DIR . 'includes/class-cb-importer.php';
require_once CB_PLUGIN_DIR . 'includes/class-cb-api-settings.php';
require_once CB_PLUGIN_DIR . 'includes/class-cb-settings.php';
require_once CB_PLUGIN_DIR . 'admin/class-cb-admin.php';
require_once CB_PLUGIN_DIR . 'admin/class-cb-received-page.php';
require_once CB_PLUGIN_DIR . 'includes/class-cb-api-receiver.php';
require_once CB_PLUGIN_DIR . 'includes/class-cb-api-sender.php';
require_once CB_PLUGIN_DIR . 'admin/class-cb-send-metabox.php';
require_once CB_PLUGIN_DIR . 'includes/class-cb-ajax-handler.php';

// ─── Bootstrap ───────────────────────────────────────────────────────────────

/**
 * Fires after all plugins have loaded — safe place to initialise our classes.
 */
add_action( 'plugins_loaded', 'cb_init' );

/**
 * Instantiate the admin controller.
 * The exporter is instantiated on-demand inside the admin class.
 *
 * @since 1.0.0
 */
function cb_init(): void {
    // Load text domain for translations — WordPress.org handles this automatically now.

    // Register API receiver (hooks into rest_api_init)
    CB_API_Receiver::register();

    if ( is_admin() ) {
        new CB_Admin();
        new CB_Settings(); // Unified settings.
        CB_Received_Page::register(); // Received files admin page.
        CB_Send_Metabox::register(); // Send via API metabox on post edit screens.
        new CB_Ajax_Handler();
    }
}

/**
 * Plugin activation hook.
 * Creates the temporary export directory with an index.php stub to prevent
 * direct directory listing.
 *
 * @since 1.0.0
 */
register_activation_hook( __FILE__, 'cb_activate' );

function cb_activate(): void {
    if ( ! file_exists( CB_EXPORT_DIR ) ) {
        wp_mkdir_p( CB_EXPORT_DIR );
    }

    // Drop a silent index file so the folder isn't browsable.
    $index = CB_EXPORT_DIR . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }

    // Add a .htaccess that blocks direct HTTP downloads (Apache / LiteSpeed).
    $htaccess = CB_EXPORT_DIR . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents(
            $htaccess,
            "Options -Indexes\n<FilesMatch \"\.zip$\">\n  Order allow,deny\n  Deny from all\n</FilesMatch>\n"
        );
    }
}

/**
 * Plugin deactivation hook — intentionally leaves generated zips in place so
 * the user doesn't lose in-progress exports.
 *
 * @since 1.0.0
 */
register_deactivation_hook( __FILE__, 'cb_deactivate' );

function cb_deactivate(): void {
    // Nothing aggressive needed on deactivation.
}

// ---------------------------------------------------------------------------
// GitHub update system
// ---------------------------------------------------------------------------
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zwh-github-updater.php';
new ZWH_GitHub_Updater( __FILE__, 'ziv-was-here/zwh-content-broadcaster' );
