<?php
/**
 * Self-updater: Check GitHub releases for plugin updates.
 *
 * Hooks into the WordPress plugin update system so the plugin can update
 * itself from GitHub releases without a third-party updater plugin.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks GitHub releases for newer versions and integrates with WP plugin updates.
 */
class APMCP_Self_Updater {

	/**
	 * GitHub repository in "owner/repo" format.
	 *
	 * @var string
	 */
	const GITHUB_REPO = 'anotherpanacea-eng/anotherpanacea-wordpress-mcp';

	/**
	 * Transient key for caching the GitHub API response.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'apmcp_github_update_check';

	/**
	 * Cache duration in seconds (12 hours).
	 *
	 * @var int
	 */
	const CACHE_DURATION = 43200;

	/**
	 * Failure cache duration in seconds (30 minutes).
	 *
	 * @var int
	 */
	const FAILURE_CACHE_DURATION = 1800;

	/**
	 * Plugin basename (e.g. "anotherpanacea-mcp/anotherpanacea-mcp.php").
	 *
	 * @var string
	 */
	private static $plugin_basename = null;

	/**
	 * Initialize the updater hooks.
	 *
	 * @param string $plugin_file Main plugin file path (__FILE__ from the bootstrap).
	 */
	public static function init( $plugin_file ) {
		self::$plugin_basename = plugin_basename( $plugin_file );

		// Inject update info into the transient that WP checks.
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );

		// Also inject when WP reads the transient (belt + suspenders).
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );

		// Supply plugin details for the "View details" modal.
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );

		// Rename the extracted folder to match our expected plugin slug.
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_source' ), 10, 4 );

		// When "Check again" is clicked, clear our transient so we re-fetch.
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ) );

		// Debug REST endpoint (editor+ only).
		add_action( 'rest_api_init', array( __CLASS__, 'register_debug_endpoint' ) );
	}

	/**
	 * Clear the GitHub cache when the user clicks "Check again" on Dashboard > Updates.
	 */
	public static function maybe_force_check() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WP core uses this same pattern in update-core.php without nonce.
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * Register a debug REST endpoint for testing the updater.
	 */
	public static function register_debug_endpoint() {
		register_rest_route(
			'apmcp/v1',
			'/updater-debug',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'debug_endpoint' ),
				'permission_callback' => function () {
					return current_user_can( 'update_plugins' );
				},
			)
		);
	}

	/**
	 * Debug endpoint: show updater state without caching.
	 *
	 * @return WP_REST_Response
	 */
	public static function debug_endpoint() {
		$cached_raw = get_transient( self::TRANSIENT_KEY );

		// Make a fresh GitHub API call (bypass cache).
		$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', self::GITHUB_REPO );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'AnotherPanacea-MCP/' . APMCP_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
				),
			)
		);

		$github_error   = null;
		$github_code    = null;
		$github_release = null;

		if ( is_wp_error( $response ) ) {
			$github_error = $response->get_error_message();
		} else {
			$github_code = wp_remote_retrieve_response_code( $response );
			$body        = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) ) {
				$github_release = array(
					'tag_name'     => $body['tag_name'] ?? null,
					'published_at' => $body['published_at'] ?? null,
					'zipball_url'  => $body['zipball_url'] ?? null,
				);
			}
		}

		$remote_version = null;
		if ( $github_release && ! empty( $github_release['tag_name'] ) ) {
			$remote_version = ltrim( $github_release['tag_name'], 'v' );
		}

		return new WP_REST_Response(
			array(
				'plugin_basename'  => self::$plugin_basename,
				'installed_version' => APMCP_VERSION,
				'cached_transient' => $cached_raw,
				'github_api_error' => $github_error,
				'github_http_code' => $github_code,
				'github_release'   => $github_release,
				'remote_version'   => $remote_version,
				'update_available' => $remote_version ? version_compare( $remote_version, APMCP_VERSION, '>' ) : null,
			),
			200
		);
	}

	/**
	 * Check GitHub for a newer release and inject into the update transient.
	 *
	 * Called by WordPress whenever it rebuilds or reads the update_plugins transient.
	 *
	 * @param object $transient The update_plugins transient object.
	 * @return object Modified transient with our update data (if newer).
	 */
	public static function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// On the 'site_transient_update_plugins' filter, checked may not be set.
		// Fall back to APMCP_VERSION directly.
		$release = self::get_latest_release();
		if ( null === $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );
		$current        = APMCP_VERSION;

		// If checked is available, prefer it (may be more up-to-date).
		if ( ! empty( $transient->checked ) && isset( $transient->checked[ self::$plugin_basename ] ) ) {
			$current = $transient->checked[ self::$plugin_basename ];
		}

		if ( version_compare( $remote_version, $current, '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ self::$plugin_basename ] = (object) array(
				'slug'        => dirname( self::$plugin_basename ),
				'plugin'      => self::$plugin_basename,
				'new_version' => $remote_version,
				'url'         => $release['html_url'],
				'package'     => $release['zipball_url'],
				'icons'       => array(),
				'banners'     => array(),
			);
		}

		return $transient;
	}

	/**
	 * Supply plugin info for the "View version details" modal.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = $args->slug ?? '';
		if ( dirname( self::$plugin_basename ) !== $slug ) {
			return $result;
		}

		$release = self::get_latest_release();
		if ( null === $release ) {
			return $result;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );

		return (object) array(
			'name'            => 'AnotherPanacea MCP',
			'slug'            => $slug,
			'version'         => $remote_version,
			'author'          => '<a href="https://anotherpanacea.com">Joshua Miller</a>',
			'homepage'        => 'https://github.com/' . self::GITHUB_REPO,
			'requires'        => '6.9',
			'requires_php'    => '7.4',
			'download_link'   => $release['zipball_url'],
			'sections'        => array(
				'description'  => 'MCP abilities for WordPress post lifecycle management.',
				'changelog'    => self::format_changelog( $release['body'] ?? '' ),
			),
			'last_updated'    => $release['published_at'] ?? '',
		);
	}

	/**
	 * Rename the extracted GitHub zip folder to the expected plugin directory name.
	 *
	 * GitHub's zipball extracts to "owner-repo-hash/". WordPress expects "anotherpanacea-mcp/".
	 *
	 * @param string       $source        Path to the extracted source directory.
	 * @param string       $remote_source Path to the remote source (unused).
	 * @param WP_Upgrader  $upgrader      The upgrader instance.
	 * @param array        $hook_extra    Extra arguments from the upgrader.
	 * @return string|WP_Error Corrected source path or WP_Error on failure.
	 */
	public static function rename_source( $source, $remote_source, $upgrader, $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only act on our own plugin updates.
		$plugin = $hook_extra['plugin'] ?? '';
		if ( self::$plugin_basename !== $plugin ) {
			return $source;
		}

		$expected_slug = dirname( self::$plugin_basename );
		$corrected     = trailingslashit( dirname( $source ) ) . $expected_slug . '/';

		if ( $source === $corrected ) {
			return $source;
		}

		// Rename the directory.
		$moved = rename( $source, $corrected );
		if ( ! $moved ) {
			return new WP_Error(
				'rename_failed',
				sprintf( 'Could not rename %s to %s.', $source, $corrected ),
				array( 'status' => 500 )
			);
		}

		return $corrected;
	}

	/**
	 * Fetch the latest release from GitHub, with transient caching.
	 *
	 * @return array|null Release data array, or null on failure.
	 */
	private static function get_latest_release() {
		$cached = get_transient( self::TRANSIENT_KEY );

		// Valid cached release data (must be an array with tag_name).
		if ( is_array( $cached ) && ! empty( $cached['tag_name'] ) ) {
			return $cached;
		}

		// Cached failure marker — don't retry until transient expires.
		if ( 'failed' === $cached ) {
			return null;
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', self::GITHUB_REPO );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'AnotherPanacea-MCP/' . APMCP_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for self-updater.
			error_log( 'APMCP self-updater: GitHub API error — ' . $response->get_error_message() );
			set_transient( self::TRANSIENT_KEY, 'failed', self::FAILURE_CACHE_DURATION );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for self-updater.
			error_log( 'APMCP self-updater: GitHub API HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
			set_transient( self::TRANSIENT_KEY, 'failed', self::FAILURE_CACHE_DURATION );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for self-updater.
			error_log( 'APMCP self-updater: unexpected GitHub API response body.' );
			set_transient( self::TRANSIENT_KEY, 'failed', self::FAILURE_CACHE_DURATION );
			return null;
		}

		$release = array(
			'tag_name'     => $body['tag_name'],
			'html_url'     => $body['html_url'] ?? '',
			'zipball_url'  => $body['zipball_url'] ?? '',
			'body'         => $body['body'] ?? '',
			'published_at' => $body['published_at'] ?? '',
		);

		set_transient( self::TRANSIENT_KEY, $release, self::CACHE_DURATION );

		return $release;
	}

	/**
	 * Convert GitHub release notes (Markdown) to basic HTML for the details modal.
	 *
	 * @param string $markdown Release body text.
	 * @return string Simple HTML.
	 */
	private static function format_changelog( $markdown ) {
		if ( empty( $markdown ) ) {
			return '<p>No changelog provided.</p>';
		}

		// Minimal Markdown-to-HTML: headings, bold, lists, line breaks.
		$html = esc_html( $markdown );
		$html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/^[-*] (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html );
		$html = nl2br( $html );

		return $html;
	}
}
