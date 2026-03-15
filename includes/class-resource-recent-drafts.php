<?php
/**
 * resource-recent-drafts ability: exposes a queue of recent draft posts as an MCP resource.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Resource_Recent_Drafts {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/resource-recent-drafts',
			array(
				'label'               => __( 'Recent Drafts Queue', 'anotherpanacea-mcp' ),
				'description'         => __( 'Queue of the 20 most recently modified draft posts: title, author, modified date, categories, and excerpt for each.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'drafts' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'integer' ),
									'title'      => array( 'type' => 'string' ),
									'slug'       => array( 'type' => 'string' ),
									'author'     => array( 'type' => 'string' ),
									'modified'   => array( 'type' => 'string', 'format' => 'date-time' ),
									'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
									'excerpt'    => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
					'required' => array( 'drafts', 'total' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://anotherpanacea-mcp/recent-drafts',
					),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	public static function check_permissions( $input = null ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view drafts.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'draft',
			'posts_per_page' => 20,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$drafts = array();
		foreach ( $posts as $post ) {
			// Per-post capability check.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$author_data = get_userdata( $post->post_author );
			$author_name = $author_data ? $author_data->display_name : '';

			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );

			$drafts[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'slug'       => $post->post_name,
				'author'     => $author_name,
				'modified'   => $post->post_modified_gmt ? mysql2date( 'c', $post->post_modified_gmt ) : null,
				'categories' => $categories ?: array(),
				'excerpt'    => $post->post_excerpt,
			);
		}

		return array(
			'drafts' => $drafts,
			'total'  => count( $drafts ),
		);
	}
}
