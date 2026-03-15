<?php
/**
 * Create-comment ability: Create a new comment on a post.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new comment on a post via the MCP abilities API.
 */
class APMCP_Create_Comment {

	/**
	 * Register the create-comment ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/create-comment',
			array(
				'label'               => __( 'Create Comment', 'anotherpanacea-mcp' ),
				'description'         => __( 'Create a new comment on a post.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'content' ),
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => 'The post to comment on.',
						),
						'content'      => array(
							'type'        => 'string',
							'description' => 'Comment text.',
						),
						'author_name'  => array(
							'type'        => 'string',
							'description' => 'Author display name. Defaults to current user display name.',
						),
						'author_email' => array(
							'type'        => 'string',
							'description' => 'Author email address. Defaults to current user email.',
						),
						'parent'       => array(
							'type'        => 'integer',
							'description' => 'Parent comment ID for threading.',
						),
						'status'       => array(
							'type'        => 'string',
							'description' => 'Initial comment status. Default approve.',
							'enum'        => array( 'approve', 'hold' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'post_id'     => array( 'type' => 'integer' ),
						'content'     => array( 'type' => 'string' ),
						'status'      => array( 'type' => 'string' ),
						'date'        => array( 'type' => 'string' ),
						'author_name' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the create-comment ability.
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
	 * Execute the create-comment ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = $input ?? array();

		$post_id = (int) ( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		$parent = (int) ( $input['parent'] ?? 0 );
		if ( $parent > 0 ) {
			$parent_comment = get_comment( $parent );
			if ( ! $parent_comment ) {
				return new WP_Error( 'not_found', 'Parent comment not found.', array( 'status' => 404 ) );
			}
		}

		$current_user = wp_get_current_user();
		$author_name  = ! empty( $input['author_name'] ) ? sanitize_text_field( $input['author_name'] ) : $current_user->display_name;
		$author_email = ! empty( $input['author_email'] ) ? sanitize_email( $input['author_email'] ) : $current_user->user_email;
		$content      = wp_kses_post( $input['content'] ?? '' );
		$status       = isset( $input['status'] ) && 'hold' === $input['status'] ? 0 : 1;

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => $content,
			'comment_author'       => $author_name,
			'comment_author_email' => $author_email,
			'comment_approved'     => $status,
			'comment_parent'       => $parent,
			'user_id'              => get_current_user_id(),
		);

		$comment_id = wp_insert_comment( $comment_data );

		if ( ! $comment_id ) {
			return new WP_Error( 'insert_failed', 'Failed to create comment.', array( 'status' => 500 ) );
		}

		$comment = get_comment( $comment_id );

		return array(
			'id'          => (int) $comment->comment_ID,
			'post_id'     => (int) $comment->comment_post_ID,
			'content'     => $comment->comment_content,
			'status'      => $comment->comment_approved,
			'date'        => $comment->comment_date_gmt ? mysql2date( 'c', $comment->comment_date_gmt ) : null,
			'author_name' => $comment->comment_author,
		);
	}
}
