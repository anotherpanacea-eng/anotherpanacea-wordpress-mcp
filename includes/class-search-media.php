<?php
/**
 * Search-media ability: Search the WordPress media library.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches the WordPress media library via the MCP abilities API.
 */
class APMCP_Search_Media {

	/**
	 * Register the search-media ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/search-media',
			array(
				'label'               => __( 'Search Media', 'anotherpanacea-mcp' ),
				'description'         => __( 'Search the media library by keyword, MIME type, or date range. Returns metadata without file contents.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array(
							'type'        => 'string',
							'description' => 'Free-text search across title, caption, alt text, and description.',
						),
						'mime_type' => array(
							'type'        => 'string',
							'description' => 'Filter by MIME type: image, video, audio, application, or a specific type like image/jpeg.',
						),
						'after'     => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Media uploaded after this date.',
						),
						'before'    => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Media uploaded before this date.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Results per page. Default 20, max 100.',
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
							'minimum'     => 1,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'media'       => array(
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
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the search-media ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to browse the media library.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the search-media ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = wp_parse_args(
			$input ?? array(),
			array(
				'search'    => '',
				'mime_type' => '',
				'after'     => '',
				'before'    => '',
				'per_page'  => 20,
				'page'      => 1,
			)
		);

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => min( (int) $input['per_page'], 100 ),
			'paged'          => max( (int) $input['page'], 1 ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}

		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = $input['mime_type'];
		}

		if ( ! empty( $input['after'] ) || ! empty( $input['before'] ) ) {
			$date_query = array();
			if ( ! empty( $input['after'] ) ) {
				$date_query['after'] = $input['after'];
			}
			if ( ! empty( $input['before'] ) ) {
				$date_query['before'] = $input['before'];
			}
			$args['date_query'] = array( $date_query );
		}

		$query = new WP_Query( $args );
		$media = array();

		foreach ( $query->posts as $attachment ) {
			$media[] = array(
				'id'          => $attachment->ID,
				'title'       => $attachment->post_title,
				'filename'    => basename( get_attached_file( $attachment->ID ) ),
				'url'         => wp_get_attachment_url( $attachment->ID ),
				'mime_type'   => $attachment->post_mime_type,
				'alt_text'    => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'caption'     => $attachment->post_excerpt,
				'description' => $attachment->post_content,
				'date'        => mysql2date( 'c', $attachment->post_date_gmt ),
				'filesize'    => filesize( get_attached_file( $attachment->ID ) ) ? filesize( get_attached_file( $attachment->ID ) ) : null,
			);
		}

		return array(
			'media'       => $media,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => (int) $input['page'],
		);
	}
}
