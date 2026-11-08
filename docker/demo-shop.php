<?php
/**
 * Demo data for the FatalGuard MCP sandbox image (never installed on a real site).
 *
 * A small "shop" plugin with the bugs real plugins ship: the Docker build includes it
 * through `wp eval-file` so PHP itself writes the warnings into the error log and the
 * plugin's own fatal-error drop-in captures the crash. Nothing here is hand-made log text.
 *
 * Installed (inactive) as wp-content/plugins/demo-shop/demo-shop.php. The build loads it
 * with require (not eval-file, so log lines point at this file):
 *   DEMO_SHOP=warnings|fatal wp eval 'require WP_PLUGIN_DIR . "/demo-shop/demo-shop.php";'
 */

function demo_shop_cart_total( array $cart ) {
	$total = 0;
	foreach ( $cart['items'] as $item ) {           // "items" missing → warning
		$total += $item['price'] * $item['qty'];
	}
	return $total + $shipping;                       // undefined variable → warning
}

function demo_shop_coupon( $code ) {
	$coupons = array( 'WELCOME10' => 10 );
	return $coupons[ strtoupper( $code ) ];         // unknown coupon → warning
}

function demo_shop_export_orders() {
	ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 16 * 1024 * 1024 ) ); // a small host limit
	$rows = array();
	for ( $i = 0; ; $i++ ) {                         // no paging → out of memory (fatal)
		$rows[] = str_repeat( 'order-' . $i . ';', 64 );
	}
}

$demo_mode = getenv( 'DEMO_SHOP' ) ?: 'warnings';
if ( 'fatal' === $demo_mode ) {
	demo_shop_export_orders();
} else {
	for ( $i = 0; $i < 3; $i++ ) {
		demo_shop_cart_total( array() );
		demo_shop_coupon( 'SUMMER' . $i );
	}
	trigger_error( 'demo-shop: stock sync API timed out after 30s', E_USER_WARNING );
	_deprecated_function( 'demo_shop_legacy_price', '2.0.0', 'demo_shop_price' );
}
