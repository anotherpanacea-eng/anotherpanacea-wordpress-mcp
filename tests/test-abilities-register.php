<?php
/**
 * Test that all plugin abilities register successfully.
 */

class AbilitiesRegisterTest extends WP_UnitTestCase {

	/**
	 * All expected ability slugs.
	 */
	private $expected_abilities = array(
		'anotherpanacea-mcp/search-posts',
		'anotherpanacea-mcp/get-post',
		'anotherpanacea-mcp/get-blocks',
		'anotherpanacea-mcp/list-categories',
		'anotherpanacea-mcp/list-tags',
		'anotherpanacea-mcp/list-revisions',
		'anotherpanacea-mcp/search-media',
		'anotherpanacea-mcp/search-comments',
		'anotherpanacea-mcp/create-post',
		'anotherpanacea-mcp/update-post',
		'anotherpanacea-mcp/transition-post-status',
		'anotherpanacea-mcp/upload-media',
		'anotherpanacea-mcp/update-media',
		'anotherpanacea-mcp/delete-post',
		'anotherpanacea-mcp/create-comment',
		'anotherpanacea-mcp/update-comment',
		'anotherpanacea-mcp/delete-comment',
		'anotherpanacea-mcp/manage-category',
		'anotherpanacea-mcp/manage-tag',
		'anotherpanacea-mcp/update-blocks',
		'anotherpanacea-mcp/resource-taxonomy-map',
		'anotherpanacea-mcp/resource-recent-drafts',
		'anotherpanacea-mcp/resource-site-info',
		'anotherpanacea-mcp/prompt-draft-post',
		'anotherpanacea-mcp/prompt-review-post',
		'anotherpanacea-mcp/list-audit-log',
		'anotherpanacea-mcp/audit-post',
		'anotherpanacea-mcp/repair-post',
	);

	public function test_all_abilities_registered() {
		// Skip if Abilities API not available (pre-6.9).
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		foreach ( $this->expected_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			$this->assertNotNull( $ability, "Ability '{$slug}' should be registered." );
		}
	}

	public function test_all_abilities_have_required_fields() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		foreach ( $this->expected_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			if ( ! $ability ) {
				continue;
			}
			$this->assertNotEmpty( $ability->get_label(), "Ability '{$slug}' should have a label." );
			$this->assertNotEmpty( $ability->get_description(), "Ability '{$slug}' should have a description." );
		}
	}

	public function test_mcp_public_flag_set() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		foreach ( $this->expected_abilities as $slug ) {
			$ability = wp_get_ability( $slug );
			if ( ! $ability ) {
				continue;
			}
			$meta = $ability->get_meta();
			$this->assertTrue(
				$meta['mcp']['public'] ?? false,
				"Ability '{$slug}' should have meta.mcp.public = true."
			);
		}
	}
}
