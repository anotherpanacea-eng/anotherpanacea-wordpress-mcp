<?php
/**
 * List-revisions ability: List revisions for a post with optional diff.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists revisions for a post with optional diff via the MCP abilities API.
 */
class APMCP_List_Revisions {

	/**
	 * Register the list-revisions ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/list-revisions',
			array(
				'label'               => __( 'List Revisions', 'anotherpanacea-mcp' ),
				'description'         => __( 'List revisions for a post. Optionally include a content diff against the current version.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => 'Post ID to list revisions for.',
						),
						'include_diff' => array(
							'type'        => 'boolean',
							'description' => 'If true, include a text diff of each revision against the current content. Default false.',
						),
						'per_page'     => array(
							'type'        => 'integer',
							'description' => 'Number of revisions to return. Default 10, max 50.',
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'revisions' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
						'total'     => array( 'type' => 'integer' ),
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
	 * Check permissions for the list-revisions ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view revisions.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the list-revisions ability.
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

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view revisions for this post.', array( 'status' => 403 ) );
		}

		$per_page     = min( (int) ( $input['per_page'] ?? 10 ), 50 );
		$include_diff = ! empty( $input['include_diff'] );

		$all_revisions = wp_get_post_revisions( $post_id, array( 'order' => 'DESC' ) );
		$total         = count( $all_revisions );
		$revisions_raw = array_slice( $all_revisions, 0, $per_page );

		$revisions = array();
		foreach ( $revisions_raw as $rev ) {
			$entry = array(
				'id'       => $rev->ID,
				'author'   => get_the_author_meta( 'display_name', $rev->post_author ),
				'date'     => mysql2date( 'c', $rev->post_modified_gmt ),
				'title'    => $rev->post_title,
				'excerpt'  => wp_trim_words( $rev->post_content, 30 ),
			);

			if ( $include_diff ) {
				$entry['diff'] = self::simple_diff( $post->post_content, $rev->post_content );
			}

			$revisions[] = $entry;
		}

		return array(
			'post_id'   => $post_id,
			'revisions' => $revisions,
			'total'     => $total,
		);
	}

	/**
	 * Simple line-based diff between current content and revision content.
	 *
	 * @param string $current  Current post content.
	 * @param string $revision Revision post content.
	 * @return string Diff output showing added and removed lines.
	 */
	private static function simple_diff( $current, $revision ) {
		$current_lines  = explode( "\n", $current );
		$revision_lines = explode( "\n", $revision );

		$added   = array_diff( $revision_lines, $current_lines );
		$removed = array_diff( $current_lines, $revision_lines );

		if ( empty( $added ) && empty( $removed ) ) {
			return '(no differences)';
		}

		$diff = '';
		foreach ( $removed as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$diff .= '- ' . $line . "\n";
			}
		}
		foreach ( $added as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$diff .= '+ ' . $line . "\n";
			}
		}
		return trim( $diff );
	}
}
