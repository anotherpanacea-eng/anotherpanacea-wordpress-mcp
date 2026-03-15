<?php
/**
 * Search-posts ability: Search and filter posts.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches and filters posts via the MCP abilities API.
 */
class APMCP_Search_Posts {

	/**
	 * Register the search-posts ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/search-posts',
			array(
				'label'               => __( 'Search Posts', 'anotherpanacea-mcp' ),
				'description'         => __( 'Search and filter posts by status, text, category, tag, date range, and more. Returns summaries without full content.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'    => array(
							'type'        => 'string',
							'description' => 'Post status: draft, publish, pending, private, trash, or any.',
							'enum'        => array( 'draft', 'publish', 'pending', 'private', 'trash', 'future', 'any' ),
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Free-text search across title and content.',
						),
						'category'  => array(
							'type'        => 'string',
							'description' => 'Category slug to filter by.',
						),
						'tag'       => array(
							'type'        => 'string',
							'description' => 'Tag slug to filter by.',
						),
						'after'     => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Posts modified after this date.',
						),
						'before'    => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Posts modified before this date.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Results per page. Default 20, max 100.',
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number for pagination. Default 1.',
							'minimum'     => 1,
						),
						'orderby'   => array(
							'type'        => 'string',
							'description' => 'Sort field: date, modified, or title.',
							'enum'        => array( 'date', 'modified', 'title' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type to search: post or page. Default post.',
							'enum'        => array( 'post', 'page' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
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
	 * Check permissions for the search-posts ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) {
		$post_type = $input['post_type'] ?? 'post';
		$cap       = 'page' === $post_type ? 'edit_pages' : 'edit_posts';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to search this content type.', array( 'status' => 403 ) );
		}
		// Private posts require read_private_posts capability.
		$status      = $input['status'] ?? 'draft';
		$private_cap = 'page' === $post_type ? 'read_private_pages' : 'read_private_posts';
		if ( 'private' === $status || 'any' === $status ) {
			if ( ! current_user_can( $private_cap ) ) {
				if ( 'private' === $status ) {
					return new WP_Error( 'forbidden', 'You do not have permission to view private posts.', array( 'status' => 403 ) );
				}
				// For 'any', we'll filter out private posts in execute() instead of blocking entirely.
			}
		}
		return true;
	}

	/**
	 * Execute the search-posts ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = wp_parse_args(
			$input ?? array(),
			array(
				'status'    => 'draft',
				'search'    => '',
				'category'  => '',
				'tag'       => '',
				'after'     => '',
				'before'    => '',
				'per_page'  => 20,
				'page'      => 1,
				'orderby'   => 'modified',
				'post_type' => 'post',
			)
		);

		// Build the status array, respecting capabilities.
		if ( 'any' === $input['status'] ) {
			$statuses = array( 'draft', 'publish', 'pending' );
			if ( current_user_can( 'page' === $input['post_type'] ? 'read_private_pages' : 'read_private_posts' ) ) {
				$statuses[] = 'private';
			}
		} else {
			$statuses = $input['status'];
		}

		$args = array(
			'post_type'      => $input['post_type'],
			'post_status'    => $statuses,
			'posts_per_page' => min( (int) $input['per_page'], 100 ),
			'paged'          => max( (int) $input['page'], 1 ),
			'orderby'        => $input['orderby'],
			'order'          => 'title' === $input['orderby'] ? 'ASC' : 'DESC',
		);

		// Scope the query at the database level so pagination is accurate.
		// Users who can edit_others_posts (Editors+) see all posts.
		// Users who can't (Authors/Contributors) only see their own.
		// non-published posts, matching WP core REST API behavior.
		if ( ! current_user_can( 'page' === $input['post_type'] ? 'edit_others_pages' : 'edit_others_posts' ) ) {
			$requested          = is_array( $statuses ) ? $statuses : array( $statuses );
			$needs_author_scope = array_diff( $requested, array( 'publish' ) );
			if ( ! empty( $needs_author_scope ) ) {
				$args['author'] = get_current_user_id();
			}
		}

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}

		if ( ! empty( $input['category'] ) ) {
			$args['category_name'] = $input['category'];
		}

		if ( ! empty( $input['tag'] ) ) {
			$args['tag'] = $input['tag'];
		}

		if ( ! empty( $input['after'] ) || ! empty( $input['before'] ) ) {
			$date_query = array();
			if ( ! empty( $input['after'] ) ) {
				$date_query['after'] = $input['after'];
			}
			if ( ! empty( $input['before'] ) ) {
				$date_query['before'] = $input['before'];
			}
			$date_query['column'] = 'post_modified';
			$args['date_query']   = array( $date_query );
		}

		$query = new WP_Query( $args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );
			$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'slugs' ) );

			$posts[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'slug'       => $post->post_name,
				'status'     => $post->post_status,
				'date'       => $post->post_date_gmt ? mysql2date( 'c', $post->post_date_gmt ) : null,
				'modified'   => $post->post_modified_gmt ? mysql2date( 'c', $post->post_modified_gmt ) : null,
				'categories' => $categories,
				'tags'       => $tags,
				'excerpt'    => wp_trim_words( $post->post_excerpt ? $post->post_excerpt : $post->post_content, 30 ),
			);
		}

		return array(
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => (int) $input['page'],
		);
	}
}
