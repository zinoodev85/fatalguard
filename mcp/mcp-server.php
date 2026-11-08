<?php
/**
 * MCP — the endpoint (free + Pro). One route, POST /wp-json/fatalguard/v1/mcp, speaking
 * minimal MCP JSON-RPC 2.0: initialize, tools/list, tools/call, ping (plus the
 * initialized notification, which is a no-op). Everything else returns a proper
 * JSON-RPC "method not found".
 *
 * Auth is the scoped read-only key (X-FatalGuard-Key or Bearer), enforced in the
 * permission callback — a keyless request never reaches a tool. The endpoint is
 * free; Pro only adds the history tool (tools.php). Tools are read-only.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

const FATALGUARD_MCP_PROTOCOL = '2025-06-18'; // MCP protocol revision we advertise

add_action( 'rest_api_init', 'fatalguard_mcp_register_rest' );

function fatalguard_mcp_register_rest(): void {
	register_rest_route(
		'fatalguard/v1',
		'/mcp',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'fatalguard_mcp_permission',
			'callback'            => 'fatalguard_mcp_handle',
		)
	);
	// B173: Return 405 for GET/DELETE so MCP clients see "no SSE stream" (not 404).
	register_rest_route(
		'fatalguard/v1',
		'/mcp',
		array(
			'methods'             => 'GET, DELETE',
			'permission_callback' => '__return_true',
			'callback'            => 'fatalguard_mcp_handle_unsupported',
		)
	);
}

/** B173: GET/DELETE → 405 Method Not Allowed (Streamable HTTP spec: no SSE stream). */
function fatalguard_mcp_handle_unsupported(): WP_REST_Response {
	return new WP_REST_Response( null, 405 );
}

/** A valid read-only key. No manage_options, no nonce, no license check. */
function fatalguard_mcp_permission( WP_REST_Request $request ): bool {
	return fatalguard_mcp_verify( $request );
}

/**
 * JSON-RPC dispatch. Reads the parsed JSON body, routes by method, and always
 * returns a well-formed JSON-RPC envelope (WP serialises the array to JSON).
 */
function fatalguard_mcp_handle( WP_REST_Request $request ) {
	$body = $request->get_json_params();

	// B211: A batch (sequential array) or non-array/null body is malformed JSON-RPC 2.0.
	// Return -32600 Invalid Request; do not silently coerce to empty object.
	// array_is_list() is PHP 8.0+; the plugin floor is 7.4, so use a portable check.
	$is_list = static function ( $a ) {
		if ( array() === $a ) {
			return true;
		}
		$i = 0;
		foreach ( $a as $k => $_v ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	};
	if ( ! is_array( $body ) || $is_list( $body ) ) {
		return fatalguard_mcp_err( null, -32600, 'Invalid Request' );
	}

	$id     = $body['id'] ?? null;
	$method = (string) ( $body['method'] ?? '' );
	$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

	// B210: Notifications (no id) — spec mandates 202 Accepted with no body.
	if ( null === $id && 0 === strpos( $method, 'notifications/' ) ) {
		return new WP_REST_Response( null, 202 );
	}

	switch ( $method ) {
		case 'initialize':
			return fatalguard_mcp_ok(
				$id,
				array(
					'protocolVersion' => FATALGUARD_MCP_PROTOCOL,
					'capabilities'    => array( 'tools' => new stdClass() ),
					'serverInfo'      => array(
						'name'    => 'FatalGuard',
						'version' => defined( 'FATALGUARD_VERSION' ) ? FATALGUARD_VERSION : '0',
					),
					'instructions'    => 'Read-only access to this site\'s error logs and captured fatal errors. Call status first. Only answers while WordPress can still boot; a fully white-screened site is unreachable here.',
				)
			);

		case 'ping':
			return fatalguard_mcp_ok( $id, new stdClass() );

		case 'tools/list':
			$list = array();
			foreach ( fatalguard_mcp_tools() as $name => $tool ) {
				$list[] = array(
					'name'        => $name,
					'description' => $tool['description'],
					'inputSchema' => $tool['inputSchema'],
				);
			}
			return fatalguard_mcp_ok( $id, array( 'tools' => $list ) );

		case 'tools/call':
			return fatalguard_mcp_call( $id, $params );
	}

	return fatalguard_mcp_err( $id, -32601, 'Method not found: ' . $method );
}

/** Run one tool and wrap its result as MCP tool-call content. */
function fatalguard_mcp_call( $id, array $params ) {
	$name  = (string) ( $params['name'] ?? '' );
	$args  = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
	$tools = fatalguard_mcp_tools();

	if ( '' === $name || ! isset( $tools[ $name ] ) ) {
		return fatalguard_mcp_err( $id, -32602, 'Unknown tool: ' . $name );
	}

	$result = call_user_func( $tools[ $name ]['handler'], $args );

	if ( is_wp_error( $result ) ) {
		// Tool-level failure is reported inside a successful JSON-RPC result with
		// isError:true, per MCP — so the model sees the message, not a transport error.
		return fatalguard_mcp_tool_result( $id, $result->get_error_message(), true );
	}

	return fatalguard_mcp_tool_result( $id, wp_json_encode( $result ), false );
}

/** MCP tool result envelope (a single text content block). */
function fatalguard_mcp_tool_result( $id, string $text, bool $is_error ) {
	return fatalguard_mcp_ok(
		$id,
		array(
			'content' => array(
				array( 'type' => 'text', 'text' => $text ),
			),
			'isError' => $is_error,
		)
	);
}

/** JSON-RPC success envelope. */
function fatalguard_mcp_ok( $id, $result ) {
	return new WP_REST_Response(
		array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		),
		200
	);
}

/** JSON-RPC error envelope (still HTTP 200 — the error lives in the body). */
function fatalguard_mcp_err( $id, int $code, string $message ) {
	return new WP_REST_Response(
		array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array( 'code' => $code, 'message' => $message ),
		),
		200
	);
}
