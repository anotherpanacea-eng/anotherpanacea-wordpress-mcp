<?php
/**
 * Create-theme ability: Scaffold a new block theme.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new block theme via the MCP abilities API.
 */
class APMCP_Create_Theme {

	/**
	 * Register the create-theme ability.
	 */
	public static function register() {
		wp_register_ability(
			'anotherpanacea-mcp/create-theme',
			array(
				'label'               => __( 'Create Theme', 'anotherpanacea-mcp' ),
				'description'         => __( 'Scaffold a new block theme with required files (style.css, templates/index.html), theme.json with design tokens, starter template parts (header, footer), and empty directories for patterns, styles, and assets.', 'anotherpanacea-mcp' ),
				'category'            => 'anotherpanacea-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'slug', 'name' ),
					'properties' => array(
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Theme directory name (lowercase, hyphens). Must not already exist.',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'Human-readable theme name.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Theme description.',
						),
						'author'      => array(
							'type'        => 'string',
							'description' => 'Theme author name.',
						),
						'author_uri'  => array(
							'type'        => 'string',
							'description' => 'Theme author URL.',
						),
						'version'     => array(
							'type'        => 'string',
							'description' => 'Theme version. Default: 1.0.0.',
						),
						'theme_json'  => array(
							'type'        => 'object',
							'description' => 'Custom theme.json contents. If omitted, a sensible default is generated with common design tokens.',
						),
						'dry_run'     => array(
							'type'        => 'boolean',
							'description' => 'If true, validate inputs and return the file manifest without creating anything.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet'    => array( 'type' => 'string' ),
						'theme_dir'     => array( 'type' => 'string' ),
						'files_created' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);
	}

	/**
	 * Check permissions for the create-theme ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_themes' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to create themes.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Execute the create-theme ability.
	 *
	 * @param array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = $input ?? array();

		$slug        = sanitize_file_name( $input['slug'] ?? '' );
		$name        = sanitize_text_field( $input['name'] ?? '' );
		$description = sanitize_text_field( $input['description'] ?? '' );
		$author      = sanitize_text_field( $input['author'] ?? '' );
		$author_uri  = esc_url_raw( $input['author_uri'] ?? '' );
		$version     = sanitize_text_field( $input['version'] ?? '1.0.0' );
		$dry_run     = ! empty( $input['dry_run'] );

		if ( empty( $slug ) || empty( $name ) ) {
			return new WP_Error( 'missing_param', 'slug and name are required.', array( 'status' => 400 ) );
		}

		// Validate slug format.
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug ) ) {
			return new WP_Error( 'invalid_slug', 'Slug must be lowercase alphanumeric with hyphens, not starting or ending with a hyphen.', array( 'status' => 400 ) );
		}

		$theme_root = get_theme_root();
		$theme_dir  = $theme_root . '/' . $slug;

		// Check the theme doesn't already exist.
		if ( is_dir( $theme_dir ) ) {
			return new WP_Error( 'already_exists', "Theme directory '{$slug}' already exists.", array( 'status' => 409 ) );
		}

		// Build the file manifest.
		$files = self::build_manifest( $slug, $name, $description, $author, $author_uri, $version, $input['theme_json'] ?? null );

		if ( $dry_run ) {
			return array(
				'dry_run'    => true,
				'stylesheet' => $slug,
				'theme_dir'  => $theme_dir,
				'files'      => array_keys( $files ),
			);
		}

		// Create theme directory.
		if ( ! wp_mkdir_p( $theme_dir ) ) {
			return new WP_Error( 'create_failed', 'Could not create theme directory.', array( 'status' => 500 ) );
		}

		// Create subdirectories.
		$dirs = array( 'templates', 'parts', 'patterns', 'styles', 'assets', 'assets/css', 'assets/js', 'assets/images' );
		foreach ( $dirs as $dir ) {
			wp_mkdir_p( $theme_dir . '/' . $dir );
		}

		// Write files.
		$files_created = array();
		foreach ( $files as $relative_path => $content ) {
			$file_path = $theme_dir . '/' . $relative_path;
			$dir       = dirname( $file_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $file_path, $content );
			if ( false !== $written ) {
				$files_created[] = $relative_path;
			}
		}

		// Clear theme cache so WP recognizes the new theme.
		wp_clean_themes_cache();

		return array(
			'stylesheet'    => $slug,
			'theme_dir'     => $theme_dir,
			'files_created' => $files_created,
		);
	}

	/**
	 * Build the file manifest for a new block theme.
	 *
	 * @param string     $slug        Theme slug.
	 * @param string     $name        Theme name.
	 * @param string     $description Theme description.
	 * @param string     $author      Author name.
	 * @param string     $author_uri  Author URI.
	 * @param string     $version     Theme version.
	 * @param array|null $custom_json Custom theme.json or null for defaults.
	 * @return array<string, string> Map of relative path => file content.
	 */
	private static function build_manifest( $slug, $name, $description, $author, $author_uri, $version, $custom_json = null ) {
		$files = array();

		// style.css (required — theme header).
		$header_lines = array(
			'/*',
			"Theme Name: {$name}",
		);
		if ( ! empty( $description ) ) {
			$header_lines[] = "Description: {$description}";
		}
		if ( ! empty( $author ) ) {
			$header_lines[] = "Author: {$author}";
		}
		if ( ! empty( $author_uri ) ) {
			$header_lines[] = "Author URI: {$author_uri}";
		}
		$header_lines[] = "Version: {$version}";
		$header_lines[] = 'Requires at least: 6.4';
		$header_lines[] = 'Tested up to: 6.9';
		$header_lines[] = 'Requires PHP: 7.4';
		$header_lines[] = "Text Domain: {$slug}";
		$header_lines[] = 'License: GPL-2.0-or-later';
		$header_lines[] = '*/';
		$header_lines[] = '';

		$files['style.css'] = implode( "\n", $header_lines );

		// theme.json.
		if ( null !== $custom_json ) {
			$files['theme.json'] = wp_json_encode( $custom_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		} else {
			$files['theme.json'] = wp_json_encode( self::default_theme_json( $name ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		}

		// templates/index.html (required).
		$files['templates/index.html'] = implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","area":"header"} /-->',
				'',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true}} -->',
				'<div class="wp-block-query">',
				'<!-- wp:post-template -->',
				'<!-- wp:post-title {"isLink":true} /-->',
				'<!-- wp:post-date /-->',
				'<!-- wp:post-excerpt /-->',
				'<!-- /wp:post-template -->',
				'<!-- wp:query-pagination -->',
				'<!-- wp:query-pagination-previous /-->',
				'<!-- wp:query-pagination-numbers /-->',
				'<!-- wp:query-pagination-next /-->',
				'<!-- /wp:query-pagination -->',
				'</div>',
				'<!-- /wp:query -->',
				'</main>',
				'<!-- /wp:group -->',
				'',
				'<!-- wp:template-part {"slug":"footer","area":"footer"} /-->',
				'',
			)
		);

		// templates/single.html.
		$files['templates/single.html'] = implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","area":"header"} /-->',
				'',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:post-title {"level":1} /-->',
				'<!-- wp:post-date /-->',
				'<!-- wp:post-content {"layout":{"type":"constrained"}} /-->',
				'<!-- wp:post-terms {"term":"category"} /-->',
				'<!-- wp:post-terms {"term":"post_tag"} /-->',
				'</main>',
				'<!-- /wp:group -->',
				'',
				'<!-- wp:template-part {"slug":"footer","area":"footer"} /-->',
				'',
			)
		);

		// templates/page.html.
		$files['templates/page.html'] = implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","area":"header"} /-->',
				'',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:post-title {"level":1} /-->',
				'<!-- wp:post-content {"layout":{"type":"constrained"}} /-->',
				'</main>',
				'<!-- /wp:group -->',
				'',
				'<!-- wp:template-part {"slug":"footer","area":"footer"} /-->',
				'',
			)
		);

		// templates/404.html.
		$files['templates/404.html'] = implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","area":"header"} /-->',
				'',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:heading {"level":1} -->',
				'<h1 class="wp-block-heading">Page Not Found</h1>',
				'<!-- /wp:heading -->',
				'<!-- wp:paragraph -->',
				'<p>The page you are looking for does not exist.</p>',
				'<!-- /wp:paragraph -->',
				'<!-- wp:search {"label":"Search","buttonText":"Search"} /-->',
				'</main>',
				'<!-- /wp:group -->',
				'',
				'<!-- wp:template-part {"slug":"footer","area":"footer"} /-->',
				'',
			)
		);

		// templates/archive.html.
		$files['templates/archive.html'] = implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","area":"header"} /-->',
				'',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:query-title {"type":"archive"} /-->',
				'<!-- wp:term-description /-->',
				'<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true}} -->',
				'<div class="wp-block-query">',
				'<!-- wp:post-template -->',
				'<!-- wp:post-title {"isLink":true} /-->',
				'<!-- wp:post-date /-->',
				'<!-- wp:post-excerpt /-->',
				'<!-- /wp:post-template -->',
				'<!-- wp:query-pagination -->',
				'<!-- wp:query-pagination-previous /-->',
				'<!-- wp:query-pagination-numbers /-->',
				'<!-- wp:query-pagination-next /-->',
				'<!-- /wp:query-pagination -->',
				'</div>',
				'<!-- /wp:query -->',
				'</main>',
				'<!-- /wp:group -->',
				'',
				'<!-- wp:template-part {"slug":"footer","area":"footer"} /-->',
				'',
			)
		);

		// templates/search.html.
		$files['templates/search.html'] = implode(
			"\n",
			array(
				'<!-- wp:template-part {"slug":"header","area":"header"} /-->',
				'',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->',
				'<main class="wp-block-group">',
				'<!-- wp:query-title {"type":"search"} /-->',
				'<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true}} -->',
				'<div class="wp-block-query">',
				'<!-- wp:post-template -->',
				'<!-- wp:post-title {"isLink":true} /-->',
				'<!-- wp:post-date /-->',
				'<!-- wp:post-excerpt /-->',
				'<!-- /wp:post-template -->',
				'<!-- wp:query-pagination -->',
				'<!-- wp:query-pagination-previous /-->',
				'<!-- wp:query-pagination-numbers /-->',
				'<!-- wp:query-pagination-next /-->',
				'<!-- /wp:query-pagination -->',
				'</div>',
				'<!-- /wp:query -->',
				'</main>',
				'<!-- /wp:group -->',
				'',
				'<!-- wp:template-part {"slug":"footer","area":"footer"} /-->',
				'',
			)
		);

		// parts/header.html.
		$files['parts/header.html'] = implode(
			"\n",
			array(
				'<!-- wp:group {"tagName":"header","layout":{"type":"constrained"}} -->',
				'<header class="wp-block-group">',
				'<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->',
				'<div class="wp-block-group">',
				'<!-- wp:site-title /-->',
				'<!-- wp:navigation /-->',
				'</div>',
				'<!-- /wp:group -->',
				'</header>',
				'<!-- /wp:group -->',
				'',
			)
		);

		// parts/footer.html.
		$files['parts/footer.html'] = implode(
			"\n",
			array(
				'<!-- wp:group {"tagName":"footer","layout":{"type":"constrained"}} -->',
				'<footer class="wp-block-group">',
				'<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->',
				'<div class="wp-block-group">',
				'<!-- wp:paragraph -->',
				'<p>&copy; {year} ' . esc_html( $name ) . '</p>',
				'<!-- /wp:paragraph -->',
				'</div>',
				'<!-- /wp:group -->',
				'</footer>',
				'<!-- /wp:group -->',
				'',
			)
		);

		// functions.php (minimal).
		$func_prefix            = str_replace( '-', '_', $slug );
		$files['functions.php'] = implode(
			"\n",
			array(
				'<?php',
				'/**',
				" * {$name} functions and definitions.",
				' *',
				" * @package {$slug}",
				' */',
				'',
				"if ( ! defined( 'ABSPATH' ) ) {",
				'	exit;',
				'}',
				'',
				'/**',
				' * Enqueue theme styles.',
				' */',
				"function {$func_prefix}_enqueue_styles() {",
				"	wp_enqueue_style( '{$slug}-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );",
				'}',
				"add_action( 'wp_enqueue_scripts', '{$func_prefix}_enqueue_styles' );",
				'',
			)
		);

		// README.txt.
		$files['README.txt'] = implode(
			"\n",
			array(
				"=== {$name} ===",
				'',
				'== Description ==',
				'',
				! empty( $description ) ? $description : 'A custom block theme.',
				'',
				'== Changelog ==',
				'',
				"= {$version} =",
				'* Initial release.',
				'',
			)
		);

		return $files;
	}

	/**
	 * Generate a default theme.json with sensible design tokens.
	 *
	 * @param string $name Theme name.
	 * @return array theme.json structure.
	 */
	private static function default_theme_json( $name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'$schema'  => 'https://schemas.wp.org/wp/6.9/theme.json',
			'version'  => 3,
			'settings' => array(
				'appearanceTools' => true,
				'layout'          => array(
					'contentSize' => '720px',
					'wideSize'    => '1200px',
				),
				'color'           => array(
					'palette' => array(
						array(
							'slug'  => 'primary',
							'color' => '#1a1a2e',
							'name'  => 'Primary',
						),
						array(
							'slug'  => 'secondary',
							'color' => '#16213e',
							'name'  => 'Secondary',
						),
						array(
							'slug'  => 'accent',
							'color' => '#e94560',
							'name'  => 'Accent',
						),
						array(
							'slug'  => 'base',
							'color' => '#ffffff',
							'name'  => 'Base',
						),
						array(
							'slug'  => 'contrast',
							'color' => '#1a1a2e',
							'name'  => 'Contrast',
						),
					),
				),
				'typography'      => array(
					'fontFamilies' => array(
						array(
							'fontFamily' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
							'slug'       => 'system',
							'name'       => 'System',
						),
						array(
							'fontFamily' => '"Georgia", "Times New Roman", serif',
							'slug'       => 'serif',
							'name'       => 'Serif',
						),
						array(
							'fontFamily' => '"SF Mono", "Monaco", "Inconsolata", "Fira Mono", "Droid Sans Mono", "Source Code Pro", monospace',
							'slug'       => 'monospace',
							'name'       => 'Monospace',
						),
					),
					'fontSizes'    => array(
						array(
							'slug' => 'small',
							'size' => '0.875rem',
							'name' => 'Small',
						),
						array(
							'slug' => 'medium',
							'size' => '1rem',
							'name' => 'Medium',
						),
						array(
							'slug' => 'large',
							'size' => '1.25rem',
							'name' => 'Large',
						),
						array(
							'slug' => 'x-large',
							'size' => '1.75rem',
							'name' => 'Extra Large',
						),
						array(
							'slug' => 'xx-large',
							'size' => '2.5rem',
							'name' => 'Huge',
						),
					),
				),
				'spacing'         => array(
					'units' => array( 'px', 'em', 'rem', '%', 'vh', 'vw' ),
				),
			),
			'styles'   => array(
				'color'      => array(
					'background' => 'var(--wp--preset--color--base)',
					'text'       => 'var(--wp--preset--color--contrast)',
				),
				'typography' => array(
					'fontFamily' => 'var(--wp--preset--font-family--system)',
					'fontSize'   => 'var(--wp--preset--font-size--medium)',
					'lineHeight' => '1.6',
				),
				'elements'   => array(
					'link' => array(
						'color' => array(
							'text' => 'var(--wp--preset--color--accent)',
						),
					),
					'h1'   => array(
						'typography' => array(
							'fontSize' => 'var(--wp--preset--font-size--xx-large)',
						),
					),
					'h2'   => array(
						'typography' => array(
							'fontSize' => 'var(--wp--preset--font-size--x-large)',
						),
					),
					'h3'   => array(
						'typography' => array(
							'fontSize' => 'var(--wp--preset--font-size--large)',
						),
					),
				),
				'blocks'     => array(),
			),
		);
	}
}
