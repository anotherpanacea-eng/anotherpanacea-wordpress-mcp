<?php
/**
 * Test permission checks across user roles.
 */

class PermissionsTest extends WP_UnitTestCase {

	/**
	 * Abilities that require edit_posts or edit_post.
	 */
	private $editor_abilities = array(
		'anotherpanacea-mcp/search-posts',
		'anotherpanacea-mcp/get-post',
		'anotherpanacea-mcp/list-categories',
		'anotherpanacea-mcp/list-tags',
		'anotherpanacea-mcp/create-post',
		'anotherpanacea-mcp/update-post',
	);

	/**
	 * Abilities that require moderate_comments.
	 */
	private $moderator_abilities = array(
		'anotherpanacea-mcp/search-comments',
		'anotherpanacea-mcp/create-comment',
		'anotherpanacea-mcp/update-comment',
		'anotherpanacea-mcp/delete-comment',
	);

	/**
	 * Abilities that require manage_categories.
	 */
	private $taxonomy_abilities = array(
		'anotherpanacea-mcp/manage-category',
		'anotherpanacea-mcp/manage-tag',
	);

	/**
	 * Abilities that require upload_files.
	 */
	private $upload_abilities = array(
		'anotherpanacea-mcp/upload-media',
	);

	public function test_subscriber_denied_editor_abilities() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		foreach ( $this->editor_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			if ( ! $ability ) {
				continue;
			}
			$result = $ability->check_permissions();
			$this->assertTrue(
				is_wp_error( $result ),
				"Subscriber should be denied '{$slug}'."
			);
		}
	}

	public function test_editor_allowed_editor_abilities() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );

		foreach ( $this->editor_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			if ( ! $ability ) {
				continue;
			}
			$result = $ability->check_permissions();
			$this->assertTrue(
				true === $result,
				"Editor should be allowed '{$slug}'."
			);
		}
	}

	public function test_subscriber_denied_moderator_abilities() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		foreach ( $this->moderator_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			if ( ! $ability ) {
				continue;
			}
			$result = $ability->check_permissions();
			$this->assertTrue(
				is_wp_error( $result ),
				"Subscriber should be denied '{$slug}'."
			);
		}
	}

	public function test_contributor_denied_upload() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $user );

		foreach ( $this->upload_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			if ( ! $ability ) {
				continue;
			}
			$result = $ability->check_permissions();
			$this->assertTrue(
				is_wp_error( $result ),
				"Contributor should be denied '{$slug}'."
			);
		}
	}

	public function test_author_denied_taxonomy_management() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user );

		foreach ( $this->taxonomy_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			if ( ! $ability ) {
				continue;
			}
			$result = $ability->check_permissions();
			$this->assertTrue(
				is_wp_error( $result ),
				"Author should be denied '{$slug}'."
			);
		}
	}
}
