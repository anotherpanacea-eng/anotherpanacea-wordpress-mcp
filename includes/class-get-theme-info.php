<?php
/**
 * Get-theme-info ability: Detailed information about a specific theme.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed theme information via the MCP abilities API.
 */
class APMCP_Get_Theme_Info {

	/**
	 * Register the get-theme-info ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/get-theme-info',
			array(
				'label'               => __( 'Get Theme Info', 'anotherpanacea-mcp' ),
				'description'         => __( 'Get detailed information about a theme: metadata, theme.json contents, file tree (templates, parts, patterns, styles, assets), and screenshot URL.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => 'Theme stylesheet (directory name). Omit for active theme.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array( 'type' => 'string' ),
						'name'       => array( 'type' => 'string' ),
						'version'    => array( 'type' => 'string' ),
						'type'       => array( 'type' => 'string' ),
						'is_active'  => array( 'type' => 'boolean' ),
						'theme_json' => array( 'description' => 'Parsed theme.json contents or null.' ),
						'file_tree'  => array( 'type' => 'object' ),
						'screenshot' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the get-theme-info ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view theme information.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the get-theme-info ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$stylesheet = $input['stylesheet'] ?? get_stylesheet();

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'not_found', "Theme '{$stylesheet}' not found.", array( 'status' => 404 ) );
		}

		$is_block  = method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme();
		$theme_dir = $theme->get_stylesheet_directory();

		// Read theme.json if present.
		$theme_json      = null;
		$theme_json_path = $theme_dir . '/theme.json';
		if ( file_exists( $theme_json_path ) ) {
			$raw = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $raw ) {
				$theme_json = json_decode( $raw, true );
			}
		}

		// Build file tree for key directories.
		$file_tree = array();
		$dirs      = array( 'templates', 'parts', 'patterns', 'styles', 'assets', 'inc' );

		foreach ( $dirs as $dir ) {
			$dir_path = $theme_dir . '/' . $dir;
			if ( is_dir( $dir_path ) ) {
				$file_tree[ $dir ] = self::scan_directory( $dir_path, $theme_dir );
			}
		}

		// Root-level files.
		$root_files = array();
		$root_items = scandir( $theme_dir );
		if ( false !== $root_items ) {
			foreach ( $root_items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				if ( is_file( $theme_dir . '/' . $item ) ) {
					$root_files[] = $item;
				}
			}
		}
		$file_tree['root_files'] = $root_files;

		// Parent theme info.
		$parent_info = null;
		$parent      = $theme->parent();
		if ( $parent ) {
			$parent_info = array(
				'stylesheet' => $parent->get_stylesheet(),
				'name'       => $parent->get( 'Name' ),
				'version'    => $parent->get( 'Version' ),
			);
		}

		// Screenshot URL.
		$screenshot = $theme->get_screenshot();

		return array(
			'stylesheet'   => $stylesheet,
			'name'         => $theme->get( 'Name' ),
			'version'      => $theme->get( 'Version' ),
			'description'  => $theme->get( 'Description' ),
			'author'       => $theme->get( 'Author' ),
			'author_uri'   => $theme->get( 'AuthorURI' ),
			'theme_uri'    => $theme->get( 'ThemeURI' ),
			'type'         => $is_block ? 'block' : 'classic',
			'is_active'    => ( get_stylesheet() === $stylesheet ),
			'parent'       => $parent_info,
			'theme_json'   => $theme_json,
			'file_tree'    => $file_tree,
			'screenshot'   => $screenshot ? $screenshot : null,
			'tags'         => $theme->get( 'Tags' ) ? $theme->get( 'Tags' ) : array(),
			'requires_wp'  => $theme->get( 'RequiresWP' ),
			'requires_php' => $theme->get( 'RequiresPHP' ),
			'text_domain'  => $theme->get( 'TextDomain' ),
		);
	}

	/**
	 * Recursively scan a directory and return relative file paths.
	 *
	 * @param string $dir_path  Absolute path to scan.
	 * @param string $theme_dir Theme root directory for relative paths.
	 * @return array List of relative file paths.
	 */
	private static function scan_directory( $dir_path, $theme_dir ) {
		$files = array();
		$items = scandir( $dir_path );

		if ( false === $items ) {
			return $files;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$full_path     = $dir_path . '/' . $item;
			$relative_path = ltrim( str_replace( $theme_dir, '', $full_path ), '/' );

			if ( is_dir( $full_path ) ) {
				// Recurse into subdirectories.
				$sub_files = self::scan_directory( $full_path, $theme_dir );
				$files     = array_merge( $files, $sub_files );
			} else {
				$files[] = $relative_path;
			}
		}

		return $files;
	}
}
