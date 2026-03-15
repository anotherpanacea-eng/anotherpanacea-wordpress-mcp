<?php
/**
 * Upload-media ability: Upload an image to the media library.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uploads media to the WordPress library via the MCP abilities API.
 */
class APMCP_Upload_Media {

	/**
	 * MIME types allowed for upload.
	 *
	 * @var string[]
	 */
	const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/svg+xml',
		'application/pdf',
	);

	/**
	 * Maximum allowed file size in bytes (10 MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 10485760; // 10 * 1024 * 1024

	/**
	 * Register the upload-media ability.
	 */
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
						'strip_exif'  => array(
							'type'        => 'boolean',
							'description' => 'If true, strip EXIF metadata from JPEG images. Default true.',
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

	/**
	 * Check permissions for the upload-media ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to upload files.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the upload-media ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
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
			// Hostname allowlist check (optional — site owners may restrict via filter).
			$allowed_hosts = apply_filters( 'apmcp_upload_allowed_hosts', array() );
			if ( ! empty( $allowed_hosts ) ) {
				$parsed_host = wp_parse_url( $input['file_url'], PHP_URL_HOST );
				if ( ! in_array( $parsed_host, $allowed_hosts, true ) ) {
					return new WP_Error(
						'disallowed_host',
						sprintf( 'The host "%s" is not in the allowed hosts list.', $parsed_host ),
						array( 'status' => 403 )
					);
				}
			}

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

		// File size check: reject files larger than MAX_FILE_SIZE.
		$file_size = filesize( $tmp_file );
		if ( false === $file_size || $file_size > self::MAX_FILE_SIZE ) {
			@unlink( $tmp_file );
			return new WP_Error(
				'file_too_large',
				sprintf( 'File size exceeds the maximum allowed size of %d MB.', self::MAX_FILE_SIZE / 1048576 ),
				array( 'status' => 400 )
			);
		}

		// MIME type check: validate actual MIME type against allowlist.
		$mime_check = wp_check_filetype_and_ext( $tmp_file, $filename );
		$detected_mime = $mime_check['type'];

		// Fall back to mime_content_type() if wp_check_filetype_and_ext() couldn't detect.
		if ( empty( $detected_mime ) && function_exists( 'mime_content_type' ) ) {
			$detected_mime = mime_content_type( $tmp_file );
		}

		if ( empty( $detected_mime ) || ! in_array( $detected_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			@unlink( $tmp_file );
			return new WP_Error(
				'disallowed_mime_type',
				sprintf(
					'MIME type "%s" is not allowed. Allowed types: %s.',
					$detected_mime ? $detected_mime : 'unknown',
					implode( ', ', self::ALLOWED_MIME_TYPES )
				),
				array( 'status' => 400 )
			);
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

		// EXIF stripping: re-encode JPEG through WP image editor to remove EXIF metadata.
		// Default: strip unless explicitly set to false.
		$strip_exif = isset( $input['strip_exif'] ) ? (bool) $input['strip_exif'] : true;
		if ( $strip_exif && 'image/jpeg' === $detected_mime ) {
			$attached_file = get_attached_file( $attachment_id );
			if ( $attached_file ) {
				$editor = wp_get_image_editor( $attached_file );
				if ( ! is_wp_error( $editor ) ) {
					// save() re-encodes the image through the editor, stripping EXIF.
					$editor->save( $attached_file );
				}
			}
		}

		// Set alt text.
		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}

		// Set caption.
		if ( ! empty( $input['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_textarea_field( $input['caption'] ),
				)
			);
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
