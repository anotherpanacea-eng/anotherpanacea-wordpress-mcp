<?php
/**
 * Prompt-draft-post ability: "Draft a post in house style" editorial workflow prompt.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a structured prompt for drafting posts in house style via the MCP abilities API.
 */
class APMCP_Prompt_Draft_Post {

	/**
	 * Register the prompt-draft-post ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/prompt-draft-post',
			array(
				'label'               => __( 'Draft a Post in House Style', 'anotherpanacea-mcp' ),
				'description'         => __( 'Returns a structured prompt for drafting a new post in the site\'s house style, including available categories and editorial guidelines.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'topic' => array(
							'type'        => 'string',
							'description' => 'The topic or working title for the post to draft.',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'The target category slug for the post (optional).',
						),
					),
					'required' => array( 'topic' ),
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
					'required' => array( 'messages' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
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
	 * Check permissions for the prompt-draft-post ability.
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
	 * Execute the prompt-draft-post ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input    = $input ?? array();
		$topic    = isset( $input['topic'] ) ? sanitize_text_field( $input['topic'] ) : '';
		$category = isset( $input['category'] ) ? sanitize_text_field( $input['category'] ) : '';

		// Build category list for context.
		$category_terms = get_terms( array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		$category_list = '';
		if ( ! is_wp_error( $category_terms ) && ! empty( $category_terms ) ) {
			$lines = array();
			foreach ( $category_terms as $term ) {
				$lines[] = sprintf( '- %s (slug: %s, %d posts)', $term->name, $term->slug, $term->count );
			}
			$category_list = implode( "\n", $lines );
		}

		$site_name = get_bloginfo( 'name' );
		$site_url  = get_bloginfo( 'url' );

		$category_context = $category
			? "The target category is: {$category}"
			: "Choose the most appropriate category from the list below.";

		$system_text = <<<PROMPT
You are an editorial assistant for {$site_name} ({$site_url}).

## House Style Guidelines

- Write in a clear, analytical, and intellectually engaged voice.
- Posts should have a compelling introduction that frames the central question or argument.
- Use section headings (H2/H3) to organize longer posts.
- Conclude with a synthesis or call-to-reflection, not just a summary.
- Prefer concrete examples and specific references over vague generalities.
- Aim for 800–2000 words for standard posts; longer for in-depth essays.
- Avoid listicles as the primary format; prose arguments are preferred.
- Link to relevant prior posts on the site where appropriate.

## Available Categories

{$category_list}

## Task

{$category_context}

Draft a post on the following topic: {$topic}

Produce a complete draft including:
1. A working title
2. The full post body in Markdown
3. A suggested excerpt (1–2 sentences)
4. Recommended category slug
5. Suggested tags (comma-separated slugs)
PROMPT;

		return array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $system_text,
					),
				),
			),
		);
	}
}
