<?php
/**
 * Plugin Name: AnotherPanacea MCP
 * Description: Registers MCP abilities for content management via the WordPress Abilities API and MCP Adapter.
 * Version:     1.2.0
 * Author:      Joshua Miller
 * License:     GPL-2.0-or-later
 * Requires at least: 6.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'APMCP_VERSION', '1.2.0' );
define( 'APMCP_DIR', plugin_dir_path( __FILE__ ) );

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

/**
 * Register the ability category and all abilities.
 */
add_action( 'wp_abilities_api_categories_init', 'apmcp_register_category' );
add_action( 'wp_abilities_api_init', 'apmcp_register_abilities' );

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
	// Phase 1: Read-only abilities.
	APMCP_Search_Posts::register();
	APMCP_Get_Post::register();
	APMCP_List_Categories::register();
	APMCP_List_Tags::register();

	// Phase 2: Write abilities.
	APMCP_Create_Post::register();
	APMCP_Update_Post::register();
	APMCP_Transition_Status::register();
	APMCP_Upload_Media::register();
	APMCP_Delete_Post::register();
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
