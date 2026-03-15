<?php
/**
 * create-post ability: Create a new post (typically as draft).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Create_Post {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/create-post',
			array(
				'label'               => __( 'Create Post', 'anotherpanacea-mcp' ),
				'description'         => __( 'Create a new post. Accepts Markdown content which is converted to block markup server-side.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title', 'content' ),
					'properties' => array(
						'title'          => array(
							'type'        => 'string',
							'description' => 'Post title.',
						),
						'content'        => array(
							'type'        => 'string',
							'description' => 'Post content in Markdown (default), HTML, or block markup.',
						),
						'format'         => array(
							'type'        => 'string',
							'description' => 'Content format: markdown (default), html, or blocks.',
							'enum'        => array( 'markdown', 'html', 'blocks' ),
						),
						'status'         => array(
							'type'        => 'string',
							'description' => 'Post status. Default: draft.',
							'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Array of category slugs. Created if they don\'t exist.',
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Array of tag slugs. Created if they don\'t exist.',
						),
						'excerpt'        => array(
							'type'        => 'string',
							'description' => 'Post excerpt.',
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => 'Post slug.',
						),
						'date'           => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Future date with publish status = scheduled.',
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'Media ID for featured image.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'title'  => array( 'type' => 'string' ),
						'slug'   => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
						'link'   => array( 'type' => 'string' ),
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
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to create posts.', array( 'status' => 403 ) );
		}
		$status = $input['status'] ?? 'draft';
		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to publish posts.', array( 'status' => 403 ) );
		}
		if ( 'private' === $status && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to create private posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input  = $input ?? array();
		$format = $input['format'] ?? 'markdown';

		// Convert content based on format.
		$content = $input['content'] ?? '';
		if ( 'markdown' === $format ) {
			$content = APMCP_Markdown_Converter::markdown_to_blocks( $content );
		}
		// 'html' and 'blocks' pass through as-is.

		$post_data = array(
			'post_title'   => sanitize_text_field( $input['title'] ),
			'post_content' => $content,
			'post_status'  => $input['status'] ?? 'draft',
			'post_type'    => 'post',
		);

		if ( ! empty( $input['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
		}

		if ( ! empty( $input['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $input['slug'] );
		}

		if ( ! empty( $input['date'] ) ) {
			$post_data['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $input['date'] ) ) );
			$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $input['date'] ) );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Handle categories.
		if ( ! empty( $input['categories'] ) ) {
			$cat_ids = self::resolve_categories( $input['categories'] );
			wp_set_post_categories( $post_id, $cat_ids );
		}

		// Handle tags.
		if ( ! empty( $input['tags'] ) ) {
			wp_set_post_tags( $post_id, $input['tags'] );
		}

		// Featured image.
		if ( ! empty( $input['featured_media'] ) ) {
			set_post_thumbnail( $post_id, (int) $input['featured_media'] );
		}

		$post = get_post( $post_id );

		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'slug'   => $post->post_name,
			'status' => $post->post_status,
			'link'   => get_permalink( $post->ID ),
		);
	}

	/**
	 * Resolve category slugs to IDs, creating categories that don't exist.
	 */
	private static function resolve_categories( $slugs ) {
		$ids = array();
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( $term ) {
				$ids[] = $term->term_id;
			} elseif ( current_user_can( 'manage_categories' ) ) {
				$result = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), 'category', array( 'slug' => $slug ) );
				if ( ! is_wp_error( $result ) ) {
					$ids[] = $result['term_id'];
				}
			}
			// If category doesn't exist and user can't create, silently skip.
		}
		return $ids;
	}
}
