<?php
/**
 * Audit logging for APMCP write actions.
 *
 * Creates a custom database table {prefix}apmcp_audit_log and hooks into
 * WordPress core actions (wp_insert_post, transition_post_status,
 * wp_trash_post, add_attachment, edit_attachment) to record every write
 * action performed through the MCP abilities.
 *
 * Also registers a read-only `list-audit-log` ability for querying the log.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit log class: hooks into WP lifecycle events and provides a list-audit-log ability.
 */
class APMCP_Audit_Log {

	/**
	 * Database table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_SUFFIX = 'apmcp_audit_log';

	/**
	 * Initialize hooks.
	 *
	 * Called on 'plugins_loaded' (or wp_abilities_api_init) after all abilities are registered.
	 */
	public static function init() {
		// Post creation — fires after wp_insert_post inserts a new post.
		add_action( 'wp_insert_post', array( __CLASS__, 'on_insert_post' ), 10, 3 );

		// Status transitions — fires on every post status change, including wp_update_post calls.
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );

		// Trash — fires when a post is moved to trash.
		add_action( 'trashed_post', array( __CLASS__, 'on_trashed_post' ), 10, 1 );

		// Media uploads.
		add_action( 'add_attachment', array( __CLASS__, 'on_add_attachment' ), 10, 1 );

		// Media edits (alt text, caption, etc.).
		add_action( 'edit_attachment', array( __CLASS__, 'on_edit_attachment' ), 10, 1 );
	}

	/**
	 * Create the custom audit log table.
	 *
	 * Called on plugin activation via register_activation_hook().
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ability_name VARCHAR(200)        NOT NULL DEFAULT '',
			post_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			before_status VARCHAR(20)        NOT NULL DEFAULT '',
			after_status  VARCHAR(20)        NOT NULL DEFAULT '',
			request_id   VARCHAR(100)        NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_id  (user_id),
			KEY post_id  (post_id),
			KEY timestamp (timestamp)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a row into the audit log table.
	 *
	 * @param string $ability_name  Name of the ability (e.g. 'anotherpanacea-mcp/create-post').
	 * @param int    $post_id       Related post ID (0 for non-post actions).
	 * @param string $before_status Status before the action (empty string if creating).
	 * @param string $after_status  Status after the action.
	 */
	public static function log( $ability_name, $post_id, $before_status, $after_status ) {
		global $wpdb;

		$request_id = '';
		if ( isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ) {
			$request_id = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_SUFFIX,
			array(
				'timestamp'     => current_time( 'mysql', true ), // UTC.
				'user_id'       => get_current_user_id(),
				'ability_name'  => $ability_name,
				'post_id'       => (int) $post_id,
				'before_status' => $before_status,
				'after_status'  => $after_status,
				'request_id'    => $request_id,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * WordPress core action callbacks.
	 */

	/**
	 * Log new post insertions.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  True if this is an update, false for new inserts.
	 */
	public static function on_insert_post( $post_id, $post, $update ) {
		// Only log new posts, not updates (transition_post_status handles updates).
		if ( $update ) {
			return;
		}
		// Only log publicly-visible post types the plugin manages.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		self::log( 'anotherpanacea-mcp/create-post', $post_id, '', $post->post_status );
	}

	/**
	 * Log post status transitions.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		// Skip if status hasn't changed.
		if ( $new_status === $old_status ) {
			return;
		}
		// Only log post/page types.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		// Determine which ability drove this change.
		// We can't know for certain, so we record a generic label for
		// transitions and a specific label for trashing (handled in on_trashed_post).
		$ability = 'to-trash' === $new_status
			? 'anotherpanacea-mcp/delete-post'
			: 'anotherpanacea-mcp/transition-post-status';

		self::log( $ability, $post->ID, $old_status, $new_status );
	}

	/**
	 * Log when a post is moved to trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_trashed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		// The transition_post_status hook fires before this, so the
		// before_status recorded there will have the original status.
		// Here we record a dedicated trash entry only if the type is one we manage.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		// The actual transition is already captured by on_transition_post_status.
		// No duplicate log needed here unless we want finer granularity.
		// Intentionally left as a no-op to avoid duplicate rows.
	}

	/**
	 * Log new media uploads.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public static function on_add_attachment( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return;
		}
		self::log( 'anotherpanacea-mcp/upload-media', $attachment_id, '', 'inherit' );
	}

	/**
	 * Log media attachment edits.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public static function on_edit_attachment( $attachment_id ) {
		self::log( 'anotherpanacea-mcp/update-media', $attachment_id, 'inherit', 'inherit' );
	}

	/**
	 * List-audit-log ability.
	 */

	/**
	 * Register the list-audit-log ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/list-audit-log',
			array(
				'label'               => __( 'List Audit Log', 'anotherpanacea-mcp' ),
				'description'         => __( 'Query the APMCP audit log. Returns write actions performed via MCP abilities.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Number of results per page. Default 20, max 100.',
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number (1-based). Default 1.',
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => 'Filter by user ID.',
						),
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'Filter by post ID.',
						),
						'ability'  => array(
							'type'        => 'string',
							'description' => 'Filter by ability name (exact or partial match).',
						),
						'after'    => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Return entries after this timestamp.',
						),
						'before'   => array(
							'type'        => 'string',
							'description' => 'ISO 8601 date. Return entries before this timestamp.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'entries'     => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'            => array( 'type' => 'integer' ),
									'timestamp'     => array( 'type' => 'string' ),
									'user_id'       => array( 'type' => 'integer' ),
									'ability_name'  => array( 'type' => 'string' ),
									'post_id'       => array( 'type' => 'integer' ),
									'before_status' => array( 'type' => 'string' ),
									'after_status'  => array( 'type' => 'string' ),
									'request_id'    => array( 'type' => 'string' ),
								),
							),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'show_in_rest'        => true,
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the list-audit-log ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view the audit log.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the list-audit-log ability.
	 *
	 * @param array|null $input Ability input with filtering and pagination parameters.
	 * @return array Paginated audit log entries.
	 */
	public static function execute( $input = null ) {
		global $wpdb;

		$input    = $input ?? array();
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table  = $wpdb->prefix . self::TABLE_SUFFIX;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $input['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $input['user_id'];
		}

		if ( ! empty( $input['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $input['post_id'];
		}

		if ( ! empty( $input['ability'] ) ) {
			$where[]  = 'ability_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $input['ability'] ) . '%';
		}

		if ( ! empty( $input['after'] ) ) {
			$after_ts = gmdate( 'Y-m-d H:i:s', strtotime( $input['after'] ) );
			if ( $after_ts ) {
				$where[]  = 'timestamp > %s';
				$params[] = $after_ts;
			}
		}

		if ( ! empty( $input['before'] ) ) {
			$before_ts = gmdate( 'Y-m-d H:i:s', strtotime( $input['before'] ) );
			if ( $before_ts ) {
				$where[]  = 'timestamp < %s';
				$params[] = $before_ts;
			}
		}

		$where_clause = implode( ' AND ', $where );

		// Total count.
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		// Fetch rows.
		$query_params   = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, timestamp, user_id, ability_name, post_id, before_status, after_status, request_id FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				$query_params
			),
			ARRAY_A
		);

		$entries = array();
		foreach ( $rows as $row ) {
			$entries[] = array(
				'id'            => (int) $row['id'],
				'timestamp'     => $row['timestamp'],
				'user_id'       => (int) $row['user_id'],
				'ability_name'  => $row['ability_name'],
				'post_id'       => (int) $row['post_id'],
				'before_status' => $row['before_status'],
				'after_status'  => $row['after_status'],
				'request_id'    => $row['request_id'],
			);
		}

		return array(
			'entries'     => $entries,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}
}
