<?php
/**
 * Get-theme-file ability: Read the contents of a theme file.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads theme files via the MCP abilities API.
 */
class APMCP_Get_Theme_File {

	/**
	 * Allowed file extensions for reading.
	 *
	 * @var string[]
	 */
	private static $allowed_extensions = array(
		'php',
		'css',
		'js',
		'json',
		'html',
		'htm',
		'txt',
		'md',
		'svg',
		'xml',
		'yaml',
		'yml',
		'mustache',
		'twig',
	);

	/**
	 * Register the get-theme-file ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/get-theme-file',
			array(
				'label'               => __( 'Get Theme File', 'anotherpanacea-mcp' ),
				'description'         => __( 'Read the contents of a file in a theme. Supports style.css, theme.json, templates/*.html, parts/*.html, patterns/*.php, styles/*.json, functions.php, assets/*, and more.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'file_path' ),
					'properties' => array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => 'Theme stylesheet (directory name). Omit for active theme.',
						),
						'file_path'  => array(
							'type'        => 'string',
							'description' => 'Relative path within the theme, e.g. "style.css", "templates/single.html", "theme.json", "patterns/hero.php".',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'file_path'  => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'size_bytes' => array( 'type' => 'integer' ),
						'extension'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the get-theme-file ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to read theme files.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the get-theme-file ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$stylesheet = $input['stylesheet'] ?? get_stylesheet();
		$file_path  = $input['file_path'] ?? '';

		if ( empty( $file_path ) ) {
			return new WP_Error( 'missing_param', 'file_path is required.', array( 'status' => 400 ) );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'not_found', "Theme '{$stylesheet}' not found.", array( 'status' => 404 ) );
		}

		// Validate the file path (prevent traversal).
		$validated = self::validate_path( $file_path, $theme->get_stylesheet_directory() );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$absolute_path = $validated;

		if ( ! file_exists( $absolute_path ) ) {
			return new WP_Error( 'not_found', "File '{$file_path}' not found in theme '{$stylesheet}'.", array( 'status' => 404 ) );
		}

		if ( ! is_file( $absolute_path ) ) {
			return new WP_Error( 'invalid_path', "Path '{$file_path}' is a directory, not a file.", array( 'status' => 400 ) );
		}

		// Check file extension.
		$extension = strtolower( pathinfo( $absolute_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::$allowed_extensions, true ) ) {
			return new WP_Error(
				'invalid_extension',
				"File extension '.{$extension}' is not readable. Allowed: " . implode( ', ', self::$allowed_extensions ),
				array( 'status' => 400 )
			);
		}

		// Size guard: 1MB max.
		$size = filesize( $absolute_path );
		if ( $size > 1048576 ) {
			return new WP_Error( 'file_too_large', 'File exceeds 1MB limit for reading.', array( 'status' => 400 ) );
		}

		$content = file_get_contents( $absolute_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return new WP_Error( 'read_error', "Could not read file '{$file_path}'.", array( 'status' => 500 ) );
		}

		return array(
			'file_path'  => $file_path,
			'stylesheet' => $stylesheet,
			'content'    => $content,
			'size_bytes' => $size,
			'extension'  => $extension,
		);
	}

	/**
	 * Validate a relative file path, preventing directory traversal.
	 *
	 * @param string $relative_path Relative path within the theme.
	 * @param string $theme_dir     Absolute path to the theme directory.
	 * @return string|WP_Error Absolute path or error.
	 */
	public static function validate_path( $relative_path, $theme_dir ) {
		// Strip leading slashes.
		$relative_path = ltrim( $relative_path, '/' );

		// Block obvious traversal attempts.
		if ( false !== strpos( $relative_path, '..' ) ) {
			return new WP_Error( 'invalid_path', 'Path traversal is not allowed.', array( 'status' => 400 ) );
		}

		$absolute_path = $theme_dir . '/' . $relative_path;
		$real_path     = realpath( $absolute_path );

		// For existing files, verify they're within the theme directory.
		if ( false !== $real_path ) {
			$real_theme_dir = realpath( $theme_dir );
			if ( false === $real_theme_dir || 0 !== strpos( $real_path, $real_theme_dir . '/' ) ) {
				return new WP_Error( 'invalid_path', 'Path resolves outside the theme directory.', array( 'status' => 400 ) );
			}
			return $real_path;
		}

		// For new files (don't exist yet), validate the parent directory.
		$parent_dir  = dirname( $absolute_path );
		$real_parent = realpath( $parent_dir );

		if ( false !== $real_parent ) {
			$real_theme_dir = realpath( $theme_dir );
			if ( false === $real_theme_dir || 0 !== strpos( $real_parent, $real_theme_dir ) ) {
				return new WP_Error( 'invalid_path', 'Path resolves outside the theme directory.', array( 'status' => 400 ) );
			}
		}

		return $absolute_path;
	}
}
