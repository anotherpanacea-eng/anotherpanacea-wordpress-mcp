<?php
/**
 * PHPUnit bootstrap for AnotherPanacea MCP plugin tests.
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	exit( 1 );
}

// Load WordPress test functions.
require_once "{$_tests_dir}/includes/functions.php";

// Load plugin.
tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/anotherpanacea-mcp.php';
} );

// Start WordPress test suite.
require "{$_tests_dir}/includes/bootstrap.php";
