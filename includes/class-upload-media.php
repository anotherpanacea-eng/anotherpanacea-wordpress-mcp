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
	 * RFC 1918 / link-local / loopback CIDR ranges to block for SSRF protection.
	 *
	 * @var string[]
	 */
	const BLOCKED_IP_RANGES = array(
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'127.0.0.0/8',
		'169.254.0.0/16',  // Link-local.
		'0.0.0.0/8',
		'100.64.0.0/10',   // Carrier-grade NAT.
		'::1/128',         // IPv6 loopback.
		'fc00::/7',        // IPv6 ULA.
		'fe80::/10',       // IPv6 link-local.
	);

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
			// SSRF protection: validate scheme, host, and resolved IP before fetching.
			$url_check = self::validate_remote_url( $input['file_url'] );
			if ( is_wp_error( $url_check ) ) {
				return $url_check;
			}

			// Download from URL.
			$tmp_file = download_url( $input['file_url'] );
			if ( is_wp_error( $tmp_file ) ) {
				return $tmp_file;
			}
		} elseif ( ! empty( $input['file_base64'] ) ) {
			// Decode base64 to temp file.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding user-supplied base64 file data, not obfuscation.
			$decoded = base64_decode( $input['file_base64'], true );
			if ( false === $decoded ) {
				return new WP_Error( 'invalid_base64', 'Invalid base64 data.', array( 'status' => 400 ) );
			}
			$tmp_file = wp_tempnam( $filename );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to temp file before sideload.
			file_put_contents( $tmp_file, $decoded );
		} else {
			return new WP_Error( 'missing_file', 'One of file_url or file_base64 is required.', array( 'status' => 400 ) );
		}

		// File size check: reject files larger than MAX_FILE_SIZE.
		$file_size = filesize( $tmp_file );
		if ( false === $file_size || $file_size > self::MAX_FILE_SIZE ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'file_too_large',
				sprintf( 'File size exceeds the maximum allowed size of %d MB.', self::MAX_FILE_SIZE / 1048576 ),
				array( 'status' => 400 )
			);
		}

		// MIME type check: validate actual MIME type against allowlist.
		$mime_check    = wp_check_filetype_and_ext( $tmp_file, $filename );
		$detected_mime = $mime_check['type'];

		// Fall back to mime_content_type() if wp_check_filetype_and_ext() couldn't detect.
		if ( empty( $detected_mime ) && function_exists( 'mime_content_type' ) ) {
			$detected_mime = mime_content_type( $tmp_file );
		}

		if ( empty( $detected_mime ) || ! in_array( $detected_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			wp_delete_file( $tmp_file );
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
			wp_delete_file( $tmp_file );
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

	/**
	 * Validate a remote URL for SSRF safety before fetching.
	 *
	 * Checks:
	 * 1. Scheme must be http or https.
	 * 2. Host must pass the optional allowlist filter (default: allow all external).
	 * 3. Resolved IP must not be in RFC 1918, loopback, link-local, or metadata ranges.
	 *
	 * @param string $url The URL to validate.
	 * @return true|WP_Error True if safe, WP_Error if blocked.
	 */
	private static function validate_remote_url( $url ) {
		// 1. Scheme check.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'invalid_url_scheme',
				'Only http and https URLs are allowed for file_url.',
				array( 'status' => 400 )
			);
		}

		// 2. Host extraction.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error(
				'invalid_url',
				'Could not parse host from file_url.',
				array( 'status' => 400 )
			);
		}

		// 3. Host allowlist (default: empty = allow all external hosts).
		$allowed_hosts = apply_filters( 'apmcp_upload_allowed_hosts', array() );
		if ( ! empty( $allowed_hosts ) && ! in_array( $host, $allowed_hosts, true ) ) {
			return new WP_Error(
				'disallowed_host',
				sprintf( 'The host "%s" is not in the allowed hosts list.', $host ),
				array( 'status' => 403 )
			);
		}

		// 4. Resolve DNS and check for internal/private IPs.
		$ips = gethostbynamel( $host );
		if ( false === $ips || empty( $ips ) ) {
			return new WP_Error(
				'dns_resolution_failed',
				sprintf( 'Could not resolve host "%s".', $host ),
				array( 'status' => 400 )
			);
		}

		foreach ( $ips as $ip ) {
			if ( self::is_internal_ip( $ip ) ) {
				return new WP_Error(
					'internal_ip_blocked',
					'The resolved IP address is in a private or reserved range. External URLs only.',
					array( 'status' => 403 )
				);
			}
		}

		// 5. Block well-known cloud metadata endpoints by hostname.
		$metadata_hosts = array(
			'metadata.google.internal',
			'metadata.google.com',
		);
		if ( in_array( strtolower( $host ), $metadata_hosts, true ) ) {
			return new WP_Error(
				'metadata_endpoint_blocked',
				'Cloud metadata endpoints are not allowed.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check whether an IP address falls within any blocked internal range.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool True if the IP is internal/private/reserved.
	 */
	private static function is_internal_ip( $ip ) {
		// Use PHP's built-in filter for IPv4 private/reserved ranges.
		if ( false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) ) {
			return true;
		}

		// Additional CIDR checks for ranges not covered by PHP's filter flags.
		foreach ( self::BLOCKED_IP_RANGES as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if an IP address is within a CIDR range.
	 *
	 * @param string $ip   The IP address to check.
	 * @param string $cidr The CIDR range (e.g. '10.0.0.0/8').
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $mask_bits ) = explode( '/', $cidr );

		// IPv6 check.
		if ( false !== strpos( $subnet, ':' ) ) {
			$ip_bin     = inet_pton( $ip );
			$subnet_bin = inet_pton( $subnet );
			if ( false === $ip_bin || false === $subnet_bin ) {
				return false;
			}
			if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
				return false; // Mismatched address families.
			}
			$mask_bits  = (int) $mask_bits;
			$full_bytes = intdiv( $mask_bits, 8 );
			$remaining  = $mask_bits % 8;
			for ( $i = 0; $i < $full_bytes; $i++ ) {
				if ( $ip_bin[ $i ] !== $subnet_bin[ $i ] ) {
					return false;
				}
			}
			if ( $remaining > 0 && $full_bytes < strlen( $ip_bin ) ) {
				$mask_byte = 0xFF << ( 8 - $remaining ) & 0xFF;
				if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask_byte ) !== ( ord( $subnet_bin[ $full_bytes ] ) & $mask_byte ) ) {
					return false;
				}
			}
			return true;
		}

		// IPv4 check.
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}
		$mask = -1 << ( 32 - (int) $mask_bits );
		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}
}
