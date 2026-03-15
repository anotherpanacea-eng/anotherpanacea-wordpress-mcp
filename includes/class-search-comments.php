<?php
/**
 * Search-comments ability: Search and filter comments.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches and filters comments via the MCP abilities API.
 */
class APMCP_Search_Comments {

	/**
	 * Register the search-comments ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/search-comments',
			array(
				'label'               => __( 'Search Comments', 'anotherpanacea-mcp' ),
				'description'         => __( 'Search and filter comments by keyword, post, status, author email, and more.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'       => array(
							'type'        => 'string',
							'description' => 'Keyword search in comment content.',
						),
						'post_id'      => array(
							'type'        => 'integer',
							'description' => 'Filter by post ID.',
						),
						'status'       => array(
							'type'        => 'string',
							'description' => 'Comment status filter. Default approved.',
							'enum'        => array( 'approved', 'hold', 'spam', 'trash', 'all' ),
						),
						'author_email' => array(
							'type'        => 'string',
							'description' => 'Filter by author email.',
						),
						'per_page'     => array(
							'type'        => 'integer',
							'description' => 'Results per page. Default 20, max 100.',
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'         => array(
							'type'        => 'integer',
							'description' => 'Page number for pagination. Default 1.',
							'minimum'     => 1,
						),
						'order'        => array(
							'type'        => 'string',
							'description' => 'Sort order: asc or desc. Default desc.',
							'enum'        => array( 'asc', 'desc' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'comments'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'total'       => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
				'meta'                => array(
					'mcp' => array( 'public' => true ),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for the search-comments ability.
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
	 * Execute the search-comments ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = wp_parse_args( $input ?? array(), array(
			'search'       => '',
			'post_id'      => 0,
			'status'       => 'approved',
			'author_email' => '',
			'per_page'     => 20,
			'page'         => 1,
			'order'        => 'desc',
		) );

		$per_page = min( (int) $input['per_page'], 100 );
		$page     = max( (int) $input['page'], 1 );
		$offset   = ( $page - 1 ) * $per_page;

		// Map status values to WP comment status strings.
		$status_map = array(
			'approved' => 'approve',
			'hold'     => 'hold',
			'spam'     => 'spam',
			'trash'    => 'trash',
		);

		$args = array(
			'number'  => $per_page,
			'offset'  => $offset,
			'order'   => strtoupper( $input['order'] ),
			'orderby' => 'comment_date_gmt',
		);

		if ( 'all' !== $input['status'] ) {
			$args['status'] = $status_map[ $input['status'] ] ?? 'approve';
		}

		if ( ! empty( $input['search'] ) ) {
			$args['search'] = $input['search'];
		}

		if ( ! empty( $input['post_id'] ) ) {
			$args['post_id'] = (int) $input['post_id'];
		}

		if ( ! empty( $input['author_email'] ) ) {
			$args['author_email'] = $input['author_email'];
		}

		// Get total count using same args minus pagination.
		$count_args          = $args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );
		$total = (int) get_comments( $count_args );

		$raw_comments = get_comments( $args );
		$comments     = array();

		foreach ( $raw_comments as $comment ) {
			$comments[] = array(
				'id'           => (int) $comment->comment_ID,
				'post_id'      => (int) $comment->comment_post_ID,
				'post_title'   => get_the_title( $comment->comment_post_ID ),
				'author_name'  => $comment->comment_author,
				'author_email' => $comment->comment_author_email,
				'author_url'   => $comment->comment_author_url,
				'date'         => $comment->comment_date_gmt ? mysql2date( 'c', $comment->comment_date_gmt ) : null,
				'content'      => strip_tags( $comment->comment_content ),
				'status'       => $comment->comment_approved,
				'parent'       => (int) $comment->comment_parent,
				'type'         => $comment->comment_type,
			);
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		return array(
			'comments'    => $comments,
			'total'       => $total,
			'page'        => $page,
			'total_pages' => $total_pages,
		);
	}
}
