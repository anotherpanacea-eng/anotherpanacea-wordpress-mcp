<?php
/**
 * Activate-theme ability: Switch the active WordPress theme.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activates a theme via the MCP abilities API.
 */
class APMCP_Activate_Theme {

	/**
	 * Register the activate-theme ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/activate-theme',
			array(
				'label'               => __( 'Activate Theme', 'anotherpanacea-mcp' ),
				'description'         => __( 'Switch the active WordPress theme. Supports dry-run mode to preview the change without activating.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'stylesheet' ),
					'properties' => array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => 'Theme stylesheet (directory name) to activate.',
						),
						'dry_run'    => array(
							'type'        => 'boolean',
							'description' => 'If true, validate the theme can be activated without switching.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet'     => array( 'type' => 'string' ),
						'activated'      => array( 'type' => 'boolean' ),
						'previous_theme' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the activate-theme ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'switch_themes' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to switch themes.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the activate-theme ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$stylesheet = $input['stylesheet'] ?? '';
		$dry_run    = ! empty( $input['dry_run'] );

		if ( empty( $stylesheet ) ) {
			return new WP_Error( 'missing_param', 'stylesheet is required.', array( 'status' => 400 ) );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'not_found', "Theme '{$stylesheet}' not found.", array( 'status' => 404 ) );
		}

		// Check for errors (e.g. missing required files, broken theme).
		$errors = $theme->errors();
		if ( is_wp_error( $errors ) ) {
			return new WP_Error(
				'theme_error',
				'Theme has errors: ' . $errors->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$previous_stylesheet = get_stylesheet();

		if ( $stylesheet === $previous_stylesheet ) {
			return array(
				'stylesheet'     => $stylesheet,
				'activated'      => true,
				'previous_theme' => $previous_stylesheet,
				'note'           => 'Theme was already active.',
			);
		}

		if ( $dry_run ) {
			return array(
				'dry_run'        => true,
				'stylesheet'     => $stylesheet,
				'name'           => $theme->get( 'Name' ),
				'type'           => ( method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme() ) ? 'block' : 'classic',
				'previous_theme' => $previous_stylesheet,
				'can_activate'   => true,
			);
		}

		// Switch the theme.
		switch_theme( $stylesheet );

		// Verify the switch.
		$new_active = get_stylesheet();
		if ( $new_active !== $stylesheet ) {
			return new WP_Error( 'switch_failed', 'Theme switch did not take effect.', array( 'status' => 500 ) );
		}

		return array(
			'stylesheet'     => $stylesheet,
			'name'           => $theme->get( 'Name' ),
			'activated'      => true,
			'previous_theme' => $previous_stylesheet,
		);
	}
}
