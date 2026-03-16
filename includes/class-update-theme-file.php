<?php
/**
 * Update-theme-file ability: Write or update a file in a theme.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes or updates theme files via the MCP abilities API.
 */
class APMCP_Update_Theme_File {

	/**
	 * Allowed file extensions for writing.
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
	 * Register the update-theme-file ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/update-theme-file',
			array(
				'label'               => __( 'Update Theme File', 'anotherpanacea-mcp' ),
				'description'         => __( 'Write or update a file in a theme. Creates the file if it does not exist. Supports templates, parts, patterns, styles, assets, theme.json, style.css, functions.php, and more.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'file_path', 'content' ),
					'properties' => array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => 'Theme stylesheet (directory name). Omit for active theme.',
						),
						'file_path'  => array(
							'type'        => 'string',
							'description' => 'Relative path within the theme, e.g. "templates/single.html", "theme.json", "patterns/hero.php", "assets/css/custom.css".',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'File content to write.',
						),
						'dry_run'    => array(
							'type'        => 'boolean',
							'description' => 'If true, validate the operation without writing.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'file_path'  => array( 'type' => 'string' ),
						'action'     => array( 'type' => 'string' ),
						'size_bytes' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the update-theme-file ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_themes' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to modify theme files.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the update-theme-file ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$stylesheet = $input['stylesheet'] ?? get_stylesheet();
		$file_path  = $input['file_path'] ?? '';
		$content    = $input['content'] ?? '';
		$dry_run    = ! empty( $input['dry_run'] );

		if ( empty( $file_path ) ) {
			return new WP_Error( 'missing_param', 'file_path is required.', array( 'status' => 400 ) );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'not_found', "Theme '{$stylesheet}' not found.", array( 'status' => 404 ) );
		}

		$theme_dir = $theme->get_stylesheet_directory();

		// Validate the file path (prevent traversal).
		$validated = APMCP_Get_Theme_File::validate_path( $file_path, $theme_dir );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$absolute_path = $validated;

		// Check file extension.
		$extension = strtolower( pathinfo( $absolute_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::$allowed_extensions, true ) ) {
			return new WP_Error(
				'invalid_extension',
				"File extension '.{$extension}' is not writable. Allowed: " . implode( ', ', self::$allowed_extensions ),
				array( 'status' => 400 )
			);
		}

		// Size guard: 1MB max for content.
		if ( strlen( $content ) > 1048576 ) {
			return new WP_Error( 'content_too_large', 'Content exceeds 1MB limit.', array( 'status' => 400 ) );
		}

		// Validate JSON for theme.json and styles/*.json.
		if ( 'json' === $extension ) {
			$decoded = json_decode( $content, true );
			if ( null === $decoded && '' !== trim( $content ) ) {
				return new WP_Error( 'invalid_json', 'Content is not valid JSON.', array( 'status' => 400 ) );
			}
		}

		$exists = file_exists( $absolute_path );
		$action = $exists ? 'updated' : 'created';

		if ( $dry_run ) {
			return array(
				'dry_run'    => true,
				'file_path'  => $file_path,
				'stylesheet' => $stylesheet,
				'action'     => $action,
				'size_bytes' => strlen( $content ),
				'valid'      => true,
			);
		}

		// Create parent directory if needed.
		$parent_dir = dirname( $absolute_path );
		if ( ! is_dir( $parent_dir ) ) {
			if ( ! wp_mkdir_p( $parent_dir ) ) {
				return new WP_Error( 'create_dir_failed', 'Could not create parent directory.', array( 'status' => 500 ) );
			}
		}

		// Write the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $absolute_path, $content );
		if ( false === $written ) {
			return new WP_Error( 'write_failed', "Could not write file '{$file_path}'.", array( 'status' => 500 ) );
		}

		// Clear theme caches so changes are reflected.
		wp_clean_themes_cache();

		return array(
			'file_path'  => $file_path,
			'stylesheet' => $stylesheet,
			'action'     => $action,
			'size_bytes' => $written,
		);
	}
}
