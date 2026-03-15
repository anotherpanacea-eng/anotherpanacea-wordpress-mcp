<?php
/**
 * Server segmentation: register separate MCP server endpoints for
 * read-only, editorial, and full-access surfaces.
 *
 * Each surface has its own discover and execute wrapper abilities that
 * restrict which underlying abilities are visible and callable.
 *
 * Servers:
 *   /mcp/reader      — search, get, list only
 *   /mcp/editorial    — reader + create, update (no delete/transition)
 *   /mcp/full         — everything including destructive operations
 *
 * The default MCP Adapter server remains available for backward compatibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APMCP_Server_Segmentation {

	/**
	 * Abilities allowed on the read-only surface.
	 */
	const READER_ABILITIES = array(
		'anotherpanacea-mcp/search-posts',
		'anotherpanacea-mcp/get-post',
		'anotherpanacea-mcp/get-blocks',
		'anotherpanacea-mcp/list-categories',
		'anotherpanacea-mcp/list-tags',
		'anotherpanacea-mcp/list-revisions',
		'anotherpanacea-mcp/search-media',
		'anotherpanacea-mcp/search-comments',
	);

	/**
	 * Additional abilities allowed on the editorial surface (reader + these).
	 */
	const EDITORIAL_ADDITIONS = array(
		'anotherpanacea-mcp/create-post',
		'anotherpanacea-mcp/update-post',
		'anotherpanacea-mcp/update-blocks',
		'anotherpanacea-mcp/create-comment',
		'anotherpanacea-mcp/update-comment',
		'anotherpanacea-mcp/manage-category',
		'anotherpanacea-mcp/manage-tag',
		'anotherpanacea-mcp/upload-media',
		'anotherpanacea-mcp/update-media',
	);

	/**
	 * Additional abilities allowed on the full surface (editorial + these).
	 */
	const FULL_ADDITIONS = array(
		'anotherpanacea-mcp/transition-status',
		'anotherpanacea-mcp/delete-post',
		'anotherpanacea-mcp/delete-comment',
		'anotherpanacea-mcp/list-audit-log',
	);

	/**
	 * MCP resources available on all surfaces.
	 */
	const RESOURCES = array(
		'anotherpanacea-mcp/resource-taxonomy-map',
		'anotherpanacea-mcp/resource-recent-drafts',
		'anotherpanacea-mcp/resource-site-info',
	);

	/**
	 * MCP prompts available on editorial and full surfaces.
	 */
	const PROMPTS = array(
		'anotherpanacea-mcp/prompt-draft-post',
		'anotherpanacea-mcp/prompt-review-post',
	);

	/**
	 * Resolved ability lists per surface (computed once).
	 *
	 * @var array<string, string[]>
	 */
	private static $surface_abilities = array();

	/**
	 * Initialize: register wrapper abilities and hook into MCP Adapter.
	 */
	public static function init() {
		// Register wrapper abilities before servers are created.
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_wrapper_abilities' ) );

		// Register servers after the adapter initializes.
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers' ) );
	}

	/**
	 * Get the allowed abilities for a given surface.
	 *
	 * @param string $surface One of 'reader', 'editorial', 'full'.
	 * @return string[]
	 */
	public static function get_allowed_abilities( $surface ) {
		if ( empty( self::$surface_abilities ) ) {
			self::$surface_abilities['reader']    = self::READER_ABILITIES;
			self::$surface_abilities['editorial'] = array_merge( self::READER_ABILITIES, self::EDITORIAL_ADDITIONS );
			self::$surface_abilities['full']      = array_merge( self::READER_ABILITIES, self::EDITORIAL_ADDITIONS, self::FULL_ADDITIONS );
		}

		return self::$surface_abilities[ $surface ] ?? array();
	}

	/**
	 * Register the 6 wrapper abilities (discover + execute for each surface).
	 */
	public static function register_wrapper_abilities() {
		$surfaces = array(
			'reader'    => 'Read-only access to content.',
			'editorial' => 'Read and write access, no destructive operations.',
			'full'      => 'Full access including destructive operations.',
		);

		foreach ( $surfaces as $surface => $desc ) {
			// Discover wrapper.
			wp_register_ability(
				"anotherpanacea-mcp/discover-{$surface}",
				array(
					'label'               => sprintf( __( 'Discover %s abilities', 'anotherpanacea-mcp' ), ucfirst( $surface ) ),
					'description'         => sprintf( __( 'List available abilities on the %s surface. %s', 'anotherpanacea-mcp' ), $surface, $desc ),
					'category'            => 'anotherpanacea-mcp',
					'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'abilities' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'name'        => array( 'type' => 'string' ),
										'label'       => array( 'type' => 'string' ),
										'description' => array( 'type' => 'string' ),
									),
								),
							),
						),
						'required' => array( 'abilities' ),
					),
					'execute_callback'    => array( __CLASS__, "discover_{$surface}" ),
					'permission_callback' => array( __CLASS__, 'check_permissions' ),
					'show_in_rest'        => true,
					'meta'                => array(
						'annotations' => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);

			// Execute wrapper.
			wp_register_ability(
				"anotherpanacea-mcp/execute-{$surface}",
				array(
					'label'               => sprintf( __( 'Execute %s ability', 'anotherpanacea-mcp' ), ucfirst( $surface ) ),
					'description'         => sprintf( __( 'Execute an ability on the %s surface. %s', 'anotherpanacea-mcp' ), $surface, $desc ),
					'category'            => 'anotherpanacea-mcp',
					'input_schema'        => array(
						'type'                 => 'object',
						'required'             => array( 'ability_name', 'parameters' ),
						'properties'           => array(
							'ability_name' => array(
								'type'        => 'string',
								'description' => 'The full name of the ability to execute.',
							),
							'parameters'   => array(
								'type'        => 'object',
								'description' => 'Parameters to pass to the ability.',
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'description' => 'Result data from the ability.' ),
							'error'   => array( 'type' => 'string' ),
						),
						'required'   => array( 'success' ),
					),
					'execute_callback'    => array( __CLASS__, "execute_{$surface}" ),
					'permission_callback' => array( __CLASS__, 'check_permissions' ),
					'show_in_rest'        => true,
					'meta'                => array(
						'annotations' => array(
							'readonly'    => false,
							'destructive' => 'full' === $surface,
							'idempotent'  => false,
						),
					),
				)
			);
		}
	}

	/**
	 * Permission check shared by all wrapper abilities.
	 * Authentication is required; per-ability checks enforce granular access.
	 */
	public static function check_permissions( $input = null ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'authentication_required', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions.', array( 'status' => 403 ) );
		}
		return true;
	}

	// ── Discover callbacks ──────────────────────────────────────────────

	public static function discover_reader( $input = null ) {
		return self::discover( 'reader' );
	}

	public static function discover_editorial( $input = null ) {
		return self::discover( 'editorial' );
	}

	public static function discover_full( $input = null ) {
		return self::discover( 'full' );
	}

	/**
	 * Discover abilities for a given surface.
	 *
	 * @param string $surface Surface name.
	 * @return array
	 */
	private static function discover( $surface ) {
		$allowed   = self::get_allowed_abilities( $surface );
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		$list = array();
		foreach ( $abilities as $ability ) {
			$name = $ability->get_name();
			$meta = $ability->get_meta();

			// Must be MCP-public and in the allowed list.
			if ( ! ( $meta['mcp']['public'] ?? false ) ) {
				continue;
			}

			// Only tools (default type), not resources/prompts.
			$type = $meta['mcp']['type'] ?? 'tool';
			if ( 'tool' !== $type ) {
				continue;
			}

			if ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}

			$list[] = array(
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
			);
		}

		return array( 'abilities' => $list );
	}

	// ── Execute callbacks ───────────────────────────────────────────────

	public static function execute_reader( $input = null ) {
		return self::execute_surface( 'reader', $input );
	}

	public static function execute_editorial( $input = null ) {
		return self::execute_surface( 'editorial', $input );
	}

	public static function execute_full( $input = null ) {
		return self::execute_surface( 'full', $input );
	}

	/**
	 * Execute an ability on a given surface, with allowlist enforcement.
	 *
	 * @param string     $surface Surface name.
	 * @param array|null $input   Input with ability_name and parameters.
	 * @return array
	 */
	private static function execute_surface( $surface, $input = null ) {
		$input        = $input ?? array();
		$ability_name = $input['ability_name'] ?? '';
		$parameters   = empty( $input['parameters'] ) ? null : $input['parameters'];

		if ( empty( $ability_name ) ) {
			return array( 'success' => false, 'error' => 'ability_name is required.' );
		}

		// Allowlist check: is this ability permitted on this surface?
		$allowed = self::get_allowed_abilities( $surface );
		if ( ! in_array( $ability_name, $allowed, true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'Ability "%s" is not available on the %s surface.',
					$ability_name,
					$surface
				),
			);
		}

		// Resolve the ability.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array( 'success' => false, 'error' => 'Abilities API not available.' );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return array( 'success' => false, 'error' => "Ability '{$ability_name}' not found." );
		}

		// Check the ability's own permissions.
		$permission = $ability->check_permissions( $parameters );
		if ( is_wp_error( $permission ) ) {
			return array( 'success' => false, 'error' => $permission->get_error_message() );
		}
		if ( ! $permission ) {
			return array( 'success' => false, 'error' => 'Permission denied.' );
		}

		// Execute.
		try {
			$result = $ability->execute( $parameters );
			if ( is_wp_error( $result ) ) {
				return array( 'success' => false, 'error' => $result->get_error_message() );
			}
			return array( 'success' => true, 'data' => $result );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}
	}

	// ── Server registration ─────────────────────────────────────────────

	/**
	 * Register 3 segmented MCP servers via the MCP Adapter.
	 *
	 * @param object $adapter The MCP Adapter instance.
	 */
	public static function register_servers( $adapter ) {
		$transport   = 'WP\\MCP\\Transport\\HttpTransport';
		$err_handler = 'WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';

		// Reader: read-only surface.
		$adapter->create_server(
			'apmcp-reader',
			'mcp',
			'reader',
			'AnotherPanacea Reader',
			'Read-only MCP surface: search, get, list.',
			APMCP_VERSION,
			array( $transport ),
			$err_handler,
			null,
			array(
				'anotherpanacea-mcp/discover-reader',
				'anotherpanacea-mcp/execute-reader',
				'mcp-adapter/get-ability-info',
			),
			self::RESOURCES,
			array(), // No prompts on reader.
			function () {
				return current_user_can( 'edit_posts' );
			}
		);

		// Editorial: read + write, no destructive ops.
		$adapter->create_server(
			'apmcp-editorial',
			'mcp',
			'editorial',
			'AnotherPanacea Editorial',
			'Editorial MCP surface: read and write, no destructive operations.',
			APMCP_VERSION,
			array( $transport ),
			$err_handler,
			null,
			array(
				'anotherpanacea-mcp/discover-editorial',
				'anotherpanacea-mcp/execute-editorial',
				'mcp-adapter/get-ability-info',
			),
			self::RESOURCES,
			self::PROMPTS,
			function () {
				return current_user_can( 'edit_posts' );
			}
		);

		// Full: everything including destructive operations.
		$adapter->create_server(
			'apmcp-full',
			'mcp',
			'full',
			'AnotherPanacea Full Access',
			'Full MCP surface including destructive operations.',
			APMCP_VERSION,
			array( $transport ),
			$err_handler,
			null,
			array(
				'anotherpanacea-mcp/discover-full',
				'anotherpanacea-mcp/execute-full',
				'mcp-adapter/get-ability-info',
			),
			self::RESOURCES,
			self::PROMPTS,
			function () {
				return current_user_can( 'edit_others_posts' );
			}
		);
	}
}
