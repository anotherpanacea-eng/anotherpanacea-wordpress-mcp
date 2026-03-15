<?php
/**
 * Get-blocks ability: Parse a post's content into constituent Gutenberg blocks.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses a post's content into Gutenberg blocks via the MCP abilities API.
 */
class APMCP_Get_Blocks {

	/**
	 * Register the get-blocks ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/get-blocks',
			array(
				'label'               => __( 'Get Blocks', 'anotherpanacea-mcp' ),
				'description'         => __( 'Parse a post\'s content into its constituent Gutenberg blocks, returning structured block data for individual block inspection and editing.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post ID to parse blocks from.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'block_count' => array( 'type' => 'integer' ),
						'blocks'      => array(
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
				'show_in_rest'        => true,
				'meta'                => array(
					'mcp'         => array( 'public' => true ),
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
	 * Check permissions for the get-blocks ability.
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
	 * Execute the get-blocks ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input   = $input ?? array();
		$post_id = (int) ( $input['post_id'] ?? 0 );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		$raw_blocks = parse_blocks( $post->post_content );

		// Filter out empty/null blocks (whitespace-only filler with no blockName).
		$raw_blocks = array_values(
			array_filter(
				$raw_blocks,
				function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);

		$blocks = array();
		foreach ( $raw_blocks as $index => $block ) {
			$inner_html = trim( $block['innerHTML'] );
			$blocks[]   = array(
				'index'      => $index,
				'block_name' => $block['blockName'],
				'attrs'      => $block['attrs'] ? $block['attrs'] : new stdClass(),
				'inner_html' => $inner_html,
				'inner_text' => wp_strip_all_tags( $inner_html ),
			);
		}

		return array(
			'post_id'     => $post_id,
			'title'       => $post->post_title,
			'block_count' => count( $blocks ),
			'blocks'      => $blocks,
		);
	}
}
