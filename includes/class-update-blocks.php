<?php
/**
 * Update-blocks ability: Update, insert, delete, or reorder individual blocks within a post.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates individual Gutenberg blocks within a post via the MCP abilities API.
 */
class APMCP_Update_Blocks {

	/**
	 * Register the update-blocks ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/update-blocks',
			array(
				'label'               => __( 'Update Blocks', 'anotherpanacea-mcp' ),
				'description'         => __( 'Surgically update, insert, delete, or reorder individual Gutenberg blocks within a post without rewriting the entire content.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'operations' ),
					'properties' => array(
						'post_id'               => array(
							'type'        => 'integer',
							'description' => 'Post ID to modify.',
						),
						'expected_modified_gmt' => array(
							'type'        => 'string',
							'description' => 'ISO 8601 timestamp of last known modification. If provided and the post has been modified since, the update is rejected with a conflict error.',
						),
						'operations'            => array(
							'type'        => 'array',
							'description' => 'Ordered list of block operations to apply sequentially.',
							'items'       => array(
								'type'       => 'object',
								'required'   => array( 'action' ),
								'properties' => array(
									'action'       => array(
										'type'        => 'string',
										'enum'        => array( 'update', 'insert', 'delete', 'move' ),
										'description' => 'Operation type.',
									),
									'index'        => array(
										'type'        => 'integer',
										'description' => '0-based block index. Required for update, delete, and move.',
									),
									'block_name'   => array(
										'type'        => 'string',
										'description' => 'Block name, e.g. "core/paragraph". Used for insert/update.',
									),
									'content'      => array(
										'type'        => 'string',
										'description' => 'Block content as block markup, markdown, or HTML depending on format.',
									),
									'format'       => array(
										'type'        => 'string',
										'enum'        => array( 'blocks', 'markdown', 'html' ),
										'description' => 'Content format. Default: blocks.',
									),
									'target_index' => array(
										'type'        => 'integer',
										'description' => 'Destination index for move operations.',
									),
									'position'     => array(
										'type'        => 'string',
										'enum'        => array( 'before', 'after' ),
										'description' => 'For insert: where to insert relative to index. Default: after.',
									),
								),
							),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'            => array( 'type' => 'integer' ),
						'block_count'        => array( 'type' => 'integer' ),
						'operations_applied' => array( 'type' => 'integer' ),
						'blocks'             => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'index'      => array( 'type' => 'integer' ),
									'block_name' => array( 'type' => 'string' ),
									'attrs'      => array( 'type' => 'object' ),
									'inner_html' => array( 'type' => 'string' ),
									'inner_text' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the update-blocks ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this post.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the update-blocks ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input      = $input ?? array();
		$post_id    = (int) ( $input['post_id'] ?? 0 );
		$operations = $input['operations'] ?? array();

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		// Concurrency guard.
		if ( ! empty( $input['expected_modified_gmt'] ) ) {
			$actual = mysql2date( 'c', $post->post_modified_gmt );
			if ( $actual !== $input['expected_modified_gmt'] ) {
				return new WP_Error(
					'conflict',
					'Post was modified since you last read it.',
					array(
						'status'              => 409,
						'actual_modified_gmt' => $actual,
					)
				);
			}
		}

		// Parse existing content into a clean working array (filter empty filler blocks).
		$blocks = array_values(
			array_filter(
				parse_blocks( $post->post_content ),
				function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);

		$operations_applied = 0;

		foreach ( $operations as $op ) {
			$action = $op['action'] ?? '';

			switch ( $action ) {
				case 'update':
					$index = (int) ( $op['index'] ?? -1 );
					if ( $index < 0 || $index >= count( $blocks ) ) {
						return new WP_Error(
							'invalid_index',
							sprintf( 'Block index %d is out of range.', $index ),
							array( 'status' => 400 )
						);
					}
					$new_blocks = self::parse_content( $op );
					if ( is_wp_error( $new_blocks ) ) {
						return $new_blocks;
					}
					// Replace the single block with parsed content (may be one or more blocks).
					array_splice( $blocks, $index, 1, $new_blocks );
					++$operations_applied;
					break;

				case 'insert':
					$index      = (int) ( $op['index'] ?? -1 );
					$position   = $op['position'] ?? 'after';
					$new_blocks = self::parse_content( $op );
					if ( is_wp_error( $new_blocks ) ) {
						return $new_blocks;
					}
					// Determine insertion offset.
					if ( 'before' === $position ) {
						$insert_at = max( 0, $index );
					} else {
						// 'after': insert after index, or append if index is -1 or out of range.
						$insert_at = ( $index < 0 || $index >= count( $blocks ) )
							? count( $blocks )
							: $index + 1;
					}
					array_splice( $blocks, $insert_at, 0, $new_blocks );
					++$operations_applied;
					break;

				case 'delete':
					$index = (int) ( $op['index'] ?? -1 );
					if ( $index < 0 || $index >= count( $blocks ) ) {
						return new WP_Error(
							'invalid_index',
							sprintf( 'Block index %d is out of range.', $index ),
							array( 'status' => 400 )
						);
					}
					array_splice( $blocks, $index, 1 );
					++$operations_applied;
					break;

				case 'move':
					$index        = (int) ( $op['index'] ?? -1 );
					$target_index = (int) ( $op['target_index'] ?? -1 );
					if ( $index < 0 || $index >= count( $blocks ) ) {
						return new WP_Error(
							'invalid_index',
							sprintf( 'Block index %d is out of range.', $index ),
							array( 'status' => 400 )
						);
					}
					$moving = array_splice( $blocks, $index, 1 );
					// After removal, clamp target_index to valid range.
					$target_index = max( 0, min( $target_index, count( $blocks ) ) );
					array_splice( $blocks, $target_index, 0, $moving );
					++$operations_applied;
					break;

				default:
					return new WP_Error(
						'invalid_action',
						sprintf( 'Unknown operation action "%s".', $action ),
						array( 'status' => 400 )
					);
			}
		}

		// Serialize blocks back to block markup and save.
		$serialized = serialize_blocks( $blocks );
		$result     = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $serialized,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Re-fetch to get freshly parsed clean output.
		$updated_post = get_post( $post_id );
		$final_blocks = array_values(
			array_filter(
				parse_blocks( $updated_post->post_content ),
				function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);

		$output_blocks = array();
		foreach ( $final_blocks as $index => $block ) {
			$inner_html      = trim( $block['innerHTML'] );
			$output_blocks[] = array(
				'index'      => $index,
				'block_name' => $block['blockName'],
				'attrs'      => $block['attrs'] ? $block['attrs'] : new stdClass(),
				'inner_html' => $inner_html,
				'inner_text' => wp_strip_all_tags( $inner_html ),
			);
		}

		return array(
			'post_id'            => $post_id,
			'block_count'        => count( $output_blocks ),
			'operations_applied' => $operations_applied,
			'blocks'             => $output_blocks,
		);
	}

	/**
	 * Parse operation content into an array of block structures.
	 *
	 * @param array $op Operation definition with optional content, format, block_name keys.
	 * @return array|WP_Error Array of parsed block structures, or WP_Error on failure.
	 */
	private static function parse_content( $op ) {
		$content    = $op['content'] ?? '';
		$format     = $op['format'] ?? 'blocks';
		$block_name = $op['block_name'] ?? '';

		if ( '' === $content ) {
			// Empty content: produce a single empty paragraph block.
			$markup = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
			$parsed = parse_blocks( $markup );
			return array_values(
				array_filter(
					$parsed,
					function ( $b ) {
						return ! empty( $b['blockName'] );
					}
				)
			);
		}

		if ( 'markdown' === $format ) {
			$markup = APMCP_Markdown_Converter::markdown_to_blocks( $content );
		} elseif ( 'html' === $format ) {
			// Wrap raw HTML in a paragraph block.
			$markup = '<!-- wp:paragraph --><p>' . $content . '</p><!-- /wp:paragraph -->';
		} else {
			// 'blocks' format: use content directly as block markup.
			$markup = $content;
		}

		$parsed = parse_blocks( $markup );
		$parsed = array_values(
			array_filter(
				$parsed,
				function ( $b ) {
					return ! empty( $b['blockName'] );
				}
			)
		);

		return $parsed;
	}
}
