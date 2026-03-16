<?php
/**
 * Delete-theme-file ability: Remove a file from a theme.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes theme files via the MCP abilities API.
 */
class APMCP_Delete_Theme_File {

	/**
	 * Files that must not be deleted (theme won't function without them).
	 *
	 * @var string[]
	 */
	private static $protected_files = array(
		'style.css',
		'templates/index.html',
	);

	/**
	 * Register the delete-theme-file ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/delete-theme-file',
			array(
				'label'               => __( 'Delete Theme File', 'anotherpanacea-mcp' ),
				'description'         => __( 'Delete a file from a theme. Protected files (style.css, templates/index.html) cannot be deleted. Use for removing unused patterns, templates, style variations, or assets.', 'anotherpanacea-mcp' ),
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
							'description' => 'Relative path within the theme to delete, e.g. "patterns/old-hero.php", "styles/dark.json".',
						),
						'dry_run'    => array(
							'type'        => 'boolean',
							'description' => 'If true, validate the operation without deleting.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'file_path' => array( 'type' => 'string' ),
						'deleted'   => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the delete-theme-file ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_themes' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to delete theme files.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the delete-theme-file ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$stylesheet = $input['stylesheet'] ?? get_stylesheet();
		$file_path  = $input['file_path'] ?? '';
		$dry_run    = ! empty( $input['dry_run'] );

		if ( empty( $file_path ) ) {
			return new WP_Error( 'missing_param', 'file_path is required.', array( 'status' => 400 ) );
		}

		// Normalize path for comparison.
		$normalized = ltrim( $file_path, '/' );

		// Check protected files.
		if ( in_array( $normalized, self::$protected_files, true ) ) {
			return new WP_Error(
				'protected_file',
				"'{$normalized}' is a required theme file and cannot be deleted.",
				array( 'status' => 403 )
			);
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

		if ( ! file_exists( $absolute_path ) ) {
			return new WP_Error( 'not_found', "File '{$file_path}' not found in theme '{$stylesheet}'.", array( 'status' => 404 ) );
		}

		if ( ! is_file( $absolute_path ) ) {
			return new WP_Error( 'invalid_path', "Path '{$file_path}' is a directory, not a file. Only individual files can be deleted.", array( 'status' => 400 ) );
		}

		if ( $dry_run ) {
			return array(
				'dry_run'    => true,
				'file_path'  => $file_path,
				'stylesheet' => $stylesheet,
				'deletable'  => true,
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$deleted = unlink( $absolute_path );
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', "Could not delete file '{$file_path}'.", array( 'status' => 500 ) );
		}

		// Clear theme caches.
		wp_clean_themes_cache();

		return array(
			'file_path'  => $file_path,
			'stylesheet' => $stylesheet,
			'deleted'    => true,
		);
	}
}
