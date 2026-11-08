<?php
/**
 * FatalGuard MCP over stdio — a bridge for the sandboxed Docker demo.
 *
 * On a real site FatalGuard's MCP server is the REST route POST /wp-json/fatalguard/v1/mcp,
 * guarded by a read-only API key. In this throwaway container there is no web server, so
 * this script boots WordPress once, then hands each newline-delimited JSON-RPC message
 * from stdin to the same handler the REST route uses (fatalguard_mcp_handle) and writes
 * the response as one JSON line to stdout. The key check only guards HTTP; it is skipped.
 *
 * stdout carries JSON-RPC only. WordPress notices and stray output go to stderr.
 *
 * Usage: php fatalguard-mcp-stdio.php [path/to/wordpress]   (default /var/www/html)
 */

// --- config ---------------------------------------------------------------
$wp_root = rtrim( $argv[1] ?? getenv( 'WP_ROOT' ) ?: '/var/www/html', '/' );
$stderr  = fopen( 'php://stderr', 'w' );

ini_set( 'display_errors', 'stderr' );
ini_set( 'html_errors', '0' );

// --- boot WordPress (anything it prints goes to stderr, not the protocol stream) ---
$_SERVER['HTTP_HOST']       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME']     = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI']     = '/wp-json/fatalguard/v1/mcp';
$_SERVER['REQUEST_METHOD']  = 'POST';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

ob_start();
require $wp_root . '/wp-load.php';
fwrite( $stderr, (string) ob_get_clean() );

if ( ! function_exists( 'fatalguard_mcp_handle' ) ) {
	fwrite( $stderr, "fatalguard-mcp-stdio: FatalGuard is not active in {$wp_root}\n" );
	exit( 1 );
}

/** Methods the plugin does not declare; answered empty so generic clients get a clean reply. */
const FATALGUARD_STDIO_EMPTY = array(
	'resources/list'           => 'resources',
	'resources/templates/list' => 'resourceTemplates',
	'prompts/list'             => 'prompts',
);

/**
 * Handle one decoded message. Returns the response array, or null when nothing is owed
 * (a notification: JSON-RPC 2.0 never answers a message without an id).
 */
function fatalguard_stdio_dispatch( $msg ): ?array {
	$is_request = is_array( $msg ) && array_key_exists( 'id', $msg );
	$method     = is_array( $msg ) ? (string) ( $msg['method'] ?? '' ) : '';

	if ( $is_request && isset( FATALGUARD_STDIO_EMPTY[ $method ] ) ) {
		return array( 'jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => array( FATALGUARD_STDIO_EMPTY[ $method ] => array() ) );
	}

	$request = new WP_REST_Request( 'POST', '/fatalguard/v1/mcp' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $msg ) );

	ob_start();
	$response = fatalguard_mcp_handle( $request );
	fwrite( $GLOBALS['stderr'], (string) ob_get_clean() );

	if ( ! $is_request && is_array( $msg ) ) {
		return null;
	}
	$data = $response instanceof WP_REST_Response ? $response->get_data() : null;
	return is_array( $data ) ? $data : null;
}

// --- the loop: one JSON-RPC message per line ---------------------------------
while ( false !== ( $line = fgets( STDIN ) ) ) {
	$line = trim( $line );
	if ( '' === $line ) {
		continue;
	}
	$msg = json_decode( $line, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		$out = array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700, 'message' => 'Parse error' ) );
	} else {
		$out = fatalguard_stdio_dispatch( $msg );
	}
	if ( null !== $out ) {
		fwrite( STDOUT, json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		fflush( STDOUT );
	}
}
