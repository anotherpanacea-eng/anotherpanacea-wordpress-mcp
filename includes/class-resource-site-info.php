<?php
/**
 * Resource-site-info ability: Exposes basic site information as an MCP resource.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes basic site information as an MCP resource via the abilities API.
 */
class APMCP_Resource_Site_Info {

	/**
	 * Register the resource-site-info ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/resource-site-info',
			array(
				'label'               => __( 'Site Info', 'anotherpanacea-mcp' ),
				'description'         => __( 'Basic site information: title, tagline, URL, timezone, date format, language, active theme name, and WordPress version.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'title'        => array( 'type' => 'string' ),
						'tagline'      => array( 'type' => 'string' ),
						'url'          => array( 'type' => 'string' ),
						'timezone'     => array( 'type' => 'string' ),
						'date_format'  => array( 'type' => 'string' ),
						'language'     => array( 'type' => 'string' ),
						'active_theme' => array( 'type' => 'string' ),
						'wp_version'   => array( 'type' => 'string' ),
					),
					'required'   => array( 'title', 'url', 'timezone', 'wp_version' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://anotherpanacea-mcp/site-info',
					),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for the resource-site-info ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view site info.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the resource-site-info ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wp_version;

		$theme = wp_get_theme();

		return array(
			'title'        => get_bloginfo( 'name' ),
			'tagline'      => get_bloginfo( 'description' ),
			'url'          => get_bloginfo( 'url' ),
			'timezone'     => get_option( 'timezone_string' ) ? get_option( 'timezone_string' ) : 'UTC' . get_option( 'gmt_offset' ),
			'date_format'  => get_option( 'date_format' ),
			'language'     => get_bloginfo( 'language' ),
			'active_theme' => $theme->get( 'Name' ),
			'wp_version'   => $wp_version,
		);
	}
}
