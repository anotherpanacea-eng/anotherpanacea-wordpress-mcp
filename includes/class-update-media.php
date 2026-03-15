<?php
/**
 * update-media ability: Update metadata for an existing media item.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Update_Media {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/update-media',
			array(
				'label'               => __( 'Update Media', 'anotherpanacea-mcp' ),
				'description'         => __( 'Update metadata for an existing media item: alt text, caption, title, or description.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Media attachment ID.',
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'New title for the media item.',
						),
						'alt_text'    => array(
							'type'        => 'string',
							'description' => 'New alt text for the image.',
						),
						'caption'     => array(
							'type'        => 'string',
							'description' => 'New caption.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'New description.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'url'         => array( 'type' => 'string' ),
						'alt_text'    => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'mime_type'   => array( 'type' => 'string' ),
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
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit media.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = $input ?? array();
		$id    = (int) ( $input['id'] ?? 0 );

		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', 'Media item not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this media item.', array( 'status' => 403 ) );
		}

		$post_data = array( 'ID' => $id );

		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $input['title'] );
		}

		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $input['caption'] );
		}

		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = sanitize_textarea_field( $input['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}

		$attachment = get_post( $id );

		return array(
			'id'          => $id,
			'title'       => $attachment->post_title,
			'url'         => wp_get_attachment_url( $id ),
			'alt_text'    => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'mime_type'   => $attachment->post_mime_type,
		);
	}
}
