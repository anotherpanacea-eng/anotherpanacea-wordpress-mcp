<?php
/**
 * update-post ability: Partial update of an existing post.
 * Does NOT change post status (use transition-post-status for that).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Update_Post {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/update-post',
			array(
				'label'               => __( 'Update Post', 'anotherpanacea-mcp' ),
				'description'         => __( 'Partially update an existing post. Only provided fields are changed. Does not change status.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'             => array(
							'type'        => 'integer',
							'description' => 'Post ID to update.',
						),
						'title'          => array(
							'type'        => 'string',
							'description' => 'New post title.',
						),
						'content'        => array(
							'type'        => 'string',
							'description' => 'New content in Markdown (default), HTML, or block markup.',
						),
						'format'         => array(
							'type'        => 'string',
							'description' => 'Content format: markdown (default), html, or blocks.',
							'enum'        => array( 'markdown', 'html', 'blocks' ),
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Category slugs. Replaces existing categories.',
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Tag slugs. Replaces existing tags.',
						),
						'excerpt'        => array(
							'type'        => 'string',
							'description' => 'Post excerpt.',
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => 'Post slug.',
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'Media ID for featured image.',
						),
						'expected_modified_gmt' => array(
							'type'        => 'string',
							'description' => 'ISO 8601 timestamp of last known modification. If provided and the post has been modified since, the update is rejected with a conflict error.',
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => 'If true, validate the update without applying changes. Returns what would change.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
						'slug'     => array( 'type' => 'string' ),
						'status'   => array( 'type' => 'string' ),
						'modified' => array( 'type' => 'string' ),
						'link'     => array( 'type' => 'string' ),
						'dry_run'  => array( 'type' => 'boolean' ),
						'conflict_detected' => array( 'type' => 'boolean' ),
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
			return new WP_Error( 'forbidden', 'You do not have permission to update posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = $input ?? array();
		$id    = (int) ( $input['id'] ?? 0 );

		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this post.', array( 'status' => 403 ) );
		}

		// Concurrency guard.
		if ( ! empty( $input['expected_modified_gmt'] ) ) {
			$actual = mysql2date( 'c', $post->post_modified_gmt );
			if ( $actual !== $input['expected_modified_gmt'] ) {
				return new WP_Error(
					'conflict',
					'Post was modified since you last read it.',
					array( 'status' => 409, 'actual_modified_gmt' => $actual )
				);
			}
		}

		$post_data = array( 'ID' => $id );

		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $input['title'] );
		}

		if ( isset( $input['content'] ) ) {
			$format  = $input['format'] ?? 'markdown';
			$content = $input['content'];
			if ( 'markdown' === $format ) {
				$content = APMCP_Markdown_Converter::markdown_to_blocks( $content );
			}
			$post_data['post_content'] = $content;
		}

		if ( isset( $input['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
		}

		if ( isset( $input['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $input['slug'] );
		}

		// Dry-run: validate and return preview without mutating.
		if ( ! empty( $input['dry_run'] ) ) {
			$preview = array(
				'dry_run'        => true,
				'id'             => $id,
				'fields_changed' => array_keys( array_diff_key( $post_data, array( 'ID' => true ) ) ),
			);
			if ( isset( $input['slug'] ) ) {
				$preview['resolved_slug'] = wp_unique_post_slug(
					sanitize_title( $input['slug'] ), $id, $post->post_status, $post->post_type, $post->post_parent
				);
			}
			if ( isset( $input['categories'] ) ) {
				$preview['resolved_categories'] = self::resolve_categories( $input['categories'] );
			}
			return $preview;
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Handle categories.
		if ( isset( $input['categories'] ) ) {
			$cat_ids = self::resolve_categories( $input['categories'] );
			wp_set_post_categories( $id, $cat_ids );
		}

		// Handle tags.
		if ( isset( $input['tags'] ) ) {
			wp_set_post_tags( $id, $input['tags'] );
		}

		// Featured image.
		if ( isset( $input['featured_media'] ) ) {
			if ( $input['featured_media'] ) {
				set_post_thumbnail( $id, (int) $input['featured_media'] );
			} else {
				delete_post_thumbnail( $id );
			}
		}

		$post = get_post( $id );

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'modified' => mysql2date( 'c', $post->post_modified_gmt ),
			'link'     => get_permalink( $post->ID ),
		);
	}

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
