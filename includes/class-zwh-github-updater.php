<?php
/**
 * ZWH GitHub Updater — drop-in updater for GitHub-hosted plugins.
 *
 * Checks GitHub Releases and integrates with WordPress's native plugin
 * update system (update notices, one-click update, "View details" modal).
 *
 * Usage (in the plugin main file):
 *
 *   require_once __DIR__ . '/includes/class-zwh-github-updater.php';
 *   new ZWH_GitHub_Updater( __FILE__, 'ziv-was-here/my-plugin-repo' );
 *
 * Publishing an update:
 *   1. Bump Version: in the plugin header.
 *   2. Tag & push (e.g. v1.1.0) — or create a GitHub Release manually.
 *   3. Attach a zip named {slug}.zip whose top-level folder is the slug
 *      (the bundled GitHub Actions workflow does this automatically).
 *      If no asset is attached, the updater falls back to the source zipball.
 *
 * @version 1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZWH_GitHub_Updater' ) ) :

class ZWH_GitHub_Updater {

	/** @var string Absolute path to the plugin main file. */
	private $file;

	/** @var string "owner/repo" on GitHub. */
	private $repo;

	/** @var string Plugin basename, e.g. "my-plugin/my-plugin.php". */
	private $basename;

	/** @var string Plugin directory slug, e.g. "my-plugin". */
	private $slug;

	/** @var array|null Plugin header data (lazy-loaded). */
	private $plugin_data = null;

	/** @var string Cache key for the GitHub API response. */
	private $cache_key;

	/** @var int Seconds to cache the release check. */
	private $cache_ttl;

	/** @var string Optional GitHub token (for private repos or rate limits). */
	private $token;

	/**
	 * @param string $file      __FILE__ of the plugin main file.
	 * @param string $repo      GitHub "owner/repo".
	 * @param array  $args      Optional: [ 'cache_ttl' => 21600, 'token' => '' ].
	 */
	public function __construct( $file, $repo, array $args = array() ) {
		$this->file      = $file;
		$this->repo      = $repo;
		$this->basename  = plugin_basename( $file );
		$this->slug      = dirname( $this->basename );
		$this->cache_key = 'zwh_ghu_' . md5( $this->repo );
		$this->cache_ttl = isset( $args['cache_ttl'] ) ? (int) $args['cache_ttl'] : 6 * HOUR_IN_SECONDS;
		$this->token     = isset( $args['token'] ) ? $args['token'] : '';

		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache' ), 10, 2 );
	}

	// -------------------------------------------------------------------
	// Update check
	// -------------------------------------------------------------------

	/**
	 * Injects update info into the update_plugins site transient.
	 *
	 * @param object|false $transient
	 * @return object|false
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$new_version     = $this->normalize_version( $release['tag_name'] );
		$current_version = $this->get_plugin_data()['Version'];

		$item = (object) array(
			'id'          => 'github.com/' . $this->repo,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $new_version,
			'url'         => 'https://github.com/' . $this->repo,
			'package'     => $this->get_package_url( $release ),
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => '',
			'requires'    => $this->get_plugin_data()['RequiresWP'],
		);

		if ( version_compare( $new_version, $current_version, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}

		return $transient;
	}

	/**
	 * Handler for the update_plugins_{hostname} filter (WP 5.8+), used when
	 * the plugin header declares "Update URI: https://github.com/owner/repo".
	 *
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @param string      $plugin_file
	 * @return array|false
	 */
	public function check_update( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $update;
		}

		$new_version = $this->normalize_version( $release['tag_name'] );
		if ( ! version_compare( $new_version, $plugin_data['Version'], '>' ) ) {
			return $update;
		}

		return array(
			'id'      => 'github.com/' . $this->repo,
			'slug'    => $this->slug,
			'plugin'  => $this->basename,
			'version' => $new_version,
			'url'     => 'https://github.com/' . $this->repo,
			'package' => $this->get_package_url( $release ),
		);
	}

	// -------------------------------------------------------------------
	// "View details" modal
	// -------------------------------------------------------------------

	/**
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$data = $this->get_plugin_data();

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $this->slug,
			'version'       => $this->normalize_version( $release['tag_name'] ),
			'author'        => $data['Author'],
			'homepage'      => 'https://github.com/' . $this->repo,
			'requires'      => $data['RequiresWP'],
			'requires_php'  => $data['RequiresPHP'],
			'last_updated'  => isset( $release['published_at'] ) ? $release['published_at'] : '',
			'download_link' => $this->get_package_url( $release ),
			'sections'      => array(
				'description' => $data['Description'],
				'changelog'   => $this->format_changelog( $release ),
			),
		);
	}

	// -------------------------------------------------------------------
	// Install fixes
	// -------------------------------------------------------------------

	/**
	 * GitHub zipballs extract to "owner-repo-hash/". Rename the extracted
	 * folder to the plugin slug so WP overwrites the right directory.
	 *
	 * @param string      $source
	 * @param string      $remote_source
	 * @param WP_Upgrader $upgrader
	 * @param array       $hook_extra
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ) ) ) {
			return $desired;
		}

		return new WP_Error(
			'zwh_ghu_rename_failed',
			sprintf( 'Could not rename update folder for %s.', $this->slug )
		);
	}

	/**
	 * Clear the release cache after this plugin is updated.
	 *
	 * @param WP_Upgrader $upgrader
	 * @param array       $hook_extra
	 */
	public function purge_cache( $upgrader, $hook_extra ) {
		if (
			isset( $hook_extra['action'], $hook_extra['type'] ) &&
			'update' === $hook_extra['action'] &&
			'plugin' === $hook_extra['type'] &&
			! empty( $hook_extra['plugins'] ) &&
			in_array( $this->basename, (array) $hook_extra['plugins'], true )
		) {
			delete_site_transient( $this->cache_key );
		}
	}

	// -------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------

	/**
	 * Fetch the latest release from GitHub (cached).
	 *
	 * @return array|false Decoded release array, or false.
	 */
	private function get_latest_release() {
		$cached = get_site_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return false; // Negative cache — avoid hammering the API.
		}

		$url  = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );
		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
		);
		if ( $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get( $url, $args );

		if (
			is_wp_error( $response ) ||
			200 !== wp_remote_retrieve_response_code( $response )
		) {
			// Cache the failure briefly so a broken repo doesn't slow wp-admin.
			set_site_transient( $this->cache_key, 'none', 30 * MINUTE_IN_SECONDS );
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $release['tag_name'] ) ) {
			set_site_transient( $this->cache_key, 'none', 30 * MINUTE_IN_SECONDS );
			return false;
		}

		set_site_transient( $this->cache_key, $release, $this->cache_ttl );
		return $release;
	}

	/**
	 * Prefer a release asset named "{slug}.zip"; fall back to any .zip
	 * asset, then the auto-generated source zipball.
	 *
	 * @param array $release
	 * @return string
	 */
	private function get_package_url( array $release ) {
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( $asset['name'] === $this->slug . '.zip' ) {
					return $asset['browser_download_url'];
				}
			}
			foreach ( $release['assets'] as $asset ) {
				if ( substr( $asset['name'], -4 ) === '.zip' ) {
					return $asset['browser_download_url'];
				}
			}
		}
		return isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/** Strip a leading "v" from a tag name. */
	private function normalize_version( $tag ) {
		return ltrim( (string) $tag, 'vV' );
	}

	/** Release notes → simple HTML for the changelog tab. */
	private function format_changelog( array $release ) {
		$body = isset( $release['body'] ) ? trim( $release['body'] ) : '';
		if ( '' === $body ) {
			return 'See the <a href="https://github.com/' . esc_attr( $this->repo ) . '/releases">GitHub releases page</a>.';
		}
		return '<pre style="white-space:pre-wrap">' . esc_html( $body ) . '</pre>';
	}

	/** Lazy-load plugin header data. */
	private function get_plugin_data() {
		if ( null === $this->plugin_data ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->plugin_data = get_plugin_data( $this->file, false, false );
		}
		return $this->plugin_data;
	}
}

endif;
