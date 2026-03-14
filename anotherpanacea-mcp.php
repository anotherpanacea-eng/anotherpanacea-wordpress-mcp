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
define( 'APMCP_BUNDLED_ABILITIES_API_VERSION', '0.4.0' );
define( 'APMCP_BUNDLED_MCP_ADAPTER_VERSION', '0.4.1' );

/**
 * Load the bundled Abilities API and MCP Adapter if not already active.
 *
 * Loads on 'plugins_loaded' (priority 5, before default 10) so both
 * dependencies initialize before anything hooks into wp_abilities_api_init.
 *
 * Load order matters: Abilities API must be available before MCP Adapter,
 * because MCP Adapter checks function_exists('wp_register_ability').
 */
add_action( 'plugins_loaded', 'apmcp_maybe_load_bundled_dependencies', 5 );

function apmcp_maybe_load_bundled_dependencies() {
	// 1. Load Abilities API if wp_register_ability() is not already available.
	if ( ! function_exists( 'wp_register_ability' ) ) {
		$abilities_path = APMCP_DIR . 'bundled/abilities-api/abilities-api.php';
		if ( file_exists( $abilities_path ) ) {
			require_once $abilities_path;
		}
	}

	// 2. Load MCP Adapter if not already available (standalone plugin).
	if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
		$adapter_path = APMCP_DIR . 'bundled/mcp-adapter/mcp-adapter.php';
		if ( file_exists( $adapter_path ) ) {
			require_once $adapter_path;
		}
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
