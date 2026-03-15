<?php
/**
 * Test server segmentation surface definitions.
 */

class ServerSegmentationTest extends WP_UnitTestCase {

	public function test_reader_surface_is_read_only() {
		$reader = APMCP_Server_Segmentation::get_allowed_abilities( 'reader' );

		// Should contain read operations.
		$this->assertContains( 'anotherpanacea-mcp/search-posts', $reader );
		$this->assertContains( 'anotherpanacea-mcp/get-post', $reader );
		$this->assertContains( 'anotherpanacea-mcp/get-blocks', $reader );

		// Should NOT contain write or destructive operations.
		$this->assertNotContains( 'anotherpanacea-mcp/create-post', $reader );
		$this->assertNotContains( 'anotherpanacea-mcp/update-post', $reader );
		$this->assertNotContains( 'anotherpanacea-mcp/delete-post', $reader );
		$this->assertNotContains( 'anotherpanacea-mcp/transition-status', $reader );
	}

	public function test_editorial_surface_has_write_no_delete() {
		$editorial = APMCP_Server_Segmentation::get_allowed_abilities( 'editorial' );

		// Should contain read + write.
		$this->assertContains( 'anotherpanacea-mcp/search-posts', $editorial );
		$this->assertContains( 'anotherpanacea-mcp/create-post', $editorial );
		$this->assertContains( 'anotherpanacea-mcp/update-post', $editorial );
		$this->assertContains( 'anotherpanacea-mcp/update-blocks', $editorial );

		// Should NOT contain destructive operations.
		$this->assertNotContains( 'anotherpanacea-mcp/delete-post', $editorial );
		$this->assertNotContains( 'anotherpanacea-mcp/transition-status', $editorial );
		$this->assertNotContains( 'anotherpanacea-mcp/delete-comment', $editorial );
	}

	public function test_full_surface_has_everything() {
		$full = APMCP_Server_Segmentation::get_allowed_abilities( 'full' );

		// Should contain everything.
		$this->assertContains( 'anotherpanacea-mcp/search-posts', $full );
		$this->assertContains( 'anotherpanacea-mcp/create-post', $full );
		$this->assertContains( 'anotherpanacea-mcp/delete-post', $full );
		$this->assertContains( 'anotherpanacea-mcp/transition-status', $full );
		$this->assertContains( 'anotherpanacea-mcp/list-audit-log', $full );
	}

	public function test_editorial_is_superset_of_reader() {
		$reader    = APMCP_Server_Segmentation::get_allowed_abilities( 'reader' );
		$editorial = APMCP_Server_Segmentation::get_allowed_abilities( 'editorial' );

		foreach ( $reader as $ability ) {
			$this->assertContains( $ability, $editorial, "Editorial should contain reader ability: {$ability}" );
		}
	}

	public function test_full_is_superset_of_editorial() {
		$editorial = APMCP_Server_Segmentation::get_allowed_abilities( 'editorial' );
		$full      = APMCP_Server_Segmentation::get_allowed_abilities( 'full' );

		foreach ( $editorial as $ability ) {
			$this->assertContains( $ability, $full, "Full should contain editorial ability: {$ability}" );
		}
	}

	public function test_execute_wrapper_blocks_unauthorized_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );

		// Try to execute a destructive ability via the reader surface.
		$result = APMCP_Server_Segmentation::execute_reader( array(
			'ability_name' => 'anotherpanacea-mcp/delete-post',
			'parameters'   => array( 'id' => 1 ),
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not available on the reader surface', $result['error'] );
	}
}
