<?php
/**
 * Plugin Name: AnotherPanacea MCP
 * Description: Registers MCP abilities for content management via the WordPress Abilities API and MCP Adapter.
 * Version:     1.0.0
 * Author:      Joshua Miller
 * License:     GPL-2.0-or-later
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'APMCP_VERSION', '1.0.0' );
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
	// If the standalone MCP Adapter plugin already defined its constant, skip.
	if ( defined( 'WP_MCP_DIR' ) ) {
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

/**
 * Register the ability category and all abilities.
 */
add_action( 'wp_abilities_api_init', 'apmcp_register_abilities' );

function apmcp_register_abilities() {
	// Register our category first.
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category(
			'anotherpanacea-mcp',
			array(
				'label'       => __( 'AnotherPanacea Content Management', 'anotherpanacea-mcp' ),
				'description' => __( 'Post lifecycle management for anotherpanacea.com.', 'anotherpanacea-mcp' ),
			)
		);
	}

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
