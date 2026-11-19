<?php
/**
 * Plugin Name:          Tapstitch Shipping Method
 * Plugin URI:          https://github.com/street-tripy/tapstitch-shipping-method
 * Description:         WooCommerce shipping method that charges the shipping rates Tapstitch publishes, by destination country and product category, using Tapstitch's first-item + additional-item model. Cached rates, per shipping class, never free by accident.
 * Version:             1.3.2
 * Requires at least:   6.0
 * Requires PHP:        7.4
 * Author:              street-tripy
 * Author URI:          https://github.com/street-tripy
 * License:             GPL-2.0-or-later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         tapstitch-shipping-method
 * Domain Path:         /languages
 * WC requires at least: 6.0
 * WC tested up to:     11.3
 */

/**
 * Tapstitch Shipping Method
 *
 * WooCommerce shipping method that pulls Tapstitch's published shipping rates
 * (country + product category + lane) and charges them at checkout, per shipping
 * class, using Tapstitch's own "first item + additional per extra item" model.
 *
 * Rates are never fetched during checkout: checkout reads a cache. If the cache
 * is empty the plugin serves the last good snapshot, and failing that a configured
 * floor, so shipping is never silently free.
 *
 * @package tapstitch-shipping-method
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

define( 'TSM_VERSION', '1.3.2' );
define( 'TSM_FILE', __FILE__ );
define( 'TSM_PATH', plugin_dir_path( __FILE__ ) );
define( 'TSM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin bootstrap.
 */
final class Tapstitch_Shipping_Method {

	/** @var Tapstitch_Shipping_Method */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		require_once TSM_PATH . 'includes/class-tsm-rates.php';
		require_once TSM_PATH . 'includes/class-tsm-api.php';
		require_once TSM_PATH . 'includes/class-tsm-provider.php';
		require_once TSM_PATH . 'includes/class-tsm-suppliers.php';
		require_once TSM_PATH . 'includes/class-tsm-selftest.php';
		require_once TSM_PATH . 'includes/class-tsm-admin.php';
		require_once TSM_PATH . 'includes/class-tsm-rest.php';

		add_action( 'plugins_loaded', array( $this, 'load' ), 20 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		register_activation_hook( TSM_FILE, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Translations live in /languages (empty today; the text domain is declared
	 * so translations can be added without touching the code).
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'tapstitch-shipping-method', false, dirname( plugin_basename( TSM_FILE ) ) . '/languages' );
	}

	/**
	 * This plugin never reads or writes order tables, so it is compatible with
	 * WooCommerce's custom order tables (HPOS).
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TSM_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TSM_FILE, true );
		}
	}

	/**
	 * Only start when WooCommerce is present.
	 */
	public function load() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return;
		}

		require_once TSM_PATH . 'includes/class-wc-shipping-tapstitch.php';
		require_once TSM_PATH . 'includes/class-tsm-packages.php';

		// Package and rate control lives here, not in a method instance: WooCommerce builds
		// the cart's shipping packages before it constructs the methods that price them.
		TSM_Packages::register();

		// No shipping quote = no sale. Wired after the method is registered so the rates
		// the guard reads are the real ones.
		require_once TSM_PATH . 'includes/class-tsm-guard.php';
		TSM_Guard::register();

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_method' ) );
		add_action( 'woocommerce_update_options_shipping_tapstitch_shipping', array( $this, 'clear_cache' ), 10, 2 );
		add_action( 'woocommerce_shipping_zone_method_added', array( $this, 'clear_cache' ), 10, 3 );
		add_action( 'woocommerce_shipping_zone_method_updated', array( $this, 'clear_cache' ), 10, 3 );
		add_action( 'woocommerce_shipping_zone_updated', array( $this, 'clear_cache' ) );

		add_action( 'admin_init', array( 'TSM_Admin', 'register' ) );
		add_action( 'admin_menu', array( 'TSM_Admin', 'menu' ) );
		add_action( 'admin_footer', array( 'TSM_Admin', 'settings_inline_style' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( TSM_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );

		// Rate refresh.
		add_action( 'tapstitch_sm_refresh', array( 'TSM_Rates', 'cron_refresh' ) );
		if ( ! wp_next_scheduled( 'tapstitch_sm_refresh' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'tapstitch_sm_refresh' );
		}
	}

	/**
	 * @param array $methods Registered shipping methods.
	 * @return array
	 */
	public function register_method( $methods ) {
		$methods['tapstitch_shipping'] = 'WC_Shipping_Tapstitch';
		return $methods;
	}

	/**
	 * @param string[] $links Plugin row actions.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=tapstitch-shipping-method' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Tapstitch&nbsp;rates</a>' );
		return $links;
	}

	/**
	 * @param string[] $meta Existing links.
	 * @param string   $file Plugin file.
	 * @return string[]
	 */
	public function row_meta( $meta, $file ) {
		if ( plugin_basename( TSM_FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( admin_url( 'admin.php?page=tapstitch-shipping-method&tab=doctor' ) ) . '">Self-check</a>';
			$meta[] = '<a href="' . esc_url( admin_url( 'admin.php?page=tapstitch-shipping-method&tab=export' ) ) . '">Static rate export</a>';
		}
		return $meta;
	}

	/**
	 * Drop the rate cache so the next checkout re-reads settings.
	 */
	public function clear_cache() {
		delete_transient( 'tapstitch_sm_cache_index' );
		$index = get_option( 'tapstitch_sm_cache_index', array() );
		if ( is_array( $index ) ) {
			foreach ( $index as $key ) {
				delete_transient( $key );
			}
		}
		update_option( 'tapstitch_sm_cache_index', array() );
	}

	/**
	 * Seed the snapshot on activation so shipping works before the first fetch.
	 */
	public static function activate() {
		$seed = TSM_Rates::seed_rows();
		if ( ! empty( $seed ) ) {
			update_option( 'tapstitch_sm_stale_v1', array( 'fetched' => time(), 'rows' => $seed ) );
			update_option( 'tapstitch_sm_activated', time() );
		}
		if ( ! wp_next_scheduled( 'tapstitch_sm_refresh' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'tapstitch_sm_refresh' );
		}
	}
}

Tapstitch_Shipping_Method::instance();
