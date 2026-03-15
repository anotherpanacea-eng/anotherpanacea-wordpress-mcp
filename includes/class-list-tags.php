<?php
/**
 * List-tags ability: List all post tags.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists all post tags via the MCP abilities API.
 */
class APMCP_List_Tags {

	/**
	 * Register the list-tags ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/list-tags',
			array(
				'label'               => __( 'List Tags', 'anotherpanacea-mcp' ),
				'description'         => __( 'List all post tags, optionally filtered by search string.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Optional search string to filter tags by name.',
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array( 'type' => 'integer' ),
							'name'  => array( 'type' => 'string' ),
							'slug'  => array( 'type' => 'string' ),
							'count' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the list-tags ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to list tags.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the list-tags ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = $input ?? array();

		$args = array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['search'] = $input['search'];
		}

		$terms  = get_terms( $args );
		$result = array();

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $term ) {
			$result[] = array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			);
		}

		return $result;
	}
}
