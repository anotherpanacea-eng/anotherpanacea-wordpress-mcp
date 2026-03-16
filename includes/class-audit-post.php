<?php
/**
 * Audit-post ability: read-only scan of a post for legacy issues.
 *
 * Returns a structured report of issues that can be fixed mechanically
 * (by repair-post) or that require human/LLM judgment.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only post audit: scans for legacy issues and returns a structured report.
 */
class APMCP_Audit_Post {

	/**
	 * Deprecated HTML tags to flag.
	 */
	const DEPRECATED_TAGS = array( 'font', 'center', 'marquee', 'blink', 'strike', 'big', 'small', 'tt', 'u' );

	/**
	 * Register the audit-post ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/audit-post',
			array(
				'label'               => __( 'Audit Post', 'anotherpanacea-mcp' ),
				'description'         => __( 'Scan a post for legacy issues: dead links, missing block markup, deprecated HTML, broken images, HTTP links, missing metadata, and more. Read-only — does not modify the post.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Post ID to audit.',
						),
						'check_links' => array(
							'type'        => 'boolean',
							'description' => 'Whether to check external links for dead URLs. Default: true. Disable for faster audits.',
							'default'     => true,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer' ),
						'title'            => array( 'type' => 'string' ),
						'slug'             => array( 'type' => 'string' ),
						'status'           => array( 'type' => 'string' ),
						'post_type'        => array( 'type' => 'string' ),
						'has_block_markup' => array( 'type' => 'boolean' ),
						'issues'           => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'type'         => array( 'type' => 'string' ),
									'severity'     => array( 'type' => 'string' ),
									'description'  => array( 'type' => 'string' ),
									'auto_fixable' => array( 'type' => 'boolean' ),
									'details'      => array( 'description' => 'Additional context.' ),
								),
							),
						),
						'summary'          => array(
							'type'       => 'object',
							'properties' => array(
								'total_issues'   => array( 'type' => 'integer' ),
								'auto_fixable'   => array( 'type' => 'integer' ),
								'needs_judgment' => array( 'type' => 'integer' ),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
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
	 * Check permissions for the audit-post ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to audit posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the audit-post ability.
	 *
	 * @param array|null $input Ability input with post ID and options.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = $input ?? array();

		if ( empty( $input['id'] ) ) {
			return new WP_Error( 'missing_param', 'id is required.', array( 'status' => 400 ) );
		}

		$post = get_post( (int) $input['id'] );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		// Per-post permission gate: prevent auditing another user's draft/private post.
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to read this post.', array( 'status' => 403 ) );
		}

		$check_links = $input['check_links'] ?? true;
		$content     = $post->post_content;
		$issues      = array();

		// 1. Check for block markup.
		$has_blocks = has_blocks( $content );
		if ( ! $has_blocks && ! empty( trim( $content ) ) ) {
			$issues[] = array(
				'type'         => 'missing_block_markup',
				'severity'     => 'medium',
				'description'  => 'Post content has no Gutenberg block markup (classic editor content).',
				'auto_fixable' => true,
				'details'      => null,
			);
		}

		// 2. Check for HTTP (non-HTTPS) links.
		$http_links = self::find_http_links( $content );
		if ( ! empty( $http_links ) ) {
			$issues[] = array(
				'type'         => 'http_links',
				'severity'     => 'medium',
				'description'  => sprintf( 'Found %d HTTP (non-HTTPS) link(s).', count( $http_links ) ),
				'auto_fixable' => true,
				'details'      => $http_links,
			);
		}

		// 3. Check for deprecated HTML tags.
		$deprecated = self::find_deprecated_html( $content );
		if ( ! empty( $deprecated ) ) {
			$issues[] = array(
				'type'         => 'deprecated_html',
				'severity'     => 'low',
				'description'  => sprintf( 'Found deprecated HTML tag(s): %s', implode( ', ', array_keys( $deprecated ) ) ),
				'auto_fixable' => true,
				'details'      => $deprecated,
			);
		}

		// 4. Check for missing excerpt.
		if ( empty( $post->post_excerpt ) && 'post' === $post->post_type ) {
			$issues[] = array(
				'type'         => 'missing_excerpt',
				'severity'     => 'low',
				'description'  => 'Post has no manual excerpt.',
				'auto_fixable' => true,
				'details'      => null,
			);
		}

		// 5. Check for uncategorized-only.
		if ( 'post' === $post->post_type ) {
			$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );
			if ( empty( $cats ) || ( 1 === count( $cats ) && 'uncategorized' === $cats[0] ) ) {
				$issues[] = array(
					'type'         => 'missing_categories',
					'severity'     => 'medium',
					'description'  => 'Post is only in the "Uncategorized" category.',
					'auto_fixable' => false,
					'details'      => $cats,
				);
			}
		}

		// 6. Check for missing tags.
		if ( 'post' === $post->post_type ) {
			$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'slugs' ) );
			if ( empty( $tags ) ) {
				$issues[] = array(
					'type'         => 'missing_tags',
					'severity'     => 'low',
					'description'  => 'Post has no tags.',
					'auto_fixable' => false,
					'details'      => null,
				);
			}
		}

		// 7. Check for numeric or bad slug.
		if ( preg_match( '/^\d+$/', $post->post_name ) ) {
			$issues[] = array(
				'type'         => 'numeric_slug',
				'severity'     => 'high',
				'description'  => sprintf( 'Post has a numeric slug: "%s". This is bad for SEO and readability.', $post->post_name ),
				'auto_fixable' => false,
				'details'      => array( 'current_slug' => $post->post_name ),
			);
		}

		// 8. Check for HTML entities in title.
		if ( html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5 ) !== $post->post_title ) {
			$issues[] = array(
				'type'         => 'html_entities_in_title',
				'severity'     => 'low',
				'description'  => 'Title contains HTML entities that could be decoded.',
				'auto_fixable' => true,
				'details'      => array(
					'raw_title'     => $post->post_title,
					'decoded_title' => html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5 ),
				),
			);
		}

		// 9. Check for broken image references in content.
		$images        = self::find_image_urls( $content );
		$broken_images = array();
		foreach ( $images as $img_url ) {
			// Only check internal images — external ones are covered by link checking.
			$site_url = get_site_url();
			if ( 0 === strpos( $img_url, $site_url ) ) {
				$path = str_replace( $site_url, ABSPATH, $img_url );
				$path = str_replace( '/wp-content/', 'wp-content/', $path );
				// Just check if the attachment exists in the DB.
				$attachment_id = attachment_url_to_postid( $img_url );
				if ( ! $attachment_id ) {
					$broken_images[] = $img_url;
				}
			}
		}
		if ( ! empty( $broken_images ) ) {
			$issues[] = array(
				'type'         => 'broken_images',
				'severity'     => 'high',
				'description'  => sprintf( 'Found %d image URL(s) not in the media library.', count( $broken_images ) ),
				'auto_fixable' => false,
				'details'      => $broken_images,
			);
		}

		// 10. Check for dead external links (optional, slow).
		// Only available to users who can edit others' posts (admin/editor)
		// because it causes the server to make outbound HTTP requests.
		if ( $check_links && current_user_can( 'edit_others_posts' ) ) {
			$all_links  = self::find_all_links( $content );
			$dead_links = array();
			$site_url   = get_site_url();

			foreach ( $all_links as $url ) {
				// Skip internal links, mailto, tel, anchors.
				if ( 0 === strpos( $url, $site_url ) ) {
					continue;
				}
				if ( preg_match( '/^(mailto:|tel:|#|javascript:|data:)/i', $url ) ) {
					continue;
				}

				// Only check http/https URLs.
				$url_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
				if ( ! in_array( strtolower( (string) $url_scheme ), array( 'http', 'https' ), true ) ) {
					continue;
				}

				$response = wp_remote_head(
					$url,
					array(
						'timeout'     => 5,
						'redirection' => 3,
						'sslverify'   => true,
					)
				);

				if ( is_wp_error( $response ) ) {
					$dead_links[] = array(
						'url'    => $url,
						'error'  => $response->get_error_message(),
						'status' => null,
					);
				} else {
					$status = wp_remote_retrieve_response_code( $response );
					if ( $status >= 400 ) {
						$dead_links[] = array(
							'url'    => $url,
							'error'  => "HTTP {$status}",
							'status' => $status,
						);
					}
				}
			}

			if ( ! empty( $dead_links ) ) {
				$issues[] = array(
					'type'         => 'dead_links',
					'severity'     => 'high',
					'description'  => sprintf( 'Found %d dead or unreachable link(s).', count( $dead_links ) ),
					'auto_fixable' => false,
					'details'      => $dead_links,
				);
			}
		}

		// 11. Check for excessive whitespace / formatting artifacts.
		if ( preg_match( '/\n{4,}/', $content ) || preg_match( '/&nbsp;{3,}/', $content ) ) {
			$issues[] = array(
				'type'         => 'whitespace_artifacts',
				'severity'     => 'low',
				'description'  => 'Content has excessive whitespace or &nbsp; artifacts.',
				'auto_fixable' => true,
				'details'      => null,
			);
		}

		// 12. Check for missing featured image.
		if ( ! has_post_thumbnail( $post->ID ) ) {
			$issues[] = array(
				'type'         => 'missing_featured_image',
				'severity'     => 'low',
				'description'  => 'Post has no featured image.',
				'auto_fixable' => false,
				'details'      => null,
			);
		}

		// Build summary.
		$auto_fixable   = count(
			array_filter(
				$issues,
				function ( $i ) {
					return $i['auto_fixable'];
				}
			)
		);
		$needs_judgment = count( $issues ) - $auto_fixable;

		return array(
			'post_id'          => $post->ID,
			'title'            => $post->post_title,
			'slug'             => $post->post_name,
			'status'           => $post->post_status,
			'post_type'        => $post->post_type,
			'date'             => $post->post_date_gmt ? mysql2date( 'c', $post->post_date_gmt ) : null,
			'has_block_markup' => $has_blocks,
			'issues'           => $issues,
			'summary'          => array(
				'total_issues'   => count( $issues ),
				'auto_fixable'   => $auto_fixable,
				'needs_judgment' => $needs_judgment,
			),
		);
	}

	// Helpers.

	/**
	 * Find all HTTP (non-HTTPS) URLs in content.
	 *
	 * @param string $content Post content to scan.
	 * @return array List of HTTP URLs found.
	 */
	private static function find_http_links( $content ) {
		$links = array();
		if ( preg_match_all( '/https?:\/\/[^\s"\'<>]+/i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				if ( 0 === strpos( $url, 'http://' ) ) {
					$links[] = $url;
				}
			}
		}
		return array_unique( $links );
	}

	/**
	 * Find deprecated HTML tags and their count.
	 *
	 * @param string $content Post content to scan.
	 * @return array Associative array of tag name => count.
	 */
	private static function find_deprecated_html( $content ) {
		$found = array();
		foreach ( self::DEPRECATED_TAGS as $tag ) {
			if ( preg_match_all( '/<' . $tag . '[\s>]/i', $content, $matches ) ) {
				$found[ $tag ] = count( $matches[0] );
			}
		}
		return $found;
	}

	/**
	 * Find all image URLs in content (from img tags and markdown images).
	 *
	 * @param string $content Post content to scan.
	 * @return array List of image URLs found.
	 */
	private static function find_image_urls( $content ) {
		$urls = array();
		// HTML img tags.
		if ( preg_match_all( '/src="([^"]+\.(jpg|jpeg|png|gif|webp|svg)(\?[^"]*)?)"[^>]*/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		// Markdown images.
		if ( preg_match_all( '/!\[[^\]]*\]\(([^)]+)\)/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		return array_unique( $urls );
	}

	/**
	 * Find all link URLs in content.
	 *
	 * @param string $content Post content to scan.
	 * @return array List of all link URLs found.
	 */
	private static function find_all_links( $content ) {
		$urls = array();
		// HTML href attributes.
		if ( preg_match_all( '/href="([^"]+)"/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		// Markdown links.
		if ( preg_match_all( '/\[[^\]]*\]\(([^)]+)\)/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		// Image sources (also link targets).
		$urls = array_merge( $urls, self::find_image_urls( $content ) );
		return array_unique( $urls );
	}
}
