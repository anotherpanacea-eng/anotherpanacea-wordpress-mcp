<?php
/**
 * manage-category ability: Create, update, or delete a category.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Manage_Category {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/manage-category',
			array(
				'label'               => __( 'Manage Category', 'anotherpanacea-mcp' ),
				'description'         => __( 'Create, update, or delete a post category.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'action' ),
					'properties' => array(
						'action'      => array(
							'type'        => 'string',
							'enum'        => array( 'create', 'update', 'delete' ),
							'description' => 'The operation to perform.',
						),
						'term_id'     => array(
							'type'        => 'integer',
							'description' => 'Category term ID. Required for update and delete.',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'Category name. Required for create.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Category slug.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Category description.',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'Parent category term ID. Use 0 for top-level.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'     => array( 'type' => 'integer' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
						'count'       => array( 'type' => 'integer' ),
						'action'      => array( 'type' => 'string' ),
						'deleted'     => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	public static function check_permissions( $input = null ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to manage categories.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input  = $input ?? array();
		$action = $input['action'] ?? '';

		switch ( $action ) {
			case 'create':
				return self::create( $input );
			case 'update':
				return self::update( $input );
			case 'delete':
				return self::delete( $input );
			default:
				return new WP_Error( 'invalid_action', 'Action must be one of: create, update, delete.', array( 'status' => 400 ) );
		}
	}

	private static function create( $input ) {
		if ( empty( $input['name'] ) ) {
			return new WP_Error( 'missing_name', 'Category name is required for create.', array( 'status' => 400 ) );
		}

		$args = array();

		if ( isset( $input['slug'] ) ) {
			$args['slug'] = $input['slug'];
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = $input['description'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$result = wp_insert_term( $input['name'], 'category', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], 'category' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'fetch_failed', 'Category created but could not be retrieved.', array( 'status' => 500 ) );
		}

		return array(
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
			'action'      => 'create',
		);
	}

	private static function update( $input ) {
		if ( empty( $input['term_id'] ) ) {
			return new WP_Error( 'missing_term_id', 'term_id is required for update.', array( 'status' => 400 ) );
		}

		$term_id = (int) $input['term_id'];
		$term    = get_term( $term_id, 'category' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Category not found.', array( 'status' => 404 ) );
		}

		$args = array();

		if ( isset( $input['name'] ) ) {
			$args['name'] = $input['name'];
		}
		if ( isset( $input['slug'] ) ) {
			$args['slug'] = $input['slug'];
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = $input['description'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$result = wp_update_term( $term_id, 'category', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $term_id, 'category' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'fetch_failed', 'Category updated but could not be retrieved.', array( 'status' => 500 ) );
		}

		return array(
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
			'action'      => 'update',
		);
	}

	private static function delete( $input ) {
		if ( empty( $input['term_id'] ) ) {
			return new WP_Error( 'missing_term_id', 'term_id is required for delete.', array( 'status' => 400 ) );
		}

		$term_id = (int) $input['term_id'];
		$term    = get_term( $term_id, 'category' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Category not found.', array( 'status' => 404 ) );
		}

		if ( $term_id === (int) get_option( 'default_category' ) ) {
			return new WP_Error( 'default_category', 'Cannot delete the default category.', array( 'status' => 400 ) );
		}

		$result = wp_delete_term( $term_id, 'category' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error( 'delete_failed', 'Failed to delete category.', array( 'status' => 500 ) );
		}

		return array(
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
			'action'      => 'delete',
			'deleted'     => true,
		);
	}
}
