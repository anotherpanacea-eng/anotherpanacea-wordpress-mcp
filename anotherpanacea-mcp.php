<?php
/**
 * Plugin Name: AnotherPanacea MCP
 * Description: Registers MCP abilities for content management via the WordPress Abilities API and MCP Adapter.
 * Version:     1.3.0
 * Author:      Joshua Miller
 * License:     GPL-2.0-or-later
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'APMCP_VERSION', '1.3.0' );
define( 'APMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'APMCP_BUNDLED_MCP_ADAPTER_VERSION', '0.4.1' );

/**
 * Load the bundled MCP Adapter if it is not already active as a standalone plugin.
 *
 * Checks on 'plugins_loaded' (priority 5, before default 10) so the adapter
 * initializes before anything hooks into wp_abilities_api_init.
 */
add_action( 'plugins_loaded', 'apmcp_maybe_load_bundled_mcp_adapter', 5 );

function apmcp_maybe_load_bundled_mcp_adapter() {
	// If MCP Adapter is already available (standalone plugin), skip the bundled copy.
	if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
		return;
	}

	$bundled_path = APMCP_DIR . 'bundled/mcp-adapter/mcp-adapter.php';

	if ( file_exists( $bundled_path ) ) {
		require_once $bundled_path;
	}
}

// Load classes.
require_once APMCP_DIR . 'includes/class-markdown-converter.php';
require_once APMCP_DIR . 'includes/class-search-posts.php';
require_once APMCP_DIR . 'includes/class-get-post.php';
require_once APMCP_DIR . 'includes/class-list-categories.php';
require_once APMCP_DIR . 'includes/class-list-tags.php';
require_once APMCP_DIR . 'includes/class-create-post.php';
require_once APMCP_DIR . 'includes/class-update-post.php';
require_once APMCP_DIR . 'includes/class-transition-status.php';
require_once APMCP_DIR . 'includes/class-upload-media.php';
require_once APMCP_DIR . 'includes/class-delete-post.php';
require_once APMCP_DIR . 'includes/class-list-revisions.php';
require_once APMCP_DIR . 'includes/class-search-media.php';
require_once APMCP_DIR . 'includes/class-update-media.php';
require_once APMCP_DIR . 'includes/class-audit-log.php';
require_once APMCP_DIR . 'includes/class-resource-taxonomy-map.php';
require_once APMCP_DIR . 'includes/class-resource-recent-drafts.php';
require_once APMCP_DIR . 'includes/class-resource-site-info.php';
require_once APMCP_DIR . 'includes/class-prompt-draft-post.php';
require_once APMCP_DIR . 'includes/class-prompt-review-post.php';
require_once APMCP_DIR . 'includes/class-search-comments.php';
require_once APMCP_DIR . 'includes/class-create-comment.php';
require_once APMCP_DIR . 'includes/class-update-comment.php';
require_once APMCP_DIR . 'includes/class-delete-comment.php';
require_once APMCP_DIR . 'includes/class-manage-category.php';
require_once APMCP_DIR . 'includes/class-manage-tag.php';
require_once APMCP_DIR . 'includes/class-get-blocks.php';
require_once APMCP_DIR . 'includes/class-update-blocks.php';

/**
 * Register the ability category and all abilities.
 */
add_action( 'wp_abilities_api_categories_init', 'apmcp_register_category' );
add_action( 'wp_abilities_api_init', 'apmcp_register_abilities' );

// Audit log hooks and table creation.
add_action( 'plugins_loaded', array( 'APMCP_Audit_Log', 'init' ) );
register_activation_hook( __FILE__, array( 'APMCP_Audit_Log', 'create_table' ) );

function apmcp_register_category() {
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category(
			'anotherpanacea-mcp',
			array(
				'label'       => __( 'AnotherPanacea Content Management', 'anotherpanacea-mcp' ),
				'description' => __( 'Post lifecycle management for anotherpanacea.com.', 'anotherpanacea-mcp' ),
			)
		);
	}
}

function apmcp_register_abilities() {
	// Audit log ability.
	APMCP_Audit_Log::register();

	// Read-only abilities.
	APMCP_Search_Posts::register();
	APMCP_Get_Post::register();
	APMCP_List_Categories::register();
	APMCP_List_Tags::register();
	APMCP_List_Revisions::register();
	APMCP_Search_Media::register();

	// Write abilities.
	APMCP_Create_Post::register();
	APMCP_Update_Post::register();
	APMCP_Transition_Status::register();
	APMCP_Upload_Media::register();
	APMCP_Update_Media::register();
	APMCP_Delete_Post::register();

	// Comment abilities.
	APMCP_Search_Comments::register();
	APMCP_Create_Comment::register();
	APMCP_Update_Comment::register();
	APMCP_Delete_Comment::register();

	// Taxonomy CRUD.
	APMCP_Manage_Category::register();
	APMCP_Manage_Tag::register();

	// Block-level abilities.
	APMCP_Get_Blocks::register();
	APMCP_Update_Blocks::register();

	// MCP resources.
	APMCP_Resource_Taxonomy_Map::register();
	APMCP_Resource_Recent_Drafts::register();
	APMCP_Resource_Site_Info::register();

	// MCP prompts.
	APMCP_Prompt_Draft_Post::register();
	APMCP_Prompt_Review_Post::register();
}

/**
 * Register a compatibility route at /wp/v2/wpmcp so that the
 * @automattic/mcp-wordpress-remote client (which expects this path)
 * can reach the MCP Adapter (which registers at /mcp/mcp-adapter-default-server).
 */
add_action( 'rest_api_init', 'apmcp_register_compat_route' );

function apmcp_register_compat_route() {
	register_rest_route(
		'wp/v2',
		'/wpmcp',
		array(
			'methods'             => array( 'POST', 'GET', 'DELETE' ),
			'callback'            => 'apmcp_proxy_to_mcp_adapter',
			'permission_callback' => '__return_true', // Auth handled by MCP Adapter.
		)
	);
}

/**
 * Forward the request to the real MCP Adapter endpoint.
 *
 * @param WP_REST_Request $request The incoming request.
 * @return WP_REST_Response|WP_Error
 */
function apmcp_proxy_to_mcp_adapter( $request ) {
	$internal = new WP_REST_Request( $request->get_method(), '/mcp/mcp-adapter-default-server' );
	$internal->set_headers( $request->get_headers() );
	$internal->set_body( $request->get_body() );
	$internal->set_query_params( $request->get_query_params() );

	return rest_do_request( $internal );
}
