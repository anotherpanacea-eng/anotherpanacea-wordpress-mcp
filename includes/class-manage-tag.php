<?php
/**
 * Manage-tag ability: Create, update, or delete a tag.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, updates, or deletes tags via the MCP abilities API.
 */
class APMCP_Manage_Tag {

	/**
	 * Register the manage-tag ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/manage-tag',
			array(
				'label'               => __( 'Manage Tag', 'anotherpanacea-mcp' ),
				'description'         => __( 'Create, update, or delete a post tag.', 'anotherpanacea-mcp' ),
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
							'description' => 'Tag term ID. Required for update and delete.',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'Tag name. Required for create.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Tag slug.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Tag description.',
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

	/**
	 * Check permissions for the manage-tag ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'manage_categories' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to manage tags.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the manage-tag ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
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

	/**
	 * Create a new tag.
	 *
	 * @param array $input Ability input parameters.
	 * @return array|WP_Error
	 */
	private static function create( $input ) {
		if ( empty( $input['name'] ) ) {
			return new WP_Error( 'missing_name', 'Tag name is required for create.', array( 'status' => 400 ) );
		}

		$args = array();

		if ( isset( $input['slug'] ) ) {
			$args['slug'] = $input['slug'];
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = $input['description'];
		}

		$result = wp_insert_term( $input['name'], 'post_tag', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], 'post_tag' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'fetch_failed', 'Tag created but could not be retrieved.', array( 'status' => 500 ) );
		}

		return array(
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => $term->count,
			'action'      => 'create',
		);
	}

	/**
	 * Update an existing tag.
	 *
	 * @param array $input Ability input parameters.
	 * @return array|WP_Error
	 */
	private static function update( $input ) {
		if ( empty( $input['term_id'] ) ) {
			return new WP_Error( 'missing_term_id', 'term_id is required for update.', array( 'status' => 400 ) );
		}

		$term_id = (int) $input['term_id'];
		$term    = get_term( $term_id, 'post_tag' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Tag not found.', array( 'status' => 404 ) );
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

		$result = wp_update_term( $term_id, 'post_tag', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $term_id, 'post_tag' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'fetch_failed', 'Tag updated but could not be retrieved.', array( 'status' => 500 ) );
		}

		return array(
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => $term->count,
			'action'      => 'update',
		);
	}

	/**
	 * Delete a tag.
	 *
	 * @param array $input Ability input parameters.
	 * @return array|WP_Error
	 */
	private static function delete( $input ) {
		if ( empty( $input['term_id'] ) ) {
			return new WP_Error( 'missing_term_id', 'term_id is required for delete.', array( 'status' => 400 ) );
		}

		$term_id = (int) $input['term_id'];
		$term    = get_term( $term_id, 'post_tag' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Tag not found.', array( 'status' => 404 ) );
		}

		$result = wp_delete_term( $term_id, 'post_tag' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error( 'delete_failed', 'Failed to delete tag.', array( 'status' => 500 ) );
		}

		return array(
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => $term->count,
			'action'      => 'delete',
			'deleted'     => true,
		);
	}
}
