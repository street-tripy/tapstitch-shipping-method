<?php
/**
 * Package-level control: one package, one rate, one label.
 *
 * These three jobs have to be wired at plugin boot, not from inside a shipping-method
 * instance. WooCommerce builds the cart's shipping packages BEFORE it constructs the
 * methods that price them, so a method that registers its filters when it is constructed
 * is always one request too late - which is exactly what the live cart showed: the merge
 * rule was in place and the cart still displayed two shipments.
 *
 *   woocommerce_cart_shipping_packages (priority 20) - undo Printify's supplier split,
 *       so a destination is one shipment, not one shipment per supplier.
 *   woocommerce_load_shipping_methods (priority 20) - put this method back when a supplier
 *       plugin wipes the method list for the package it claims.
 *   woocommerce_package_rates (priority 100) - at checkout, show only this method's rate
 *       in the zones this method prices; everywhere else, change nothing.
 *
 * Settings are read from the zone's own instance, so each zone decides for itself.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_Packages {

	const NS = 'tapstitch-shipping-method';

	/**
	 * Hook entry.
	 */
	public static function register() {
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'merge' ), 20, 1 );
		add_action( 'woocommerce_load_shipping_methods', array( __CLASS__, 'ensure' ), 20, 1 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'only_this' ), 100, 2 );
	}

	/**
	 * One package per destination.
	 *
	 * @param array $packages Packages from the cart.
	 * @return array
	 */
	public static function merge( $packages ) {
		if ( ! is_array( $packages ) || count( $packages ) < 2 ) {
			return $packages;
		}
		if ( ! self::settings_flag( $packages, 'combine' ) || ! self::settings_flag( $packages, 'hide_other' ) ) {
			return $packages;
		}
		return TSM_Rates::merge_packages( $packages );
	}

	/**
	 * Make sure this method is loaded for this package.
	 *
	 * Printify's plugin calls unregister_shipping_methods() for the packages it claims and
	 * registers only itself, so in a cart containing Printify goods this method would not
	 * run at all and the customer would be quoted Printify's rate for everything.
	 *
	 * @param array $package Package being loaded.
	 */
	public static function ensure( $package = array() ) {
		if ( ! is_array( $package ) || empty( $package ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$found = self::instance_for_package( $package );
		if ( ! $found ) {
			return;
		}
		$shipping = WC()->shipping();
		if ( ! $shipping ) {
			return;
		}
		$loaded = $shipping->get_shipping_methods();
		if ( is_array( $loaded ) && isset( $loaded[ 'tapstitch_shipping' ] ) ) {
			return;
		}
		$shipping->register_shipping_method( new WC_Shipping_Tapstitch( (int) $found['instance_id'] ) );
	}

	/**
	 * At checkout, only this method's rate for the zones it prices.
	 *
	 * @param array $rates   Rates offered for this package.
	 * @param array $package Package.
	 * @return array
	 */
	public static function only_this( $rates, $package = array() ) {
		if ( ! is_array( $rates ) || ! $rates || ! is_array( $package ) ) {
			return $rates;
		}
		// Provider Shipping is pricing this zone: leave its rate alone. Two engines filtering
		// at the same priority cancel each other out, and the customer loses the merged rate
		// with the sub-totals.
		if ( self::registry_owns( self::zone_id_for( $package ) ) ) {
			return $rates;
		}
		// Shipping screens must keep showing every method.
		if ( is_admin() && ! defined( 'WC_DOING_AJAX' ) && ! wp_doing_ajax() ) {
			return $rates;
		}
		$found = self::instance_for_package( $package );
		if ( ! $found || 'yes' !== $found['hide_other'] ) {
			return $rates;
		}

		$keep = array();
		foreach ( $rates as $key => $rate ) {
			$id = is_object( $rate ) ? (string) $rate->get_id() : '';
			if ( 0 === strpos( $id, 'tapstitch_shipping_' . $found['instance_id'] ) ) {
				$keep[ $key ] = $rate;
			}
		}

		// Nothing of ours here (a zone this method does not price): change nothing.
		return $keep ? $keep : $rates;
	}

	/**
	 * The zone a method instance belongs to, read from WooCommerce's method table.
	 *
	 * `WC_Shipping_Method::get_zone_id()` does not exist on this WooCommerce version, and the
	 * accessor that does (the zone's method list) *constructs every method in the zone*, which is
	 * what runs a supplier plugin's constructor in the middle of a checkout. The table is the
	 * source of truth: read-only, and stable.
	 *
	 * @param int $instance_id Instance id.
	 * @return int zone id (0 when the instance is not in a zone)
	 */
	public static function zone_for_instance( $instance_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! (int) $instance_id ) {
			return 0;
		}

		$zone = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT zone_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE instance_id = %d AND method_id = %s LIMIT 1", // phpcs:ignore
				(int) $instance_id,
				'tapstitch_shipping'
			)
		);

		return (int) $zone;
	}

	/**
	 * Should this method's instance stay quiet for a package?
	 *
	 * @param int   $instance_id This method's zone instance.
	 * @param array $package     Package being priced.
	 * @return bool
	 */
	public static function defers_for( $instance_id, $package ) {
		$mode  = class_exists( 'PSR_Pipeline' ) ? PSR_Pipeline::mode() : '';
		$zones = class_exists( 'PSR_Registry' ) ? array_map( 'intval', array_keys( PSR_Registry::method_instances( 'psr_shipping' ) ) ) : array();

		return self::should_defer( $mode, $zones, self::zone_for_instance( (int) $instance_id ), ! empty( $package['psr_provider'] ) );
	}

	/**
	 * Is the provider registry the one that should own a zone's checkout rate?
	 *
	 * True only when provider-shipping is installed, its pipeline is Live, and the zone has a
	 * Provider Shipping instance. That is the handover: this plugin keeps working as the *rate
	 * source* the registry quotes through, and stops competing for the customer's screen.
	 *
	 * @param int $zone_id Zone id.
	 * @return bool
	 */
	public static function registry_owns( $zone_id ) {
		$mode  = class_exists( 'PSR_Pipeline' ) ? PSR_Pipeline::mode() : '';
		$zones = class_exists( 'PSR_Registry' ) ? array_keys( PSR_Registry::method_instances( 'psr_shipping' ) ) : array();

		return self::registry_owns_decision( $mode, array_map( 'intval', $zones ), (int) $zone_id );
	}

	/**
	 * The handover rule, as a pure function so the self-check can test it.
	 *
	 * @param string $mode     provider-shipping pipeline mode.
	 * @param int[]  $zones    zones that have a Provider Shipping instance.
	 * @param int    $zone_id  The zone asking.
	 * @return bool
	 */
	public static function registry_owns_decision( $mode, $zones, $zone_id ) {
		return 'live' === $mode && in_array( (int) $zone_id, array_map( 'intval', (array) $zones ), true );
	}

	/**
	 * Should this method stay quiet for a package?
	 *
	 * Yes when the registry owns the zone's checkout rate, and no when the package is one of the
	 * registry's provider sub-packages: the registry quotes apparel *through* this method, so
	 * deferring there is a deadlock and the cart ends with no price at all.
	 *
	 * @param string $mode      provider-shipping pipeline mode.
	 * @param int[]  $zones     zones with a Provider Shipping instance.
	 * @param int    $zone_id   The zone asking.
	 * @param bool   $is_driver True when this package is a registry sub-package (this method is the driver).
	 * @return bool
	 */
	public static function should_defer( $mode, $zones, $zone_id, $is_driver ) {
		return ! $is_driver && self::registry_owns_decision( $mode, $zones, $zone_id );
	}

	/**
	 * This method's instance that owns a package's zone, with the flags that matter.
	 *
	 * @param array $package Package, or a list of packages.
	 * @return array|false instance_id|zone|mode|combine|hide_other|supplier_claims
	 */
	public static function instance_for_package( $package ) {
		static $cache = array();

		$zone_id = self::zone_id_for( $package );
		$key     = (string) $zone_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$cache[ $key ] = false;

		foreach ( TSM_Rates::zone_method_rows( $zone_id ) as $row ) {
			if ( 'tapstitch_shipping' !== $row['method_id'] || ! $row['enabled'] ) {
				continue;
			}
			$method              = new WC_Shipping_Tapstitch( (int) $row['instance_id'] );
			$cache[ $key ]       = array(
				'instance_id'     => (int) $row['instance_id'],
				'zone'            => $zone_id,
				'mode'            => (string) $method->get_option( 'mode' ),
				'combine'         => (string) $method->get_option( 'combine' ),
				'hide_other'      => (string) $method->get_option( 'hide_other' ),
				'supplier_claims' => (string) $method->get_option( 'supplier_claims' ),
			);
			return $cache[ $key ];
		}

		return $cache[ $key ];
	}

	/**
	 * Zone a package (or a list of packages) belongs to.
	 *
	 * @param array $package Package, or list of packages.
	 * @return int
	 */
	protected static function zone_id_for( $package ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return 0;
		}
		$first = $package;
		if ( isset( $package['destination'] ) === false ) {
			$inner = reset( $package );
			if ( is_array( $inner ) ) {
				$first = $inner;
			}
		}
		$zone = WC_Shipping_Zones::get_zone_matching_package( (array) $first );
		return $zone ? (int) $zone->get_id() : 0;
	}

	/**
	 * Is a per-instance flag on for the zone(s) these packages belong to?
	 *
	 * @param array  $packages Packages.
	 * @param string $flag     Option name.
	 * @return bool
	 */
	protected static function settings_flag( $packages, $flag ) {
		foreach ( (array) $packages as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$found = self::instance_for_package( $package );
			if ( $found && 'yes' === $found[ $flag ] ) {
				return true;
			}
		}
		return false;
	}
}
