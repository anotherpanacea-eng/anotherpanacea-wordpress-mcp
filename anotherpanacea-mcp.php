<?php
/**
 * Plugin Name: AnotherPanacea MCP
 * Description: Registers MCP abilities for content management via the WordPress Abilities API and MCP Adapter.
 * Version:     1.6.1
 * Author:      Joshua Miller
 * License:     GPL-2.0-or-later
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * GitHub Plugin URI: anotherpanacea-eng/anotherpanacea-wordpress-mcp
 * Primary Branch:    main
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'APMCP_VERSION', '1.6.1' );
define( 'APMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'APMCP_BUNDLED_MCP_ADAPTER_VERSION', '0.4.1' );

/**
 * Load the bundled MCP Adapter if it is not already active as a standalone plugin.
 *
 * Checks on 'plugins_loaded' (priority 5, before default 10) so the adapter
 * initializes before anything hooks into wp_abilities_api_init.
 */
add_action( 'plugins_loaded', 'apmcp_maybe_load_bundled_mcp_adapter', 5 );

/**
 * Conditionally load the bundled MCP Adapter plugin.
 *
 * Skips loading if the standalone MCP Adapter plugin is already active.
 */
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
require_once APMCP_DIR . 'includes/class-audit-post.php';
require_once APMCP_DIR . 'includes/class-repair-post.php';
require_once APMCP_DIR . 'includes/class-list-themes.php';
require_once APMCP_DIR . 'includes/class-get-theme-info.php';
require_once APMCP_DIR . 'includes/class-get-theme-file.php';
require_once APMCP_DIR . 'includes/class-create-theme.php';
require_once APMCP_DIR . 'includes/class-update-theme-file.php';
require_once APMCP_DIR . 'includes/class-delete-theme-file.php';
require_once APMCP_DIR . 'includes/class-activate-theme.php';
require_once APMCP_DIR . 'includes/class-server-segmentation.php';
require_once APMCP_DIR . 'includes/class-self-updater.php';

/**
 * Register the ability category and all abilities.
 */
add_action( 'wp_abilities_api_categories_init', 'apmcp_register_category' );
add_action( 'wp_abilities_api_init', 'apmcp_register_abilities' );

// Audit log hooks and table creation.
add_action( 'plugins_loaded', array( 'APMCP_Audit_Log', 'init' ) );
register_activation_hook( __FILE__, array( 'APMCP_Audit_Log', 'create_table' ) );

// Server segmentation: register reader, editorial, and full MCP surfaces.
APMCP_Server_Segmentation::init();

// Self-updater: check GitHub releases for plugin updates.
APMCP_Self_Updater::init( __FILE__ );

/**
 * Register the AnotherPanacea MCP ability category.
 */
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

/**
 * Register all MCP abilities for content management.
 */
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

	// Audit & repair abilities.
	APMCP_Audit_Post::register();
	APMCP_Repair_Post::register();

	// MCP resources.
	APMCP_Resource_Taxonomy_Map::register();
	APMCP_Resource_Recent_Drafts::register();
	APMCP_Resource_Site_Info::register();

	// Theme abilities.
	APMCP_List_Themes::register();
	APMCP_Get_Theme_Info::register();
	APMCP_Get_Theme_File::register();
	APMCP_Create_Theme::register();
	APMCP_Update_Theme_File::register();
	APMCP_Delete_Theme_File::register();
	APMCP_Activate_Theme::register();

	// MCP prompts.
	APMCP_Prompt_Draft_Post::register();
	APMCP_Prompt_Review_Post::register();
}

/**
 * Register a compatibility route at /wp/v2/wpmcp so that the
 *
 * @automattic/mcp-wordpress-remote client (which expects this path)
 * can reach the MCP Adapter (which registers at /mcp/mcp-adapter-default-server).
 */
add_action( 'rest_api_init', 'apmcp_register_compat_route' );

/**
 * Register a compatibility REST route proxying to the MCP Adapter endpoint.
 */
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
 * Sets audit context when the JSON-RPC call is a tools/call so that
 * audit log entries record the real MCP ability name even on the
 * default/compat route (which bypasses the segmented server wrappers).
 *
 * @param WP_REST_Request $request The incoming request.
 * @return WP_REST_Response|WP_Error
 */
function apmcp_proxy_to_mcp_adapter( $request ) {
	apmcp_set_audit_context_from_jsonrpc( $request->get_body() );

	$internal = new WP_REST_Request( $request->get_method(), '/mcp/mcp-adapter-default-server' );
	$internal->set_headers( $request->get_headers() );
	$internal->set_body( $request->get_body() );
	$internal->set_query_params( $request->get_query_params() );

	$response = rest_do_request( $internal );

	if ( class_exists( 'APMCP_Audit_Log' ) ) {
		APMCP_Audit_Log::clear_context();
	}

	return $response;
}

/**
 * Hook into the default MCP Adapter server route to set audit context.
 *
 * This catches direct requests to /mcp/mcp-adapter-default-server that
 * don't go through the /wp/v2/wpmcp compat proxy.
 */
add_action( 'rest_api_init', 'apmcp_hook_default_mcp_audit_context' );

/**
 * Register a filter on rest_pre_dispatch to set audit context for the default MCP server.
 */
function apmcp_hook_default_mcp_audit_context() {
	add_filter( 'rest_pre_dispatch', 'apmcp_default_server_audit_context', 10, 3 );
}

/**
 * Set audit context for MCP requests on the default adapter server route.
 *
 * @param mixed           $result  Pre-dispatch result (null to continue).
 * @param WP_REST_Server  $server  The REST server instance.
 * @param WP_REST_Request $request The incoming request.
 * @return mixed Unmodified $result (passthrough).
 */
function apmcp_default_server_audit_context( $result, $server, $request ) {
	$route = $request->get_route();
	if ( '/mcp/mcp-adapter-default-server' === $route && 'POST' === $request->get_method() ) {
		apmcp_set_audit_context_from_jsonrpc( $request->get_body() );
	}
	return $result;
}

/**
 * Parse a JSON-RPC request body and set audit context if it's a tools/call.
 *
 * @param string $body Raw request body.
 */
function apmcp_set_audit_context_from_jsonrpc( $body ) {
	if ( ! class_exists( 'APMCP_Audit_Log' ) || empty( $body ) ) {
		return;
	}

	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		return;
	}

	$method = $decoded['method'] ?? '';
	if ( 'tools/call' !== $method ) {
		return;
	}

	$ability_name = $decoded['params']['name'] ?? '';
	if ( ! empty( $ability_name ) ) {
		APMCP_Audit_Log::set_context( $ability_name, 'default' );
	}
}
