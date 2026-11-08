<?php
/**
 * MCP — read-only tool definitions + handlers (free + Pro).
 *
 * Every free tool is a thin adapter over the module's read callbacks in
 * abilities.php (fatalguard_ability_*) — no second copy of the reading, grouping
 * or scrubbing logic. The set is READ-ONLY by construction (no deactivate / write
 * tool), so the scoped key can only ever surface information. Pro adds one tool,
 * query_error_history, when the history module is loaded and Pro is active.
 *
 * A tool is: name, description, inputSchema (JSON Schema for tools/list) and a
 * PHP handler( array $args ): array|WP_Error for tools/call.
 *
 * @package FatalGuard
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tool registry. Filterable so a later module could add tools without
 * touching this file.
 *
 * @return array<string,array{description:string,inputSchema:array,handler:callable}>
 */
function fatalguard_mcp_tools(): array {
	$none  = array( 'type' => 'object', 'properties' => new stdClass() );
	$tools = array(
		'status'             => array(
			'description' => 'Whether PHP error logging and fatal-error capture are on, the WP_DEBUG flags, log sizes, whether alerts are set up, and when the last fatal error happened. Start here.',
			'inputSchema' => $none,
			'handler'     => 'fatalguard_mcp_tool_status',
		),
		'list_log_sources'   => array(
			'description' => 'List the log sources FatalGuard can read on this site (PHP error log, wp-content/debug.log, PHP ini error_log, etc.) with size and whether they are readable. Use the returned id with tail_log or list_errors.',
			'inputSchema' => $none,
			'handler'     => 'fatalguard_mcp_tool_log_sources',
		),
		'tail_log'           => array(
			'description' => 'Return the newest lines of a log source (by id from list_log_sources), oldest first, secrets redacted, optionally only lines containing a text. Use to read raw log context around an error.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'source' => array( 'type' => 'string', 'description' => 'Source id from list_log_sources.' ),
					'max'    => array( 'type' => 'integer', 'description' => 'Number of trailing lines (1-500).', 'minimum' => 1, 'maximum' => 500, 'default' => 200 ),
					'q'      => array( 'type' => 'string', 'description' => 'Optional case-insensitive substring filter.' ),
				),
				'required'   => array( 'source' ),
			),
			'handler'     => 'fatalguard_mcp_tool_tail_log',
		),
		'list_errors'        => array(
			'description' => 'Errors of a log grouped by signature, newest first: level, message, file and line, count, first and last seen. Use for "what keeps failing" questions.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'source' => array( 'type' => 'string', 'description' => 'Source id from list_log_sources. Omit for the first readable log.' ),
					'limit'  => array( 'type' => 'integer', 'description' => 'Max groups (1-100).', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
				),
			),
			'handler'     => 'fatalguard_mcp_tool_list_errors',
		),
		'list_recent_fatals' => array(
			'description' => 'Captured fatal errors (white screens) grouped, newest first: signature, message, file and line, the plugin or theme that caused it, count, first and last seen. Use this to see what is currently crashing the site; pass a signature to get_fatal for details.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'max' => array(
						'type'        => 'integer',
						'description' => 'How many fatal groups to return (1-100).',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 20,
					),
				),
			),
			'handler'     => 'fatalguard_mcp_tool_recent_fatals',
		),
		'get_fatal'          => array(
			'description' => 'The latest record of one fatal-error group (signature from list_recent_fatals): full message incl. stack trace, file, line, origin, URL, PHP version, and its last occurrences.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'signature' => array( 'type' => 'string', 'description' => 'Signature from list_recent_fatals.' ),
				),
				'required'   => array( 'signature' ),
			),
			'handler'     => 'fatalguard_mcp_tool_get_fatal',
		),
	);

	// Pro: the durable searchable history. Only when Pro is active AND the history
	// module (its table + query core) is loaded — the free zip has neither.
	if ( function_exists( 'fatalguard_can_use_pro' ) && fatalguard_can_use_pro()
		&& function_exists( 'fatalguard_pro_history_query_core' ) ) {
		$tools['query_error_history'] = array(
			'description' => 'Search the durable fatal-error history database: filter by text, error type, and date range; group identical errors or list every occurrence. Use for trends and "how often / since when" questions that outlive the live capture.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'search'   => array( 'type' => 'string', 'description' => 'Substring matched against message and file.' ),
					'type'     => array( 'type' => 'integer', 'description' => 'PHP error-type constant (e.g. 1 = E_ERROR).' ),
					'from'     => array( 'type' => 'string', 'description' => 'Start date/time (any strtotime format).' ),
					'to'       => array( 'type' => 'string', 'description' => 'End date/time (any strtotime format).' ),
					'group'    => array( 'type' => 'boolean', 'description' => 'Group identical errors with a count + first/last seen.', 'default' => false ),
					'page'     => array( 'type' => 'integer', 'description' => 'Page number (from 1).', 'minimum' => 1, 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'description' => 'Rows per page (1-200).', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				),
			),
			'handler'     => 'fatalguard_mcp_tool_history',
		);
	}

	/** Filter the MCP tool registry. Keep tools read-only. */
	return (array) apply_filters( 'fatalguard_mcp_tools', $tools );
}

/* -------------------------------------------------------------------------- */
/* Handlers — adapters over abilities.php; each returns array|WP_Error.        */
/* -------------------------------------------------------------------------- */

function fatalguard_mcp_tool_status( array $args ): array {
	return fatalguard_ability_status();
}

/**
 * B044: fatalguard_ability_sources() keeps only id/label/group/kind/exists/
 * readable/size/mtime — the on-disk path (with its AUTH_KEY-derived suffix) is
 * never exposed to a key-holder. tail_log resolves id → path internally.
 */
function fatalguard_mcp_tool_log_sources( array $args ): array {
	return array( 'sources' => fatalguard_ability_sources() );
}

function fatalguard_mcp_tool_tail_log( array $args ) {
	$id = trim( (string) ( $args['source'] ?? '' ) );
	if ( '' === $id ) {
		return new WP_Error( 'fatalguard_mcp_bad_args', 'A "source" id is required (see list_log_sources).' );
	}
	return fatalguard_ability_read_log(
		array(
			'source' => $id,
			'lines'  => $args['max'] ?? 200,
			'filter' => (string) ( $args['q'] ?? '' ),
		)
	);
}

function fatalguard_mcp_tool_list_errors( array $args ) {
	return fatalguard_ability_list_errors( $args );
}

function fatalguard_mcp_tool_recent_fatals( array $args ): array {
	$res = fatalguard_ability_list_fatals( array( 'limit' => $args['max'] ?? 20 ) );
	return array(
		'count'  => count( $res['groups'] ),
		'total'  => $res['total'],
		'fatals' => $res['groups'],
	);
}

function fatalguard_mcp_tool_get_fatal( array $args ) {
	return fatalguard_ability_get_fatal( $args );
}

function fatalguard_mcp_tool_history( array $args ) {
	if ( ! function_exists( 'fatalguard_pro_history_query_core' ) ) {
		return new WP_Error( 'fatalguard_mcp_no_history', 'Error history is not available on this site.' );
	}
	return fatalguard_pro_history_query_core(
		array(
			'search'   => $args['search'] ?? '',
			'type'     => $args['type'] ?? null,
			'from'     => $args['from'] ?? '',
			'to'       => $args['to'] ?? '',
			'group'    => $args['group'] ?? false,
			'page'     => $args['page'] ?? 1,
			'per_page' => $args['per_page'] ?? 50,
		)
	);
}
