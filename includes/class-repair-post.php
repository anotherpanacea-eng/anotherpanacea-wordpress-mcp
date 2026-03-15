<?php
/**
 * Repair-post ability: automated fixes for legacy post issues.
 *
 * Takes a post ID and a list of repair operations to perform.
 * Only applies mechanical, deterministic fixes — no LLM judgment needed.
 * Supports dry_run mode to preview changes without writing.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automated post repair: applies mechanical fixes to legacy content.
 */
class APMCP_Repair_Post {

	/**
	 * Available repair operations and their descriptions.
	 */
	const OPERATIONS = array(
		'http-to-https'         => 'Convert HTTP links to HTTPS where possible.',
		'strip-deprecated-html' => 'Remove deprecated HTML tags (font, center, etc.), keeping inner content.',
		'convert-to-blocks'     => 'Convert classic HTML content to Gutenberg block markup.',
		'generate-excerpt'      => 'Auto-generate an excerpt from the first paragraph of content.',
		'whitespace-normalize'  => 'Clean up excessive whitespace, &nbsp; artifacts, and blank lines.',
		'decode-title-entities' => 'Decode HTML entities in the post title.',
		'strip-empty-tags'      => 'Remove empty HTML tags (<p></p>, <div></div>, etc.).',
	);

	/**
	 * Register the repair-post ability.
	 */
	public static function register() {
		// Build the enum list from operation keys.
		$op_keys         = array_keys( self::OPERATIONS );
		$op_descriptions = array();
		foreach ( self::OPERATIONS as $key => $desc ) {
			$op_descriptions[] = "`{$key}`: {$desc}";
		}

		wp_register_ability(
			'anotherpanacea-mcp/repair-post',
			array(
				'label'               => __( 'Repair Post', 'anotherpanacea-mcp' ),
				'description'         => __( 'Apply automated repairs to a post. Operations: ', 'anotherpanacea-mcp' ) . implode( ' ', $op_descriptions ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id', 'operations' ),
					'properties' => array(
						'id'                    => array(
							'type'        => 'integer',
							'description' => 'Post ID to repair.',
						),
						'operations'            => array(
							'type'        => 'array',
							'description' => 'List of repair operations to apply.',
							'items'       => array(
								'type' => 'string',
								'enum' => $op_keys,
							),
						),
						'dry_run'               => array(
							'type'        => 'boolean',
							'description' => 'If true, return what would change without writing. Default: false.',
							'default'     => false,
						),
						'expected_modified_gmt' => array(
							'type'        => 'string',
							'description' => 'Concurrency guard: the modified_gmt value from a recent get-post. Required unless dry_run is true.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer' ),
						'dry_run'      => array( 'type' => 'boolean' ),
						'applied'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'operation'   => array( 'type' => 'string' ),
									'status'      => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'changes'     => array( 'description' => 'Details of what changed.' ),
								),
							),
						),
						'modified_gmt' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array(
					'mcp'         => array( 'public' => true ),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for the repair-post ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to repair posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the repair-post ability.
	 *
	 * @param array|null $input Ability input with post ID, operations, and options.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = $input ?? array();

		if ( empty( $input['id'] ) ) {
			return new WP_Error( 'missing_param', 'id is required.', array( 'status' => 400 ) );
		}
		if ( empty( $input['operations'] ) ) {
			return new WP_Error( 'missing_param', 'operations is required.', array( 'status' => 400 ) );
		}

		$post = get_post( (int) $input['id'] );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this post.', array( 'status' => 403 ) );
		}

		$dry_run    = ! empty( $input['dry_run'] );
		$operations = $input['operations'];

		// Concurrency guard (skip for dry_run).
		if ( ! $dry_run ) {
			if ( empty( $input['expected_modified_gmt'] ) ) {
				return new WP_Error(
					'missing_param',
					'expected_modified_gmt is required for non-dry-run repairs. Use dry_run first or get-post to read the current value.',
					array( 'status' => 400 )
				);
			}

			$current_modified = mysql2date( 'c', $post->post_modified_gmt );
			if ( $input['expected_modified_gmt'] !== $current_modified ) {
				return new WP_Error(
					'conflict',
					'Post was modified since you last read it. Re-read and try again.',
					array(
						'status'   => 409,
						'expected' => $input['expected_modified_gmt'],
						'actual'   => $current_modified,
					)
				);
			}
		}

		// Validate operations.
		$valid_ops = array_keys( self::OPERATIONS );
		foreach ( $operations as $op ) {
			if ( ! in_array( $op, $valid_ops, true ) ) {
				return new WP_Error( 'invalid_operation', "Unknown repair operation: {$op}", array( 'status' => 400 ) );
			}
		}

		// Run repairs.
		$content         = $post->post_content;
		$title           = $post->post_title;
		$excerpt         = $post->post_excerpt;
		$results         = array();
		$content_changed = false;
		$title_changed   = false;
		$excerpt_changed = false;

		foreach ( $operations as $op ) {
			switch ( $op ) {
				case 'http-to-https':
					$result = self::repair_http_to_https( $content );
					if ( $result['changed'] ) {
						$content         = $result['content'];
						$content_changed = true;
					}
					$results[] = array(
						'operation'   => $op,
						'status'      => $result['changed'] ? 'applied' : 'no_change',
						'description' => $result['description'],
						'changes'     => $result['details'],
					);
					break;

				case 'strip-deprecated-html':
					$result = self::repair_strip_deprecated( $content );
					if ( $result['changed'] ) {
						$content         = $result['content'];
						$content_changed = true;
					}
					$results[] = array(
						'operation'   => $op,
						'status'      => $result['changed'] ? 'applied' : 'no_change',
						'description' => $result['description'],
						'changes'     => $result['details'],
					);
					break;

				case 'convert-to-blocks':
					if ( has_blocks( $content ) ) {
						$results[] = array(
							'operation'   => $op,
							'status'      => 'skipped',
							'description' => 'Content already has block markup.',
							'changes'     => null,
						);
					} else {
						$new_content = APMCP_Markdown_Converter::html_to_blocks( $content );
						if ( $new_content !== $content ) {
							$old_content     = $content;
							$content         = $new_content;
							$content_changed = true;
							$results[]       = array(
								'operation'   => $op,
								'status'      => 'applied',
								'description' => 'Converted classic HTML to Gutenberg blocks.',
								'changes'     => array(
									'block_count' => substr_count( $new_content, '<!-- wp:' ),
								),
							);
						} else {
							$results[] = array(
								'operation'   => $op,
								'status'      => 'no_change',
								'description' => 'Conversion produced no changes.',
								'changes'     => null,
							);
						}
					}
					break;

				case 'generate-excerpt':
					if ( ! empty( $post->post_excerpt ) ) {
						$results[] = array(
							'operation'   => $op,
							'status'      => 'skipped',
							'description' => 'Post already has a manual excerpt.',
							'changes'     => null,
						);
					} else {
						$new_excerpt = self::generate_excerpt( $content );
						if ( $new_excerpt ) {
							$excerpt         = $new_excerpt;
							$excerpt_changed = true;
							$results[]       = array(
								'operation'   => $op,
								'status'      => 'applied',
								'description' => 'Generated excerpt from first paragraph.',
								'changes'     => array( 'excerpt' => $new_excerpt ),
							);
						} else {
							$results[] = array(
								'operation'   => $op,
								'status'      => 'no_change',
								'description' => 'Could not extract a suitable excerpt.',
								'changes'     => null,
							);
						}
					}
					break;

				case 'whitespace-normalize':
					$result = self::repair_whitespace( $content );
					if ( $result['changed'] ) {
						$content         = $result['content'];
						$content_changed = true;
					}
					$results[] = array(
						'operation'   => $op,
						'status'      => $result['changed'] ? 'applied' : 'no_change',
						'description' => $result['description'],
						'changes'     => $result['details'],
					);
					break;

				case 'decode-title-entities':
					$decoded = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5 );
					if ( $decoded !== $title ) {
						$old_title     = $title;
						$title         = $decoded;
						$title_changed = true;
						$results[]     = array(
							'operation'   => $op,
							'status'      => 'applied',
							'description' => 'Decoded HTML entities in title.',
							'changes'     => array(
								'old_title' => $old_title,
								'new_title' => $decoded,
							),
						);
					} else {
						$results[] = array(
							'operation'   => $op,
							'status'      => 'no_change',
							'description' => 'No HTML entities found in title.',
							'changes'     => null,
						);
					}
					break;

				case 'strip-empty-tags':
					$result = self::repair_strip_empty_tags( $content );
					if ( $result['changed'] ) {
						$content         = $result['content'];
						$content_changed = true;
					}
					$results[] = array(
						'operation'   => $op,
						'status'      => $result['changed'] ? 'applied' : 'no_change',
						'description' => $result['description'],
						'changes'     => $result['details'],
					);
					break;
			}
		}

		// Apply changes unless dry_run.
		if ( ! $dry_run && ( $content_changed || $title_changed || $excerpt_changed ) ) {
			$update_args = array( 'ID' => $post->ID );
			if ( $content_changed ) {
				$update_args['post_content'] = $content;
			}
			if ( $title_changed ) {
				$update_args['post_title'] = $title;
			}
			if ( $excerpt_changed ) {
				$update_args['post_excerpt'] = $excerpt;
			}

			$result = wp_update_post( $update_args, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Re-read to get updated modified time.
			$post = get_post( $post->ID );
		}

		return array(
			'post_id'      => $post->ID,
			'dry_run'      => $dry_run,
			'applied'      => $results,
			'modified_gmt' => mysql2date( 'c', $post->post_modified_gmt ),
		);
	}

	// Repair operations.

	/**
	 * Convert HTTP links to HTTPS.
	 *
	 * @param string $content Post content.
	 * @return array Result with content, changed flag, description, and details.
	 */
	private static function repair_http_to_https( $content ) {
		$count        = 0;
		$changed_urls = array();

		// Replace http:// with https:// in href and src attributes.
		$new_content = preg_replace_callback(
			'/(href|src)="http:\/\/([^"]+)"/i',
			function ( $m ) use ( &$count, &$changed_urls ) {
				$count++;
				$changed_urls[] = 'http://' . $m[2];
				return $m[1] . '="https://' . $m[2] . '"';
			},
			$content
		);

		// Also handle markdown-style links.
		$new_content = preg_replace_callback(
			'/\]\(http:\/\/([^)]+)\)/',
			function ( $m ) use ( &$count, &$changed_urls ) {
				$count++;
				$changed_urls[] = 'http://' . $m[1];
				return '](https://' . $m[1] . ')';
			},
			$new_content
		);

		return array(
			'content'     => $new_content,
			'changed'     => $count > 0,
			'description' => $count > 0
				? sprintf( 'Converted %d HTTP link(s) to HTTPS.', $count )
				: 'No HTTP links found.',
			'details'     => $count > 0 ? $changed_urls : null,
		);
	}

	/**
	 * Strip deprecated HTML tags, keeping inner content.
	 *
	 * @param string $content Post content.
	 * @return array Result with content, changed flag, description, and details.
	 */
	private static function repair_strip_deprecated( $content ) {
		$tags_found  = array();
		$new_content = $content;

		foreach ( APMCP_Audit_Post::DEPRECATED_TAGS as $tag ) {
			$before = $new_content;
			// Remove opening and closing tags, preserve content.
			$new_content = preg_replace( '/<' . $tag . '[^>]*>/i', '', $new_content );
			$new_content = preg_replace( '/<\/' . $tag . '>/i', '', $new_content );
			if ( $new_content !== $before ) {
				$tags_found[] = $tag;
			}
		}

		return array(
			'content'     => $new_content,
			'changed'     => ! empty( $tags_found ),
			'description' => ! empty( $tags_found )
				? sprintf( 'Stripped deprecated tags: %s', implode( ', ', $tags_found ) )
				: 'No deprecated HTML tags found.',
			'details'     => ! empty( $tags_found ) ? $tags_found : null,
		);
	}

	/**
	 * Normalize whitespace in content.
	 *
	 * @param string $content Post content.
	 * @return array Result with content, changed flag, description, and details.
	 */
	private static function repair_whitespace( $content ) {
		$original = $content;

		// Replace 3+ consecutive newlines with 2.
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		// Replace multiple &nbsp; with a single space.
		$content = preg_replace( '/(&nbsp;\s*){2,}/', ' ', $content );

		// Remove trailing whitespace on lines.
		$content = preg_replace( '/[ \t]+$/m', '', $content );

		$changed = $content !== $original;

		return array(
			'content'     => $content,
			'changed'     => $changed,
			'description' => $changed
				? 'Normalized whitespace and removed artifacts.'
				: 'No whitespace issues found.',
			'details'     => null,
		);
	}

	/**
	 * Remove empty HTML tags.
	 *
	 * @param string $content Post content.
	 * @return array Result with content, changed flag, description, and details.
	 */
	private static function repair_strip_empty_tags( $content ) {
		$original = $content;
		$count    = 0;

		// Remove empty p, div, span, strong, em tags.
		$empty_tags = array( 'p', 'div', 'span', 'strong', 'em', 'b', 'i' );
		foreach ( $empty_tags as $tag ) {
			$content = preg_replace(
				'/<' . $tag . '[^>]*>\s*<\/' . $tag . '>/i',
				'',
				$content,
				-1,
				$tag_count
			);
			$count  += $tag_count;
		}

		$changed = $content !== $original;

		return array(
			'content'     => $content,
			'changed'     => $changed,
			'description' => $changed
				? sprintf( 'Removed %d empty HTML tag(s).', $count )
				: 'No empty tags found.',
			'details'     => $changed ? array( 'removed_count' => $count ) : null,
		);
	}

	/**
	 * Generate an excerpt from content.
	 *
	 * @param string $content Post content.
	 * @return string|null Generated excerpt or null if content is empty.
	 */
	private static function generate_excerpt( $content ) {
		// Strip block comments.
		$text = preg_replace( '/<!-- \/?wp:\S+[^>]*-->/', '', $content );
		// Strip HTML tags.
		$text = wp_strip_all_tags( $text );
		// Trim and take first 55 words (WordPress default).
		$text = trim( $text );

		if ( empty( $text ) ) {
			return null;
		}

		$words = preg_split( '/\s+/', $text );
		if ( count( $words ) > 55 ) {
			$words = array_slice( $words, 0, 55 );
			return implode( ' ', $words ) . '...';
		}

		return implode( ' ', $words );
	}
}
