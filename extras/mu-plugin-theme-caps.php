<?php
/**
 * Register a "Theme Editor" role: Editor + theme management capabilities.
 *
 * Drop this file into wp-content/mu-plugins/ on your site.
 * Then assign the "Theme Editor" role to your MCP user via
 * Users → Edit User in wp-admin.
 *
 * The role is stored in the database. To remove it cleanly,
 * reassign affected users to another role, then delete this file
 * and run: wp role delete theme_editor
 *
 * @package AnotherPanacea_MCP
 */

add_action(
	'init',
	function () {
		// Only register once — role persists in the database.
		if ( get_role( 'theme_editor' ) ) {
			return;
		}

		// Clone Editor capabilities and add theme management.
		$editor = get_role( 'editor' );
		if ( ! $editor ) {
			return;
		}

		$caps = $editor->capabilities;

		// Theme management caps (needed by MCP theme abilities).
		$caps['edit_themes']   = true;
		$caps['switch_themes'] = true;

		add_role( 'theme_editor', 'Theme Editor', $caps );
	}
);
