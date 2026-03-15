<?php
/**
 * delete-comment ability: Delete or trash a comment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Delete_Comment {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/delete-comment',
			array(
				'label'               => __( 'Delete Comment', 'anotherpanacea-mcp' ),
				'description'         => __( 'Delete a comment. Moves to trash by default; permanently deletes when force is true.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'comment_id' ),
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => 'Comment ID to delete.',
						),
						'force'      => array(
							'type'        => 'boolean',
							'description' => 'If true, permanently delete. If false (default), move to trash.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'deleted'         => array( 'type' => 'boolean' ),
						'previous_status' => array( 'type' => 'string' ),
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
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to moderate comments.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$comment_id = (int) ( $input['comment_id'] ?? 0 );

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return new WP_Error( 'not_found', 'Comment not found.', array( 'status' => 404 ) );
		}

		$previous_status = $comment->comment_approved;
		$force           = ! empty( $input['force'] );

		$result = wp_delete_comment( $comment_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', 'Failed to delete comment.', array( 'status' => 500 ) );
		}

		return array(
			'id'              => $comment_id,
			'deleted'         => true,
			'previous_status' => $previous_status,
		);
	}
}
