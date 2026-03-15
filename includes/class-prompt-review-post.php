<?php
/**
 * Prompt-review-post ability: "Review a post before publishing" editorial review prompt.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a structured editorial review prompt for a post via the MCP abilities API.
 */
class APMCP_Prompt_Review_Post {

	/**
	 * Register the prompt-review-post ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/prompt-review-post',
			array(
				'label'               => __( 'Review Post Before Publishing', 'anotherpanacea-mcp' ),
				'description'         => __( 'Returns a structured editorial review checklist prompt for a specific post. Takes a post_id and returns the post content alongside a review checklist.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The ID of the post to review.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'messages' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'role'    => array( 'type' => 'string' ),
									'content' => array(
										'type'       => 'object',
										'properties' => array(
											'type' => array( 'type' => 'string' ),
											'text' => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					),
					'required'   => array( 'messages' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);
	}

	/**
	 * Check permissions for the prompt-review-post ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to use this prompt.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the prompt-review-post ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input   = $input ?? array();
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( ! $post_id ) {
			return new WP_Error( 'missing_param', 'post_id is required.', array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to review this post.', array( 'status' => 403 ) );
		}

		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

		$author_data = get_userdata( $post->post_author );
		$author_name = $author_data ? $author_data->display_name : 'Unknown';

		$category_list = $categories ? implode( ', ', $categories ) : '(none)';
		$tag_list      = $tags ? implode( ', ', $tags ) : '(none)';

		// Convert blocks to readable text for the prompt context.
		$content_text = APMCP_Markdown_Converter::blocks_to_markdown( $post->post_content );

		$site_name = get_bloginfo( 'name' );

		$review_text = <<<PROMPT
You are an editorial reviewer for {$site_name}. Review the following post before it is published.

## Post Metadata

- **Title**: {$post->post_title}
- **Status**: {$post->post_status}
- **Author**: {$author_name}
- **Categories**: {$category_list}
- **Tags**: {$tag_list}
- **Excerpt**: {$post->post_excerpt}

## Post Content

{$content_text}

---

## Review Checklist

Please evaluate the post against each item below and provide your assessment:

### Content Quality
- [ ] Does the introduction clearly frame the central question or argument?
- [ ] Is the argument well-structured and logically coherent throughout?
- [ ] Are claims supported with concrete examples or references?
- [ ] Is the conclusion a genuine synthesis rather than just a summary?
- [ ] Is the length appropriate for the topic (avoid padding or truncation)?

### House Style
- [ ] Is the voice clear, analytical, and intellectually engaged?
- [ ] Does the post avoid listicle format as the primary structure?
- [ ] Are section headings used appropriately for longer posts?
- [ ] Is the writing free of excessive hedging or vague generalities?

### SEO & Metadata
- [ ] Is the title compelling and descriptive?
- [ ] Does the excerpt accurately summarize the post in 1–2 sentences?
- [ ] Are the categories and tags appropriate?

### Technical
- [ ] Are there any broken links or placeholder text?
- [ ] Are external references cited accurately?
- [ ] Is formatting (headings, bold, lists) used consistently?

### Verdict

Provide:
1. An overall recommendation: **Publish as-is**, **Publish with minor edits**, or **Needs revision**
2. A brief summary of the most important issues to address (if any)
3. Specific suggested edits for the most critical problems
PROMPT;

		return array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $review_text,
					),
				),
			),
		);
	}
}
