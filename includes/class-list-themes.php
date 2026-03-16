<?php
/**
 * List-themes ability: List installed WordPress themes.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists installed themes via the MCP abilities API.
 */
class APMCP_List_Themes {

	/**
	 * Register the list-themes ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/list-themes',
			array(
				'label'               => __( 'List Themes', 'anotherpanacea-mcp' ),
				'description'         => __( 'List installed WordPress themes with status (active/inactive), type (block/classic), version, and parent theme info.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Filter by status: active, inactive, or all. Default all.',
							'enum'        => array( 'active', 'inactive', 'all' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'active_theme' => array( 'type' => 'string' ),
						'themes'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'        => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the list-themes ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to list themes.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the list-themes ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array
	 */
	public static function execute( $input = null ) {
		$input  = wp_parse_args( $input ?? array(), array( 'status' => 'all' ) );
		$status = $input['status'];

		$active_stylesheet = get_stylesheet();
		$all_themes        = wp_get_themes();
		$themes            = array();

		foreach ( $all_themes as $stylesheet => $theme ) {
			$is_active = ( $stylesheet === $active_stylesheet );

			// Filter by status.
			if ( 'active' === $status && ! $is_active ) {
				continue;
			}
			if ( 'inactive' === $status && $is_active ) {
				continue;
			}

			// Determine if block theme (has theme.json or is_block_theme()).
			$is_block = method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme();

			$theme_data = array(
				'stylesheet'  => $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => $theme->get( 'Description' ),
				'author'      => $theme->get( 'Author' ),
				'status'      => $is_active ? 'active' : 'inactive',
				'type'        => $is_block ? 'block' : 'classic',
			);

			// Parent theme info for child themes.
			$parent = $theme->parent();
			if ( $parent ) {
				$theme_data['parent'] = array(
					'stylesheet' => $parent->get_stylesheet(),
					'name'       => $parent->get( 'Name' ),
				);
			}

			$themes[] = $theme_data;
		}

		return array(
			'active_theme' => $active_stylesheet,
			'themes'       => $themes,
			'total'        => count( $themes ),
		);
	}
}
