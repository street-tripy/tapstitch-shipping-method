<?php
/**
 * The store's OTHER suppliers, asked directly.
 *
 * Printify's and Printful's plugins decide "is this ours" from their own accounts, not
 * from tags on the product - and nothing on this store carries supplier meta. So the
 * only honest way to know which items in a cart belong to whom, and what each of them
 * costs to ship, is to ask the supplier at quote time, the same way their own plugins do.
 *
 * Nothing in this file can stop a checkout: a missing plugin, a dead endpoint, an
 * unauthenticated account or a changed response shape all return "no answer", and the
 * caller then prices those goods from the instance's own fallback - never from zero.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_Suppliers {

	const TTL       = 900;  // 15 minutes for a real answer - the same life Printify uses.
	const TTL_EMPTY = 60;   // 1 minute for "no answer", so a dead endpoint is tried again soon.

	const PRINTIFY_DIR = 'printify-for-woocommerce';
	const PRINTFUL_DIR = 'printful-shipping-for-woocommerce';

	/**
	 * Printify's live shipping cost for the items in this package, and the SKUs it claims.
	 *
	 * Endpoint and payload are exactly what Printify's own plugin posts:
	 *   POST https://api-prod.printify.com/v1/callbacks/woo/shipping?version=3.2
	 *        { "items": { SKU: {sku, product_id, parent_id, permalink, quantity} },
	 *          "country": "US", "state": "NY", "zip": "10001" }
	 * The answer names the SKUs Printify recognises and gives the cost of shipping the
	 * whole set, in cents, as "shipping_standart" (their spelling) and "shipping_express".
	 *
	 * @param array $package WooCommerce shipping package.
	 * @return array available|skus|standard|express|source
	 */
	public static function printify_quote( $package ) {
		$out = array(
			'available' => false,
			'skus'      => array(),
			'standard'  => null,
			'express'   => null,
			'source'    => 'none',
		);

		$items   = self::sku_items( $package );
		$address = self::address( $package );

		if ( empty( $items ) || empty( $address['country'] ) ) {
			$out['source'] = 'no items or no address';
			return $out;
		}

		$key = 'tsm_printify_' . md5( (string) json_encode( array( 'i' => $items, 'a' => $address ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$out['available'] = ! empty( $cached['skus'] );
			$out['skus']      = (array) $cached['skus'];
			$out['standard'] = isset( $cached['standard'] ) ? $cached['standard'] : null;
			$out['express']  = isset( $cached['express'] ) ? $cached['express'] : null;
			$out['source']    = $out['available'] ? 'cache' : 'cache (empty)';
			return $out;
		}

		self::load_printify_api();
		if ( ! class_exists( 'Printify_Shipping_API' ) ) {
			$out['source'] = 'printify plugin not present';
			return $out;
		}

		$version = '3.2';
		if ( defined( 'Printify_Shipping_Method::VERSION' ) ) {
			$version = constant( 'Printify_Shipping_Method::VERSION' );
		}

		$api = new Printify_Shipping_API( $version );

		try {
			$res = $api->get_shipping_rates(
				array(
					'items'   => $items,
					'country' => $address['country'],
					'state'   => $address['state'],
					'zip'     => $address['zip'],
				)
			);
		} catch ( Throwable $e ) {
			$res = null;
		}

		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			$out['source'] = is_wp_error( $res ) ? 'api error: ' . $res->get_error_code() : 'no answer';
			set_transient( $key, array( 'skus' => array() ), self::TTL_EMPTY );
			return $out;
		}

		$skus     = isset( $res['skus'] ) ? array_values( (array) $res['skus'] ) : array();
		$standard = self::cents( $res, 'shipping_standart' );
		if ( null === $standard ) {
			$standard = self::cents( $res, 'shipping_standard' );
		}
		$express = self::cents( $res, 'shipping_express' );

		set_transient(
			$key,
			array(
				'skus'     => $skus,
				'standard' => $standard,
				'express'  => $express,
			),
			count( $skus ) ? self::TTL : self::TTL_EMPTY
		);

		$out['available'] = count( $skus ) > 0;
		$out['skus']      = $skus;
		$out['standard']  = $standard;
		$out['express']   = $express;
		$out['source']    = $out['available'] ? 'printify api' : 'printify api (claims none of these skus)';

		return $out;
	}

	/**
	 * Printful's live cost for the items in this package, through Printful's own method.
	 *
	 * Printful's plugin quotes from its account by variation id and caches the answer,
	 * so delegating to it is both accurate and cheap. Requires the plugin to be
	 * installed and connected; otherwise this returns "no answer".
	 *
	 * @param array $package WooCommerce shipping package containing only Printful goods.
	 * @return array available|cost|label|source
	 */
	public static function printful_quote( $package ) {
		$out = array(
			'available' => false,
			'cost'      => null,
			'label'     => '',
			'source'    => 'none',
		);

		if ( ! class_exists( 'Printful_Shipping' ) ) {
			$out['source'] = 'printful plugin not present';
			return $out;
		}

		try {
			$method = new Printful_Shipping();
			$rates  = $method->get_rates_for_package( $package );
		} catch ( Throwable $e ) {
			$out['source'] = 'printful call failed: ' . get_class( $e );
			return $out;
		}

		if ( ! is_array( $rates ) || empty( $rates ) ) {
			$out['source'] = 'printful returned no rates (account not connected, or these items are not Printful)';
			return $out;
		}

		$best  = null;
		$label = '';
		foreach ( $rates as $rate ) {
			if ( ! is_object( $rate ) ) {
				continue;
			}
			$cost = (float) $rate->get_cost();
			if ( $cost <= 0 ) {
				continue;
			}
			if ( null === $best || $cost < $best ) {
				$best  = $cost;
				$label = (string) $rate->get_label();
			}
		}

		if ( null === $best ) {
			$out['source'] = 'printful rates were all zero';
			return $out;
		}

		$out['available'] = true;
		$out['cost']      = $best;
		$out['label']     = $label;
		$out['source']    = 'printful method';

		return $out;
	}

	/**
	 * Are the supplier plugins on this server at all?
	 *
	 * @return array provider => bool
	 */
	public static function availability() {
		self::load_printify_api();

		return array(
			'printify' => class_exists( 'Printify_Shipping_API' ),
			'printful' => class_exists( 'Printful_Shipping' ),
		);
	}

	/**
	 * Printify's API class comes from their plugin's folder, not an autoloader.
	 *
	 * @return bool
	 */
	public static function load_printify_api() {
		if ( class_exists( 'Printify_Shipping_API' ) ) {
			return true;
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return false;
		}
		$file = WP_PLUGIN_DIR . '/' . self::PRINTIFY_DIR . '/includes/printify-shipping-api.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
		return class_exists( 'Printify_Shipping_API' );
	}

	/**
	 * The package's items keyed by SKU, in the shape Printify's endpoint expects.
	 *
	 * @param array $package Package.
	 * @return array
	 */
	public static function sku_items( $package ) {
		$items = array();
		foreach ( (array) ( isset( $package['contents'] ) ? $package['contents'] : array() ) as $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$product = $item['data'];
			if ( $product->is_virtual() || $product->is_downloadable() ) {
				continue;
			}
			$sku = trim( (string) $product->get_sku() );
			if ( '' === $sku ) {
				continue;
			}
			$qty       = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();

			if ( isset( $items[ $sku ] ) ) {
				$items[ $sku ]['quantity'] += $qty;
				continue;
			}

			$items[ $sku ] = array(
				'sku'        => $sku,
				'product_id' => (int) $product->get_id(),
				'parent_id'  => $parent_id,
				'permalink'  => function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $product->get_id() ) : '',
				'quantity'   => $qty,
			);
		}
		return $items;
	}

	/**
	 * @param array $package Package.
	 * @return array country|state|zip
	 */
	public static function address( $package ) {
		$dest = isset( $package['destination'] ) ? (array) $package['destination'] : array();
		return array(
			'country' => strtoupper( (string) ( isset( $dest['country'] ) ? $dest['country'] : '' ) ),
			'state'   => (string) ( isset( $dest['state'] ) ? $dest['state'] : '' ),
			'zip'     => (string) ( isset( $dest['postcode'] ) ? $dest['postcode'] : '' ),
		);
	}

	/**
	 * Cents to dollars from Printify's answer, tolerating their spelling of the key.
	 *
	 * @param array  $res  Response.
	 * @param string $key  Key to read.
	 * @return float|null
	 */
	protected static function cents( $res, $key ) {
		if ( ! is_array( $res ) || ! isset( $res[ $key ] ) || ! is_array( $res[ $key ] ) ) {
			return null;
		}
		if ( ! isset( $res[ $key ]['cost'] ) ) {
			return null;
		}
		$cost = (float) $res[ $key ]['cost'];
		return $cost > 0 ? round( $cost / 100, 2 ) : null;
	}
}
