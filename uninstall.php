<?php
/**
 * Uninstall: remove this plugin's options and transients only.
 *
 * Never touches orders, products, shipping zones, zone methods, logs,
 * or any WooCommerce data. Everything is recoverable.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$keys = array(
	'tapstitch_sm_endpoint',
	'tapstitch_sm_stale_v1',
	'tapstitch_sm_cache_index',
	'tapstitch_sm_last_fetch',
	'tapstitch_sm_activated',
);

foreach ( $keys as $key ) {
	delete_option( $key );
	delete_site_option( $key );
}

$index = get_option( 'tapstitch_sm_cache_index', array() );
if ( is_array( $index ) ) {
	foreach ( $index as $transient ) {
		delete_transient( $transient );
		delete_site_transient( $transient );
	}
}

delete_transient( 'tapstitch_sm_rows_' );
delete_transient( 'tsm_notice' );

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'tapstitch_sm_refresh' );
}
