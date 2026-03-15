<?php
/**
 * upload-media ability: Upload an image to the media library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Upload_Media {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/upload-media',
			array(
				'label'               => __( 'Upload Media', 'anotherpanacea-mcp' ),
				'description'         => __( 'Upload an image to the WordPress media library from a URL or base64-encoded data.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'file_url'    => array(
							'type'        => 'string',
							'description' => 'URL of the file to fetch and upload.',
						),
						'file_base64' => array(
							'type'        => 'string',
							'description' => 'Base64-encoded file data.',
						),
						'filename'    => array(
							'type'        => 'string',
							'description' => 'Target filename with extension.',
						),
						'alt_text'    => array(
							'type'        => 'string',
							'description' => 'Alt text for the image.',
						),
						'caption'     => array(
							'type'        => 'string',
							'description' => 'Caption for the media.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'url'       => array( 'type' => 'string' ),
						'alt_text'  => array( 'type' => 'string' ),
						'filename'  => array( 'type' => 'string' ),
						'mime_type' => array( 'type' => 'string' ),
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
			return new WP_Error( 'forbidden', 'You do not have permission to upload files.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = $input ?? array();

		// We need WordPress media handling functions.
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$filename = sanitize_file_name( $input['filename'] ?? 'upload' );
		$tmp_file = null;

		if ( ! empty( $input['file_url'] ) ) {
			// Download from URL.
			$tmp_file = download_url( $input['file_url'] );
			if ( is_wp_error( $tmp_file ) ) {
				return $tmp_file;
			}
		} elseif ( ! empty( $input['file_base64'] ) ) {
			// Decode base64 to temp file.
			$decoded = base64_decode( $input['file_base64'], true );
			if ( false === $decoded ) {
				return new WP_Error( 'invalid_base64', 'Invalid base64 data.', array( 'status' => 400 ) );
			}
			$tmp_file = wp_tempnam( $filename );
			file_put_contents( $tmp_file, $decoded );
		} else {
			return new WP_Error( 'missing_file', 'One of file_url or file_base64 is required.', array( 'status' => 400 ) );
		}

		// Sideload the file into the media library.
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			return $attachment_id;
		}

		// Set alt text.
		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}

		// Set caption.
		if ( ! empty( $input['caption'] ) ) {
			wp_update_post( array(
				'ID'           => $attachment_id,
				'post_excerpt' => sanitize_textarea_field( $input['caption'] ),
			) );
		}

		$attachment = get_post( $attachment_id );

		return array(
			'id'        => $attachment_id,
			'url'       => wp_get_attachment_url( $attachment_id ),
			'alt_text'  => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'filename'  => basename( get_attached_file( $attachment_id ) ),
			'mime_type' => $attachment->post_mime_type,
		);
	}
}
