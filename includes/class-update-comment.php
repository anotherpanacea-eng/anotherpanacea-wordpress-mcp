<?php
/**
 * Update-comment ability: Update comment content or status.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates comment content or status via the MCP abilities API.
 */
class APMCP_Update_Comment {

	/**
	 * Register the update-comment ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/update-comment',
			array(
				'label'               => __( 'Update Comment', 'anotherpanacea-mcp' ),
				'description'         => __( 'Update comment content or status (approve, hold, spam, trash).', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'comment_id' ),
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => 'Comment ID to update.',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'New comment text.',
						),
						'status'     => array(
							'type'        => 'string',
							'description' => 'New comment status.',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'post_id'         => array( 'type' => 'integer' ),
						'content'         => array( 'type' => 'string' ),
						'status'          => array( 'type' => 'string' ),
						'date'            => array( 'type' => 'string' ),
						'author_name'     => array( 'type' => 'string' ),
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

	/**
	 * Check permissions for the update-comment ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to moderate comments.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the update-comment ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$comment_id = (int) ( $input['comment_id'] ?? 0 );

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return new WP_Error( 'not_found', 'Comment not found.', array( 'status' => 404 ) );
		}

		$previous_status = $comment->comment_approved;

		if ( ! empty( $input['content'] ) ) {
			$result = wp_update_comment(
				array(
					'comment_ID'      => $comment_id,
					'comment_content' => wp_kses_post( $input['content'] ),
				)
			);

			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'update_failed', 'Failed to update comment content.', array( 'status' => 500 ) );
			}
		}

		if ( ! empty( $input['status'] ) ) {
			$status_result = wp_set_comment_status( $comment_id, $input['status'] );

			if ( ! $status_result ) {
				return new WP_Error( 'status_failed', 'Failed to update comment status.', array( 'status' => 500 ) );
			}
		}

		// Re-fetch after updates.
		$comment = get_comment( $comment_id );

		return array(
			'id'              => (int) $comment->comment_ID,
			'post_id'         => (int) $comment->comment_post_ID,
			'content'         => $comment->comment_content,
			'status'          => $comment->comment_approved,
			'date'            => $comment->comment_date_gmt ? mysql2date( 'c', $comment->comment_date_gmt ) : null,
			'author_name'     => $comment->comment_author,
			'previous_status' => $previous_status,
		);
	}
}
