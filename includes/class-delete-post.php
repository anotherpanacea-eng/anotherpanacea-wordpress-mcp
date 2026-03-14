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
		if ( ! current_user_can( 'delete_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to delete posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = $input ?? array();
		$id    = (int) ( $input['id'] ?? 0 );

		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to delete this post.', array( 'status' => 403 ) );
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
