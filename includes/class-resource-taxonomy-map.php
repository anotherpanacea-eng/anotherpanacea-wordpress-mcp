<?php
/**
 * resource-taxonomy-map ability: exposes full category and tag taxonomy tree as an MCP resource.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Resource_Taxonomy_Map {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/resource-taxonomy-map',
			array(
				'label'               => __( 'Taxonomy Map', 'anotherpanacea-mcp' ),
				'description'         => __( 'Full category and tag taxonomy tree: hierarchical categories with slugs, names, descriptions, parent relationships, and post counts; plus all tags with counts.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'categories' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'integer' ),
									'name'        => array( 'type' => 'string' ),
									'slug'        => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'parent'      => array( 'type' => 'integer' ),
									'count'       => array( 'type' => 'integer' ),
								),
							),
						),
						'tags'       => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'integer' ),
									'name'        => array( 'type' => 'string' ),
									'slug'        => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'count'       => array( 'type' => 'integer' ),
								),
							),
						),
					),
					'required'   => array( 'categories', 'tags' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://anotherpanacea-mcp/taxonomy-map',
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

	public static function check_permissions( $input = null ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view the taxonomy map.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$category_terms = get_terms( array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		$tag_terms = get_terms( array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $category_terms ) ) {
			$category_terms = array();
		}
		if ( is_wp_error( $tag_terms ) ) {
			$tag_terms = array();
		}

		$categories = array();
		foreach ( $category_terms as $term ) {
			$categories[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			);
		}

		$tags = array();
		foreach ( $tag_terms as $term ) {
			$tags[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => $term->count,
			);
		}

		return array(
			'categories' => $categories,
			'tags'       => $tags,
		);
	}
}
