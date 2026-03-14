<?php
/**
 * Minimal PSR-4 autoloader for WP\MCP namespace.
 *
 * Generated in lieu of Composer since the only runtime dependency is
 * the WP\MCP\ namespace mapped to the includes/ directory.
 */

spl_autoload_register( function ( $class ) {
	$prefix    = 'WP\\MCP\\';
	$base_dir  = dirname( __DIR__ ) . '/includes/';
	$prefix_len = strlen( $prefix );

	if ( 0 !== strncmp( $prefix, $class, $prefix_len ) ) {
		return;
	}

	$relative_class = substr( $class, $prefix_len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );
