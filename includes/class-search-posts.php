<?php
/**
 * search-posts ability: Search and filter posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Search_Posts {

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
						'status'   => array(
							'type'        => 'string',
							'description' => 'Post status: draft, publish, pending, private, trash, or any.',
							'enum'        => array( 'draft', 'publish', 'pending', 'private', 'trash', 'any' ),
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Free-text search across title and content.',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'Category slug to filter by.',
						),
						'tag'      => array(
							'type'        => 'string',
							'description' => 'Tag slug to filter by.',
						),
						'after'    => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Posts modified after this date.',
						),
						'before'   => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Posts modified before this date.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Results per page. Default 20, max 100.',
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number for pagination. Default 1.',
							'minimum'     => 1,
						),
						'orderby'  => array(
							'type'        => 'string',
							'description' => 'Sort field: date, modified, or title.',
							'enum'        => array( 'date', 'modified', 'title' ),
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
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to search posts.', array( 'status' => 403 ) );
		}
		// Private posts require read_private_posts capability.
		$status = $input['status'] ?? 'draft';
		if ( 'private' === $status || 'any' === $status ) {
			if ( ! current_user_can( 'read_private_posts' ) ) {
				if ( 'private' === $status ) {
					return new WP_Error( 'forbidden', 'You do not have permission to view private posts.', array( 'status' => 403 ) );
				}
				// For 'any', we'll filter out private posts in execute() instead of blocking entirely.
			}
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = wp_parse_args( $input ?? array(), array(
			'status'   => 'draft',
			'search'   => '',
			'category' => '',
			'tag'      => '',
			'after'    => '',
			'before'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'modified',
		) );

		// Build the status array, respecting capabilities.
		if ( 'any' === $input['status'] ) {
			$statuses = array( 'draft', 'publish', 'pending' );
			if ( current_user_can( 'read_private_posts' ) ) {
				$statuses[] = 'private';
			}
		} else {
			$statuses = $input['status'];
		}

		$args = array(
			'post_type'      => 'post',
			'post_status'    => $statuses,
			'posts_per_page' => min( (int) $input['per_page'], 100 ),
			'paged'          => max( (int) $input['page'], 1 ),
			'orderby'        => $input['orderby'],
			'order'          => 'title' === $input['orderby'] ? 'ASC' : 'DESC',
		);

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
			// Per-post capability check: skip posts the user cannot read.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

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
				'excerpt'    => wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 ),
			);
		}

		$filtered_total = (int) $query->found_posts;
		$per_page       = min( (int) $input['per_page'], 100 );

		// If we filtered out posts the user can't read, adjust totals
		// to reflect what they actually see.
		$page_diff = count( $query->posts ) - count( $posts );
		if ( $page_diff > 0 ) {
			$filtered_total = max( 0, $filtered_total - $page_diff );
		}

		return array(
			'posts'       => $posts,
			'total'       => $filtered_total,
			'total_pages' => $per_page > 0 ? (int) ceil( $filtered_total / $per_page ) : 1,
			'page'        => (int) $input['page'],
		);
	}
}
