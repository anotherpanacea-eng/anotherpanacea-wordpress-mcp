<?php
/**
 * Create-post ability: Create a new post (typically as draft).
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new post via the MCP abilities API.
 */
class APMCP_Create_Post {

	/**
	 * Register the create-post ability.
	 */
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
						'post_type'      => array(
							'type'        => 'string',
							'description' => 'Content type to create: post or page. Default post.',
							'enum'        => array( 'post', 'page' ),
						),
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
						'dry_run'        => array(
							'type'        => 'boolean',
							'description' => 'If true, validate the post data without creating it. Returns resolved slug, categories, and any issues.',
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
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the create-post ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) {
		$post_type   = $input['post_type'] ?? 'post';
		$edit_cap    = 'page' === $post_type ? 'edit_pages' : 'edit_posts';
		$publish_cap = 'page' === $post_type ? 'publish_pages' : 'publish_posts';
		if ( ! current_user_can( $edit_cap ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to create this content type.', array( 'status' => 403 ) );
		}
		$status = $input['status'] ?? 'draft';
		if ( 'publish' === $status && ! current_user_can( $publish_cap ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to publish this content type.', array( 'status' => 403 ) );
		}
		if ( 'private' === $status && ! current_user_can( $publish_cap ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to create private content.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the create-post ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
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
			'post_type'    => $input['post_type'] ?? 'post',
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

		// Dry-run: validate and return preview without creating.
		if ( ! empty( $input['dry_run'] ) ) {
			$preview = array(
				'dry_run'       => true,
				'resolved_slug' => wp_unique_post_slug(
					$post_data['post_name'] ?? sanitize_title( $post_data['post_title'] ),
					0,
					$post_data['post_status'],
					$post_data['post_type'] ?? 'post',
					0
				),
				'status'        => $post_data['post_status'],
			);
			if ( ! empty( $input['categories'] ) ) {
				$preview['resolved_categories'] = self::resolve_categories( $input['categories'] );
			}
			return $preview;
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
	 *
	 * @param array $slugs Category slugs.
	 * @return int[] Category term IDs.
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
