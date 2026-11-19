<?php
/**
 * Stop a sale when nothing can ship it.
 *
 * Today WooCommerce shows a notice and, in the normal flow, the customer cannot place the
 * order - but that relies on the theme template rendering the notice, and WooPayments'
 * express checkout (Apple Pay / Google Pay) runs its own flow that can complete an order
 * without the shipping step being validated. This class makes the block a rule of the
 * store, not a side-effect of a template:
 *
 *   - the order is refused server-side when the cart needs shipping and there is no
 *     shipping price for it (no method, nothing chosen, or nothing quoted);
 *   - express checkout buttons are removed on the cart and product pages in the same
 *     situation;
 *   - the customer sees one neutral sentence, and nothing else.
 *
 * Switches are site-wide options, not per-zone, because a sale is a single event:
 *   tapstitch_sm_block_no_quote  (yes/no, default yes)
 *   tapstitch_sm_no_quote_message (text)
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_Guard {

	const BLOCK_KEY  = 'tapstitch_sm_block_no_quote';
	const MESSAGE_KEY = 'tapstitch_sm_no_quote_message';

	const DEFAULT_MESSAGE = 'We can\'t work out shipping for these items at this address. Please update your address, or come back to the cart and try again.';

	/**
	 * Hook entry.
	 */
	public static function register() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'process' ), 5 );
		add_action( 'woocommerce_checkout_order_review', array( __CLASS__, 'notice' ), 5 );
		add_filter( 'wcpay_show_checkout_on_cart_page', array( __CLASS__, 'express_off' ), 20, 1 );
		add_filter( 'wcpay_show_checkout_on_product_page', array( __CLASS__, 'express_off' ), 20, 1 );
		add_filter( 'wcpay_show_express_checkout_button', array( __CLASS__, 'express_off' ), 20, 1 );
	}

	/**
	 * Is the block on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'no' !== get_option( self::BLOCK_KEY, 'yes' );
	}

	/**
	 * The sentence the customer sees.
	 *
	 * @return string
	 */
	public static function message() {
		$text = (string) get_option( self::MESSAGE_KEY, '' );
		if ( '' === trim( $text ) ) {
			$text = __( self::DEFAULT_MESSAGE, 'tapstitch-shipping-method' );
		}
		return $text;
	}

	/**
	 * Refuse the order when shipping is needed and nothing prices it.
	 *
	 * Fires inside WooCommerce's checkout validation. Adding an error notice here is what
	 * stops the order being created - server-side, so it cannot be bypassed by the page.
	 */
	public static function process() {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$state = self::decide( WC()->cart->needs_shipping(), self::rates_per_package(), self::chosen_methods() );
		if ( 'block' !== $state['action'] ) {
			return;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
		self::log( 'checkout blocked: ' . $state['reason'] );
	}

	/**
	 * Same message in the order-review area, so it is visible where the customer is looking.
	 */
	public static function notice() {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$state = self::decide( WC()->cart->needs_shipping(), self::rates_per_package(), self::chosen_methods() );
		if ( 'block' === $state['action'] && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
	}

	/**
	 * Hide WooPayments express checkout when nothing can ship the cart.
	 *
	 * @param mixed $show Whatever WooPayments asked for.
	 * @return bool
	 */
	public static function express_off( $show = false ) {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $show;
		}
		$state = self::decide( WC()->cart->needs_shipping(), self::rates_per_package(), self::chosen_methods() );
		return ( 'block' === $state['action'] ) ? false : $show;
	}

	/**
	 * The decision, as a pure function so it can be tested offline.
	 *
	 * @param bool   $needs_shipping Does the cart need shipping at all.
	 * @param array  $rates          package index => list of available rates.
	 * @param array  $chosen         chosen shipping method ids, package order.
	 * @return array action allow|block, reason
	 */
	public static function decide( $needs_shipping, $rates, $chosen ) {
		if ( ! $needs_shipping ) {
			return array(
				'action' => 'allow',
				'reason' => 'no shipping needed',
			);
		}

		$packages = is_array( $rates ) ? $rates : array();
		if ( empty( $packages ) ) {
			return array(
				'action' => 'block',
				'reason' => 'no shipping package was built for this address',
			);
		}

		foreach ( array_values( $packages ) as $i => $list ) {
			$list = is_array( $list ) ? $list : array();
			if ( empty( $list ) ) {
				return array(
					'action' => 'block',
					'reason' => 'no shipping method quoted a price for package ' . $i,
				);
			}
			$pick = isset( $chosen[ $i ] ) ? (string) $chosen[ $i ] : '';
			if ( '' === $pick ) {
				return array(
					'action' => 'block',
					'reason' => 'no shipping method chosen for package ' . $i,
				);
			}
			if ( ! array_key_exists( $pick, $list ) ) {
				return array(
					'action' => 'block',
					'reason' => 'the chosen shipping method for package ' . $i . ' is not among the quoted prices',
				);
			}
		}

		return array(
			'action' => 'allow',
			'reason' => 'every package has a quoted price',
		);
	}

	/**
	 * Rates WooCommerce has quoted for the current cart, per package index.
	 *
	 * @return array
	 */
	public static function rates_per_package() {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $out;
		}
		$packages = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_shipping_packages() : array();
		foreach ( (array) $packages as $i => $package ) {
			$stored = WC()->session->get( 'shipping_for_package_' . $i );
			if ( is_array( $stored ) && isset( $stored['rates'] ) && is_array( $stored['rates'] ) ) {
				$out[ $i ] = array_keys( $stored['rates'] );
				continue;
			}
			$out[ $i ] = isset( $package['rates'] ) && is_array( $package['rates'] ) ? array_keys( $package['rates'] ) : array();
		}
		return $out;
	}

	/**
	 * Chosen shipping methods, in package order.
	 *
	 * @return string[]
	 */
	public static function chosen_methods() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		return is_array( $chosen ) ? $chosen : array();
	}

	/**
	 * Are the hooks actually wired in this request?
	 *
	 * The guard is only a store rule if its filters exist. register() returns early when the
	 * site switch is off, so this is the measurable difference between "guard on" and
	 * "guard switched off" - reported at /status as guard.wired.
	 *
	 * @return array
	 */
	public static function wired() {
		return array(
			'checkout_process' => (bool) has_action( 'woocommerce_checkout_process', array( __CLASS__, 'process' ) ),
			'order_review'     => (bool) has_action( 'woocommerce_checkout_order_review', array( __CLASS__, 'notice' ) ),
			'express_cart'     => (bool) has_filter( 'wcpay_show_checkout_on_cart_page', array( __CLASS__, 'express_off' ) ),
			'express_product'  => (bool) has_filter( 'wcpay_show_checkout_on_product_page', array( __CLASS__, 'express_off' ) ),
			'express_button'   => (bool) has_filter( 'wcpay_show_express_checkout_button', array( __CLASS__, 'express_off' ) ),
		);
	}

	/**
	 * @return array state of the current cart, for /status and the new Providers screen
	 */
	public static function state() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array(
				'action'   => 'allow',
				'reason'   => 'no cart in this context',
				'enabled'  => self::enabled(),
				'wired'    => self::wired(),
			);
		}
		$state = self::decide( WC()->cart->needs_shipping(), self::rates_per_package(), self::chosen_methods() );
		$state['enabled']  = self::enabled();
		$state['wired']    = self::wired();
		$state['message']  = self::message();
		$state['rates']    = self::rates_per_package();
		$state['chosen']   = self::chosen_methods();
		return $state;
	}

	/**
	 * @param string $message Log line.
	 */
	protected static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->warning( $message, array( 'source' => 'tapstitch-shipping-method' ) );
		}
	}
}
