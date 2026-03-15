<?php
/**
 * Transition-post-status ability: Change a post's status.
 *
 * Separated from update-post because status transitions have side effects.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes a post's status via the MCP abilities API.
 */
class APMCP_Transition_Status {

	/**
	 * Register the transition-post-status ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/transition-post-status',
			array(
				'label'               => __( 'Transition Post Status', 'anotherpanacea-mcp' ),
				'description'         => __( 'Change a post\'s status (draft, pending, publish, private). Supports scheduling via future date.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id', 'status' ),
					'properties' => array(
						'id'                    => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
						),
						'status'                => array(
							'type'        => 'string',
							'description' => 'Target status: draft, pending, publish, or private.',
							'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
						),
						'date'                  => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. For scheduling, set status to publish with a future date.',
						),
						'expected_modified_gmt' => array(
							'type'        => 'string',
							'description' => 'ISO 8601 timestamp of last known modification. Rejects the transition if the post was modified since.',
						),
						'dry_run'               => array(
							'type'        => 'boolean',
							'description' => 'If true, validate the transition without applying it. Returns resulting status.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'title'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
						'date'   => array( 'type' => 'string' ),
						'link'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the transition-post-status ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to change post status.', array( 'status' => 403 ) );
		}
		// Publishing and private both require publish_posts capability.
		$status = $input['status'] ?? '';
		if ( in_array( $status, array( 'publish', 'private' ), true ) ) {
			// We can't know the post type from just the ID in the permission
			// callback, so check both capabilities. The per-post check in
			// execute() is the real gate.
			if ( ! current_user_can( 'publish_posts' ) && ! current_user_can( 'publish_pages' ) ) {
				return new WP_Error( 'forbidden', 'You do not have permission to publish or make content private.', array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Execute the transition-post-status ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = $input ?? array();
		$id    = (int) ( $input['id'] ?? 0 );

		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this post.', array( 'status' => 403 ) );
		}

		// Concurrency guard.
		if ( ! empty( $input['expected_modified_gmt'] ) ) {
			$actual = mysql2date( 'c', $post->post_modified_gmt );
			if ( $actual !== $input['expected_modified_gmt'] ) {
				return new WP_Error(
					'conflict',
					'Post was modified since you last read it.',
					array(
						'status'              => 409,
						'actual_modified_gmt' => $actual,
					)
				);
			}
		}

		$post_data = array(
			'ID'          => $id,
			'post_status' => $input['status'],
		);

		// Handle scheduling: publish + future date = 'future' status in WordPress.
		if ( ! empty( $input['date'] ) ) {
			$timestamp                  = strtotime( $input['date'] );
			$post_data['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
			$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );

			// WordPress automatically sets status to 'future' if date is in the future and status is 'publish'.
			if ( 'publish' === $input['status'] && $timestamp > time() ) {
				$post_data['post_status'] = 'future';
			}
		}

		// Dry-run: validate and return preview.
		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run'        => true,
				'id'             => $id,
				'current_status' => $post->post_status,
				'target_status'  => $post_data['post_status'],
			);
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = get_post( $id );

		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'date'   => $post->post_date_gmt ? mysql2date( 'c', $post->post_date_gmt ) : null,
			'link'   => get_permalink( $post->ID ),
		);
	}
}
