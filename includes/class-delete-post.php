<?php
/**
 * delete-post ability: Move a post to trash.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Delete_Post {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/delete-post',
			array(
				'label'               => __( 'Delete Post', 'anotherpanacea-mcp' ),
				'description'         => __( 'Move a post to trash. Does not permanently delete.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Post ID to trash.',
						),
						'expected_modified_gmt' => array(
							'type'        => 'string',
							'description' => 'ISO 8601 timestamp of last known modification. Rejects the delete if the post was modified since.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'title'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
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
		if ( ! current_user_can( 'delete_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to delete posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = $input ?? array();
		$id    = (int) ( $input['id'] ?? 0 );

		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to delete this post.', array( 'status' => 403 ) );
		}

		// Concurrency guard.
		if ( ! empty( $input['expected_modified_gmt'] ) ) {
			$actual = mysql2date( 'c', $post->post_modified_gmt );
			if ( $actual !== $input['expected_modified_gmt'] ) {
				return new WP_Error(
					'conflict',
					'Post was modified since you last read it.',
					array( 'status' => 409, 'actual_modified_gmt' => $actual )
				);
			}
		}

		$result = wp_trash_post( $id );

		if ( ! $result ) {
			return new WP_Error( 'trash_failed', 'Failed to move post to trash.', array( 'status' => 500 ) );
		}

		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'status' => 'trash',
		);
	}
}
