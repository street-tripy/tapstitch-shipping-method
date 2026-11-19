<?php
/**
 * REST test surface.
 *
 * Read-only diagnostics so the plugin can be verified on the live server without
 * a browser session at checkout (this store renders a maintenance page for
 * unauthenticated cart visits, and Store API POST is 401 with Basic auth).
 *
 *   GET /tapstitch-sm/v1/status    cache + snapshot + zones + provider report
 *   GET /tapstitch-sm/v1/selftest  the offline maths assertions, run on their PHP
 *   GET /tapstitch-sm/v1/probe     one live fetch against the rate endpoint
 *   GET /tapstitch-sm/v1/preview   what a given cart would be charged in a zone
 *   GET /tapstitch-sm/v1/debug     environment truth: theme gate, zone rows, seed file
 *   GET /tapstitch-sm/v1/calc      prices a synthetic package like checkout does
 *   POST /tapstitch-sm/v1/refresh  re-pull rates for every zone (cache writes only)
 *
 * All routes need manage_woocommerce. Nothing here writes products, orders,
 * zones or shipping settings.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_REST {

	const NS = 'tapstitch-sm/v1';

	/**
	 * Hook entry.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Writing routes are gated separately from the read-only ones.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Register the routes.
	 */
	public static function routes() {
		// A fatal error inside these routes is reported by WordPress as a blank
		// "critical error" page. Recording it lets /debug name the exact file and line.
		update_option( 'tapstitch_sm_last_error', array( 'at' => time(), 'route' => 'requested' ), false );
		add_action( 'shutdown', array( __CLASS__, 'capture_fatal' ) );

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'callback'            => array( __CLASS__, 'rest_status' ),
			)
		);

		register_rest_route(
			self::NS,
			'/selftest',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'callback'            => array( __CLASS__, 'rest_selftest' ),
			)
		);

		register_rest_route(
			self::NS,
			'/probe',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'country' => array( 'type' => 'string', 'default' => 'US' ),
					'slug'    => array( 'type' => 'string', 'default' => 'hoodies' ),
				),
				'callback'            => array( __CLASS__, 'rest_probe' ),
			)
		);

		register_rest_route(
			self::NS,
			'/preview',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'zone'    => array( 'type' => 'integer', 'default' => 0 ),
					'country' => array( 'type' => 'string', 'default' => 'US' ),
					'items'   => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_preview' ),
			)
		);

		register_rest_route(
			self::NS,
			'/refresh',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'callback'            => array( __CLASS__, 'rest_refresh' ),
			)
		);

		register_rest_route(
			self::NS,
			'/debug',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'callback'            => array( __CLASS__, 'rest_debug' ),
			)
		);

		register_rest_route(
			self::NS,
			'/calc',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'country'  => array( 'type' => 'string', 'default' => 'US' ),
					'state'    => array( 'type' => 'string', 'default' => '' ),
					'postcode' => array( 'type' => 'string', 'default' => '' ),
					'city'     => array( 'type' => 'string', 'default' => '' ),
					'items'    => array( 'type' => 'string', 'default' => '12:1' ),
				),
				'callback'            => array( __CLASS__, 'rest_calc' ),
			)
		);

		register_rest_route(
			self::NS,
			'/breakdown',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'zone'     => array( 'type' => 'integer', 'default' => 1 ),
					'country'  => array( 'type' => 'string', 'default' => 'US' ),
					'state'    => array( 'type' => 'string', 'default' => 'NY' ),
					'postcode' => array( 'type' => 'string', 'default' => '10001' ),
					'items'    => array( 'type' => 'string', 'default' => '12:1' ),
				),
				'callback'            => array( __CLASS__, 'rest_breakdown' ),
			)
		);

		register_rest_route(
			self::NS,
			'/shipping-classes',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'classes' => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_shipping_classes' ),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'block_no_quote' => array( 'type' => 'string', 'default' => '' ),
					'message'        => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_settings' ),
			)
		);
	}

	/**
	 * GET /status
	 */
	public static function rest_status() {
		$sets = TSM_Rates::zone_sets();
		foreach ( $sets as $i => $set ) {
			$method = TSM_Admin::method_for_zone( (int) $set['zone'] );
			$sets[ $i ]['instance']    = $method ? (int) $method->instance_id : 0;
			$sets[ $i ]['mode']        = $method ? $method->get_option( 'mode' ) : '';
			$sets[ $i ]['handling']    = $method ? $method->get_option( 'handling' ) : '';
			$sets[ $i ]['bound']       = $method ? $method->get_bound() : '';
			$sets[ $i ]['price_by']    = $method ? $method->price_mode() : '';
			$sets[ $i ]['round']       = $method ? $method->round_mode() : '';
			$sets[ $i ]['zone_methods'] = $method ? $method->zone_method_ids() : array();
			$sets[ $i ]['rows_cached'] = 0;
			if ( $method ) {
				$lookup = TSM_Rates::lookup(
					empty( $set['countries'] ) ? array( 'US' ) : $set['countries'],
					$set['slugs'],
					$set['lane'],
					$set['fulfillment']
				);
				$sets[ $i ]['rows_cached'] = count( $lookup['rows'] );
				$sets[ $i ]['source']      = $lookup['source'];
			}
		}

		return rest_ensure_response(
			array(
				'ok'            => true,
				'php'           => PHP_VERSION,
				'wc'            => get_option( 'woocommerce_version', '' ),
				'status'        => TSM_Rates::status(),
				'cron_next'     => wp_next_scheduled( 'tapstitch_sm_refresh' ),
				'zones'         => $sets,
				'classes'       => TSM_Admin::product_classes(),
				'guard'         => class_exists( 'TSM_Guard' ) ? TSM_Guard::state() : array( 'missing' => true ),
				'providers'     => TSM_Admin::method_for_zone( isset( $sets[0]['zone'] ) ? (int) $sets[0]['zone'] : 0 )
					? TSM_Provider::report( TSM_Admin::method_for_zone( (int) $sets[0]['zone'] ) )
					: array(),
			)
		);
	}

	/**
	 * GET /selftest
	 */
	public static function rest_selftest() {
		$results = TSM_Selftest::run();
		$summary = TSM_Selftest::summary();
		return rest_ensure_response(
			array(
				'ok'    => 0 === (int) $summary['failed'],
				'php'   => PHP_VERSION,
				'summary' => $summary,
				'cases' => $results,
			)
		);
	}

	/**
	 * GET /probe?country=US&slug=hoodies
	 */
	public static function rest_probe() {
		$started = microtime( true );
		$res     = TSM_API::fetch( array( 'US' ), array( 'hoodies', 'sweatpants', 't-shirts' ), array( 'timeout' => 10 ) );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return rest_ensure_response(
				array(
					'ok'        => false,
					'error'     => $res->get_error_code() . ': ' . $res->get_error_message(),
					'endpoint'  => TSM_API::endpoint(),
					'ms'        => $ms,
				)
			);
		}

		$rows = array();
		foreach ( (array) $res as $row ) {
			$rows[] = array(
				'country'  => $row['country'],
				'category' => $row['slug'],
				'line'     => $row['line'],
				'from'     => $row['fulfillment'],
				'first'    => $row['first'],
				'extra'    => $row['add_min'] . '-' . $row['add_max'],
				'days'     => $row['time_min'] . '-' . $row['time_max'],
				'grams'    => $row['weight'],
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'endpoint' => TSM_API::endpoint(),
				'ms'       => $ms,
				'rows'     => count( $rows ),
				'detail'   => $rows,
			)
		);
	}

	/**
	 * GET /preview?zone=1&country=DE&items=12:1,15:2
	 */
	public static function rest_preview( $request ) {
		$zone    = (int) $request->get_param( 'zone' );
		$country = strtoupper( (string) $request->get_param( 'country' ) );
		$items   = (string) $request->get_param( 'items' );

		$method = TSM_Admin::method_for_zone( $zone );
		if ( ! $method ) {
			return rest_ensure_response(
				array(
					'ok'     => false,
					'error'  => 'no tapstitch_shipping instance in zone ' . $zone,
					'zones'  => array_keys( TSM_Rates::zone_sets() ),
				)
			);
		}

		// Items arrive as "12:1,15:2"; preview_cart reads "product_id qty" per line.
		$lines_in = str_replace( array( ':', ',' ), array( ' ', "\n" ), $items );
		$lines    = TSM_Admin::preview_cart( $method, $lines_in, $country );

		$sum   = 0.0;
		foreach ( $lines as $l ) {
			$sum += (float) str_replace( array( '$' ), '', $l['cost'] );
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'zone'      => $zone,
				'instance'  => (int) $method->instance_id,
				'country'   => $country,
				'lane'      => $method->get_option( 'lane' ),
				'from'      => $method->get_option( 'fulfillment' ),
				'mode'      => $method->get_option( 'mode' ),
				'price_by'  => $method->price_mode(),
				'bound'     => $method->get_bound(),
				'handling'  => $method->get_option( 'handling' ),
				'lines'     => $lines,
				'total'     => round( $sum, 2 ),
			)
		);
	}

	/**
	 * POST /refresh - cache only.
	 */
	public static function rest_refresh() {
		$sets = TSM_Rates::zone_sets();
		$out  = array();
		foreach ( $sets as $set ) {
			$slugs = $set['slugs'];
			if ( empty( $slugs ) ) {
				continue;
			}
			$countries = empty( $set['countries'] ) ? TSM_Rates::all_countries() : $set['countries'];
			$res       = TSM_API::fetch( $countries, $slugs );
			if ( is_wp_error( $res ) ) {
				$out[] = array( 'zone' => $set['zone'], 'ok' => false, 'error' => $res->get_error_message() );
				continue;
			}
			$res = TSM_Rates::filter( $res, $set['lane'], $set['fulfillment'] );
			if ( empty( $res ) ) {
				$out[] = array( 'zone' => $set['zone'], 'ok' => false, 'error' => 'no rows for this lane/fulfilment' );
				continue;
			}
			$key = TSM_Rates::cache_key( $countries, $slugs, $set['lane'], $set['fulfillment'] );
			set_transient( $key, $res, TSM_Rates::TTL );
			TSM_Rates::save_stale( $res );
			update_option( 'tapstitch_sm_last_fetch', time() );
			$out[] = array(
				'zone'      => $set['zone'],
				'ok'        => true,
				'rows'      => count( $res ),
				'countries' => count( $countries ),
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'results' => $out,
				'status'  => TSM_Rates::status(),
			)
		);
	}

	/**
	 * Records the last fatal error (PHP fatals are not catchable, but shutdown runs).
	 */
	public static function capture_fatal() {
		$e = error_get_last();
		if ( ! $e ) {
			return;
		}
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! in_array( (int) $e['type'], $fatal, true ) ) {
			return;
		}
		update_option(
			'tapstitch_sm_last_error',
			array(
				'at'      => time(),
				'type'    => (int) $e['type'],
				'message' => (string) $e['message'],
				'file'    => (string) $e['file'],
				'line'    => (int) $e['line'],
			),
			false
		);
	}

	/**
	 * GET /debug - what the live server actually says about shipping.
	 *
	 * WooCommerce decides whether shipping exists at all from wc_shipping_enabled(),
	 * which by default is "is the active theme named woocommerce". That single boolean
	 * makes every rate disappear at checkout, so it is reported here, along with the
	 * zone/method rows and the seed file.
	 */
	public static function rest_debug() {
		global $wpdb;

		$seed  = TSM_PATH . 'data/seed-rates.json';
		$raw   = file_exists( $seed ) ? @file_get_contents( $seed ) : false; // phpcs:ignore
		$json  = ( is_string( $raw ) ) ? json_decode( $raw, true ) : null;
		$seedr = TSM_Rates::seed_rows();

		$zone_ids = array( 0 );
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			foreach ( (array) WC_Shipping_Zones::get_zones() as $zd ) {
				$zone_ids[] = isset( $zd['id'] ) ? (int) $zd['id'] : 0;
			}
		}

		$zones = array();
		foreach ( array_unique( $zone_ids ) as $zid ) {
			$methods   = array();
			$locations = array();
			foreach ( TSM_Rates::zone_method_rows( $zid ) as $row ) {
				$methods[] = $row['method_id'] . '#' . $row['instance_id'] . ( $row['enabled'] ? '' : ' (disabled)' );
			}
			if ( isset( $wpdb ) ) {
				$table = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
				$locs  = $wpdb->get_results( $wpdb->prepare( "SELECT location_code, location_type FROM {$table} WHERE zone_id = %d", $zid ) ); // phpcs:ignore
				foreach ( (array) $locs as $loc ) {
					$locations[] = $loc->location_type . ':' . $loc->location_code;
				}
			}
			$zones[] = array(
				'zone'      => $zid,
				'methods'   => $methods,
				'locations' => $locations,
			);
		}

		$shipping = class_exists( 'WC_Shipping' ) ? WC()->shipping() : null;

		return rest_ensure_response(
			array(
				'ok'                    => true,
				'php'                   => PHP_VERSION,
				'wc'                    => get_option( 'woocommerce_version', '' ),
				'current_theme'         => get_option( 'current_theme' ),
				'template'              => get_option( 'template' ),
				'stylesheet'            => get_option( 'stylesheet' ),
				'wc_shipping_enabled'   => function_exists( 'wc_shipping_enabled' ) ? wc_shipping_enabled() : 'function-missing',
				'shipping_object'       => $shipping ? get_class( $shipping ) : 'none',
				'shipping_object_enabled' => $shipping ? (bool) $shipping->enabled : false,
				'enable_shipping_calc'  => get_option( 'woocommerce_enable_shipping_calc' ),
				'ship_to_destination'   => get_option( 'woocommerce_ship_to_destination' ),
				'shipping_debug_mode'   => get_option( 'woocommerce_shipping_debug_mode' ),
				'shipping_countries'    => function_exists( 'WC' ) && WC()->countries ? count( array_keys( WC()->countries->get_shipping_countries() ) ) : 0,
				'registered_methods'    => $shipping ? array_keys( $shipping->get_shipping_method_class_names() ) : array(),
				'zones'                 => $zones,
				'classes'               => TSM_Admin::product_classes(),
				'seed'                  => array(
					'path'   => $seed,
					'exists' => file_exists( $seed ),
					'size'   => file_exists( $seed ) ? (int) filesize( $seed ) : 0,
					'json'   => is_array( $json ) ? 'ok' : ( function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'decode-failed' ),
					'rows'   => ( is_array( $json ) && isset( $json['rows'] ) ) ? count( $json['rows'] ) : 0,
					'valid'  => count( $seedr ),
					'first'  => count( $seedr ) ? $seedr[0] : null,
					'diag'   => self::seed_diag( $json ),
					'trace'  => TSM_Rates::seed_trace(),
				),
				'open_basedir'          => (string) ini_get( 'open_basedir' ),
				'snapshot'            => TSM_Rates::status(),
				'last_error'          => get_option( 'tapstitch_sm_last_error', array() ),
				'log_dir'             => defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '',
				'wc_logs'             => self::log_files(),
			)
		);
	}

	/**
	 * The 5 newest WooCommerce status log files (names only).
	 *
	 * @return string[]
	 */
	public static function log_files() {
		if ( ! defined( 'WC_LOG_DIR' ) || ! is_dir( WC_LOG_DIR ) ) {
			return array();
		}
		$files = glob( WC_LOG_DIR . '/*.log' );
		if ( ! $files ) {
			return array();
		}
		usort(
			$files,
			function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);
		$out = array();
		foreach ( array_slice( $files, 0, 5 ) as $f ) {
			$out[] = basename( $f ) . ' (' . (int) filesize( $f ) . ' bytes)';
		}
		return $out;
	}

	/**
	 * Why the shipped seed file does (or does not) yield usable rows.
	 *
	 * @param mixed $json Decoded seed file.
	 * @return array
	 */
	public static function seed_diag( $json ) {
		$rows  = ( is_array( $json ) && isset( $json['rows'] ) ) ? array_values( $json['rows'] ) : array();
		$first = $rows ? $rows[0] : null;
		$arr   = is_array( $first ) ? $first : null;

		return array(
			'raw'        => count( $rows ),
			'first_type' => is_object( $first ) ? 'object' : ( is_array( $first ) ? 'array' : gettype( $first ) ),
			'keys'       => is_array( $first ) ? array_keys( $first ) : ( ( is_object( $first ) ) ? array_keys( get_object_vars( $first ) ) : array() ),
			'country'    => null !== $arr && isset( $arr['country'] ) ? $arr['country'] : null,
			'slug'       => null !== $arr && isset( $arr['slug'] ) ? $arr['slug'] : null,
			'line'       => null !== $arr && isset( $arr['line'] ) ? $arr['line'] : null,
			'cost'       => null !== $arr && isset( $arr['first'] ) ? $arr['first'] : null,
			'valid'      => count( TSM_Rates::filter_valid( $rows ) ),
		);
	}

	/**
	 * GET /calc?country=US&state=NY&postcode=10001&items=12:1
	 *
	 * Prices a synthetic package the way checkout does, without constructing the
	 * other plugins' methods: the zone is matched, its method rows read from the
	 * database, and only core Flat Rate / Free Shipping / Local Pickup and this
	 * plugin's own method are asked for rates.
	 */
	public static function rest_calc( $request ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! function_exists( 'wc_get_product' ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'woocommerce not loaded' ) );
		}

		$country  = strtoupper( (string) $request->get_param( 'country' ) );
		$state    = (string) $request->get_param( 'state' );
		$postcode = (string) $request->get_param( 'postcode' );
		$city     = (string) $request->get_param( 'city' );

		$contents = array();
		$weight   = 0.0;
		$cost     = 0.0;
		$class    = '';
		$missing  = array();

		foreach ( preg_split( '/[,\s]+/', (string) $request->get_param( 'items' ) ) as $pair ) {
			$bits = explode( ':', $pair );
			if ( ! isset( $bits[0] ) || ! is_numeric( $bits[0] ) ) {
				continue;
			}
			$id  = (int) $bits[0];
			$qty = ( isset( $bits[1] ) && is_numeric( $bits[1] ) ) ? max( 1, (int) $bits[1] ) : 1;
			$product = wc_get_product( $id );
			if ( ! $product ) {
				$missing[] = $id;
				continue;
			}
			if ( $product->is_type( 'variation' ) ) {
				$contents[ 'var-' . $id ] = array(
					'product_id'    => (int) $product->get_parent_id(),
					'variation_id'  => $id,
					'quantity'      => $qty,
					'line_total'    => (float) $product->get_price() * $qty,
					'line_subtotal' => (float) $product->get_price() * $qty,
					'line_tax'      => array(),
					// Core methods read the product object itself: Flat Rate calls
					// $values['data']->needs_shipping(). Without it, /calc fatals.
					'data'          => $product,
				);
			} else {
				$contents[ 'prd-' . $id ] = array(
					'product_id'    => $id,
					'variation_id'  => 0,
					'quantity'      => $qty,
					'line_total'    => (float) $product->get_price() * $qty,
					'line_subtotal' => (float) $product->get_price() * $qty,
					'line_tax'      => array(),
					'data'          => $product,
				);
			}
			$weight += (float) $product->get_weight() * $qty;
			$cost   += (float) $product->get_price() * $qty;
			$c       = (string) $product->get_shipping_class();
			if ( '' !== $c ) {
				$class = $c;
			}
		}

		$package = array(
			'id'                        => 'tsm-calc',
			'offset'                    => 0,
			'weight'                    => $weight,
			'volume'                    => 0,
			'length'                    => 0,
			'width'                     => 0,
			'height'                    => 0,
			'contents'                  => $contents,
			'contents_cost'             => $cost,
			'accessories'               => array(),
			'shipping_class'            => $class,
			'destination'               => array(
				'country'  => $country,
				'state'    => $state,
				'postcode' => $postcode,
				'city'     => $city,
				'address'  => '',
				'address_2' => '',
			),
			'needs_shipping'            => true,
			'shipping_required'         => true,
			'shipping_calculation_type' => 'total',
		);

		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );

		$report = array();
		foreach ( TSM_Rates::zone_method_rows( (int) $zone->get_id() ) as $row ) {
			$entry = array(
				'method'   => $row['method_id'],
				'instance' => $row['instance_id'],
				'enabled'  => $row['enabled'],
				'rates'    => array(),
				'note'     => '',
			);
			if ( ! $row['enabled'] ) {
				$entry['note'] = 'disabled in zone';
			} else {
				$allowed = array(
					'flat_rate'           => 'WC_Shipping_Flat_Rate',
					'free_shipping'       => 'WC_Shipping_Free_Shipping',
					'local_pickup'        => 'WC_Shipping_Local_Pickup',
					'tapstitch_shipping'  => 'WC_Shipping_Tapstitch',
				);
				if ( ! isset( $allowed[ $row['method_id'] ] ) ) {
					$entry['note'] = 'not constructed here (third-party method)';
				} elseif ( ! class_exists( $allowed[ $row['method_id'] ] ) ) {
					$entry['note'] = 'class missing';
				} else {
					$method = new $allowed[ $row['method_id'] ]( $row['instance_id'] );
					$rates  = $method->get_rates_for_package( $package );
					if ( ! is_array( $rates ) ) {
						$entry['note'] = 'no rates returned';
					}
					foreach ( (array) $rates as $key => $rate ) {
						$meta = array();
						// WC_Shipping_Rate stores meta as a plain key => value map and has
						// no get_meta() - that is the accessor in 11.3.
						if ( method_exists( $rate, 'get_meta_data' ) ) {
							foreach ( (array) $rate->get_meta_data() as $k2 => $v2 ) {
								$meta[ (string) $k2 ] = (string) $v2;
							}
						}
						$entry['rates'][] = array(
							'key'       => (string) $key,
							'id'        => (string) $rate->get_id(),
							'label'     => (string) $rate->get_label(),
							'cost'      => (float) $rate->get_cost(),
							'tax'       => (float) array_sum( (array) $rate->get_taxes() ),
							'providers' => isset( $meta['tapstitch_providers'] ) ? $meta['tapstitch_providers'] : '',
							'detail'    => isset( $meta['tapstitch_rate_detail'] ) ? $meta['tapstitch_rate_detail'] : '',
						);
					}
				}
			}
			$report[] = $entry;
		}

		return rest_ensure_response(
			array(
				'ok'             => true,
				'enabled_gate'   => function_exists( 'wc_shipping_enabled' ) ? wc_shipping_enabled() : 'function-missing',
				'zone'           => (int) $zone->get_id(),
				'zone_name'      => TSM_Rates::zone_name( $zone ),
				'destination'    => $package['destination'],
				'weight'         => $weight,
				'subtotal'       => round( $cost, 2 ),
				'shipping_class' => $class,
				'missing'        => $missing,
				'methods'        => $report,
			)
		);
	}

	/**
	 * POST /settings - the two site-wide switches the guard uses.
	 *
	 *   block_no_quote = yes|no   refuse a sale when nothing can ship the cart
	 *   message        = text     what the customer is told
	 *
	 * manage_woocommerce only. Nothing else on the store is touched by this route.
	 */
	public static function rest_settings( $request ) {
		$block = strtolower( trim( (string) $request->get_param( 'block_no_quote' ) ) );
		if ( in_array( $block, array( 'yes', 'no' ), true ) ) {
			update_option( 'tapstitch_sm_block_no_quote', $block );
		}
		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' !== $message ) {
			update_option( 'tapstitch_sm_no_quote_message', wc_clean( $message ) );
		}

		return rest_ensure_response(
			array(
				'ok'            => true,
				'block_no_quote' => get_option( 'tapstitch_sm_block_no_quote', 'yes' ),
				'message'       => get_option( 'tapstitch_sm_no_quote_message', '' ),
			)
		);
	}

	/**
	 * POST /shipping-classes?classes=...
	 *
	 * Creates the product_shipping_class terms the provider mapping needs, and reports every
	 * class with its id. This is the one writing route in the plugin, and it is
	 * manage_woocommerce-gated for a reason: WooCommerce's REST API has no shipping-class
	 * route (measured 2026-11-19: /wp/v2/product_shipping_class is 404 and v3 has none),
	 * and a product's class is written as shipping_class_id - an id that has to exist first.
	 * Terms are never deleted by this route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_shipping_classes( $request ) {
		if ( ! taxonomy_exists( 'product_shipping_class' ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'product_shipping_class taxonomy not found' ) );
		}

		$results = array();
		$pairs   = preg_split( '/[,\s]+/', (string) $request->get_param( 'classes' ) );

		foreach ( (array) $pairs as $pair ) {
			$bits = explode( '=', (string) $pair, 2 );
			$slug = sanitize_title( $bits[0] );
			$name = ( isset( $bits[1] ) && '' !== trim( $bits[1] ) ) ? wc_clean( $bits[1] ) : $bits[0];
			if ( '' === $slug ) {
				continue;
			}

			$existing = term_exists( $slug, 'product_shipping_class' );
			if ( $existing && ! is_wp_error( $existing ) ) {
				$results[] = array(
					'slug'  => $slug,
					'id'    => (int) $existing['term_id'],
					'state' => 'existing',
				);
				continue;
			}

			$made = wp_insert_term( $name, 'product_shipping_class', array( 'slug' => $slug ) );
			if ( is_wp_error( $made ) ) {
				$results[] = array(
					'slug'  => $slug,
					'state' => 'error',
					'note'  => $made->get_error_message(),
				);
				continue;
			}
			$results[] = array(
				'slug'  => $slug,
				'id'    => (int) $made['term_id'],
				'state' => 'created',
			);
		}

		$all = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( (array) $terms as $t ) {
				$all[] = $t->slug . '#' . (int) $t->term_id;
			}
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'results'  => $results,
				'classes'  => $all,
			)
		);
	}

	/**
	 * GET /breakdown?zone=1&country=US&items=12:2,15:1
	 *
	 * Proves the admin-only sub-total line end to end: prices a synthetic cart, reads the
	 * breakdown stored on the rate, and renders the markup the shipping template prints at
	 * woocommerce_after_shipping_rate - capability gate included, so the caller sees exactly
	 * what an authorised user gets and what a customer gets (nothing).
	 */
	public static function rest_breakdown( $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'woocommerce not loaded' ) );
		}

		$built  = self::package_for(
			(string) $request->get_param( 'items' ),
			strtoupper( (string) $request->get_param( 'country' ) ),
			(string) $request->get_param( 'state' ),
			(string) $request->get_param( 'postcode' ),
			(string) $request->get_param( 'city' )
		);
		$method = TSM_Admin::method_for_zone( (int) $request->get_param( 'zone' ) );

		if ( ! $method ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'error'   => 'no tapstitch_shipping instance in zone ' . (int) $request->get_param( 'zone' ),
					'missing' => $built['missing'],
				)
			);
		}

		$rates = $method->get_rates_for_package( $built['package'] );
		$rate  = is_array( $rates ) ? reset( $rates ) : null;
		$lines = is_object( $rate ) ? TSM_Rates::meta_breakdown( $rate ) : array();
		$html  = '';
		if ( is_object( $rate ) ) {
			ob_start();
			$method->after_rate_breakdown( $rate, 0 );
			$html = (string) ob_get_clean();
		}

		$current = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;

		return rest_ensure_response(
			array(
				'ok'                  => true,
				'user'                => $current ? $current->user_login : '',
				'can_manage_woo'      => current_user_can( 'manage_woocommerce' ),
				'see_breakdown'       => $method->user_may_see_breakdown(),
				'setting'             => $method->get_option( 'admin_breakdown' ),
				'capability_required' => $method->get_option( 'admin_breakdown_cap' ),
				'label'               => is_object( $rate ) ? (string) $rate->get_label() : '',
				'cost'                => is_object( $rate ) ? (float) $rate->get_cost() : 0,
				'lines'               => $lines,
				'html'                => $html,
				'items_not_found'     => $built['missing'],
			)
		);
	}

	/**
	 * A synthetic cart package, in the shape WooCommerce hands to shipping methods.
	 *
	 * Contents carry 'data' => the product: core methods read it (Flat Rate calls
	 * $values['data']->needs_shipping()).
	 *
	 * @param string $items    "12:2,15:1".
	 * @param string $country  Country code.
	 * @param string $state    State code.
	 * @param string $postcode Postcode.
	 * @param string $city     City.
	 * @return array package|missing|weight|subtotal
	 */
	public static function package_for( $items, $country = 'US', $state = '', $postcode = '', $city = '' ) {
		$contents = array();
		$weight   = 0.0;
		$cost     = 0.0;
		$class    = '';
		$missing  = array();

		foreach ( preg_split( '/[,\s]+/', (string) $items ) as $pair ) {
			$bits = explode( ':', $pair );
			if ( ! isset( $bits[0] ) || ! is_numeric( $bits[0] ) ) {
				continue;
			}
			$id      = (int) $bits[0];
			$qty     = ( isset( $bits[1] ) && is_numeric( $bits[1] ) ) ? max( 1, (int) $bits[1] ) : 1;
			$product = wc_get_product( $id );
			if ( ! $product ) {
				$missing[] = $id;
				continue;
			}

			$contents[ ( $product->is_type( 'variation' ) ? 'var-' : 'prd-' ) . $id ] = array(
				'product_id'    => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $id,
				'variation_id'  => $product->is_type( 'variation' ) ? $id : 0,
				'quantity'      => $qty,
				'line_total'    => (float) $product->get_price() * $qty,
				'line_subtotal' => (float) $product->get_price() * $qty,
				'line_tax'      => array(),
				'data'          => $product,
			);
			$weight += (float) $product->get_weight() * $qty;
			$cost   += (float) $product->get_price() * $qty;
			$c       = (string) $product->get_shipping_class();
			if ( '' !== $c ) {
				$class = $c;
			}
		}

		return array(
			'package'  => array(
				'id'                        => 'tsm-synthetic',
				'offset'                    => 0,
				'weight'                    => $weight,
				'volume'                    => 0,
				'length'                    => 0,
				'width'                     => 0,
				'height'                    => 0,
				'contents'                  => $contents,
				'contents_cost'             => $cost,
				'accessories'               => array(),
				'shipping_class'            => $class,
				'destination'               => array(
					'country'   => strtoupper( (string) $country ),
					'state'     => (string) $state,
					'postcode'  => (string) $postcode,
					'city'      => (string) $city,
					'address'   => '',
					'address_2' => '',
				),
				'needs_shipping'            => true,
				'shipping_required'         => true,
				'shipping_calculation_type' => 'total',
			),
			'missing'  => $missing,
			'weight'   => $weight,
			'subtotal' => $cost,
		);
	}
}

TSM_REST::register();
