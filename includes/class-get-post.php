<?php
/**
 * get-post ability: Retrieve full post content for reading/editing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Get_Post {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/get-post',
			array(
				'label'               => __( 'Get Post', 'anotherpanacea-mcp' ),
				'description'         => __( 'Retrieve a full post by ID or slug. Returns both Markdown and raw block content.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
						),
						'slug' => array(
							'type'        => 'string',
							'description' => 'Post slug.',
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
			return new WP_Error( 'forbidden', 'You do not have permission to view posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = $input ?? array();

		$post = null;

		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
		} elseif ( ! empty( $input['slug'] ) ) {
			$posts = get_posts( array(
				'name'        => $input['slug'],
				'post_type'   => 'post',
				'post_status' => array( 'draft', 'publish', 'pending', 'private' ),
				'numberposts' => 1,
			) );
			$post = ! empty( $posts ) ? $posts[0] : null;
		} else {
			return new WP_Error( 'missing_param', 'Either id or slug is required.', array( 'status' => 400 ) );
		}

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		// Per-post capability check.
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to read this post.', array( 'status' => 403 ) );
		}

		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'slugs' ) );

		$featured_id  = (int) get_post_thumbnail_id( $post->ID );
		$featured_url = $featured_id ? wp_get_attachment_url( $featured_id ) : null;

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'date'               => $post->post_date_gmt ? mysql2date( 'c', $post->post_date_gmt ) : null,
			'modified'           => $post->post_modified_gmt ? mysql2date( 'c', $post->post_modified_gmt ) : null,
			'content_markdown'   => APMCP_Markdown_Converter::blocks_to_markdown( $post->post_content ),
			'content_raw'        => $post->post_content,
			'categories'         => $categories,
			'tags'               => $tags,
			'excerpt'            => $post->post_excerpt,
			'featured_media_id'  => $featured_id ?: null,
			'featured_media_url' => $featured_url,
			'link'               => get_permalink( $post->ID ),
		);
	}
}
