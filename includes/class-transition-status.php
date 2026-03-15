<?php
/**
 * transition-post-status ability: Change a post's status.
 * Separated from update-post because status transitions have side effects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Transition_Status {

	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/transition-post-status',
			array(
				'label'               => __( 'Transition Post Status', 'anotherpanacea-mcp' ),
				'description'         => __( 'Change a post\'s status (draft, pending, publish, private). Supports scheduling via future date.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id', 'status' ),
					'properties' => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
						),
						'status' => array(
							'type'        => 'string',
							'description' => 'Target status: draft, pending, publish, or private.',
							'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
						),
						'date'   => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. For scheduling, set status to publish with a future date.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'title'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
						'date'   => array( 'type' => 'string' ),
						'link'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
			)
		);
	}

	public static function check_permissions( $input = null ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to change post status.', array( 'status' => 403 ) );
		}
		// Publishing and private both require publish_posts capability.
		$status = $input['status'] ?? '';
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to publish or make posts private.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function execute( $input = null ) {
		$input = $input ?? array();
		$id    = (int) ( $input['id'] ?? 0 );

		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this post.', array( 'status' => 403 ) );
		}

		$post_data = array(
			'ID'          => $id,
			'post_status' => $input['status'],
		);

		// Handle scheduling: publish + future date = 'future' status in WordPress.
		if ( ! empty( $input['date'] ) ) {
			$timestamp = strtotime( $input['date'] );
			$post_data['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
			$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );

			// WordPress automatically sets status to 'future' if date is in the future and status is 'publish'.
			if ( 'publish' === $input['status'] && $timestamp > time() ) {
				$post_data['post_status'] = 'future';
			}
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = get_post( $id );

		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'date'   => $post->post_date_gmt ? mysql2date( 'c', $post->post_date_gmt ) : null,
			'link'   => get_permalink( $post->ID ),
		);
	}
}
