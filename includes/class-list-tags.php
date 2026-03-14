<?php
/**
 * list-tags ability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_List_Tags {

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
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}

	public static function check_permissions( $input = null ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to list tags.', array( 'status' => 403 ) );
		}
		return true;
	}

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
