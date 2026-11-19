<?php
/**
 * Provider detection: which supplier fulfils a product.
 *
 * How the sync plugins tag their own products (measured on a WooCommerce 11 install running
 * Printful's and Printify's plugins): product meta printify_product_id, variation meta
 * printify_variant_id, product meta _printful_product_id. A supplier that links by SKU through a
 * REST connection writes no provider meta at all, so its items have to be declared - by meta key,
 * by SKU pattern and by shipping class.
 *
 * Detection order (first match wins):
 *   1. explicit provider meta - the keys in meta_keys(): the plugin setting, `_psr_provider`,
 *      and anything added with the `tsm_provider_meta_keys` filter
 *      (value: tapstitch | printify | printful | fixed | none)
 *   2. supplier meta from the sync plugins (printify_product_id / _printful_product_id)
 *   3. SKU pattern (default: 15+ digits, the shape Tapstitch design ids take)
 *   4. shipping class map
 *   5. default provider
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_Provider {

	const TAPSTITCH = 'tapstitch';
	const PRINTIFY  = 'printify';
	const PRINTFUL  = 'printful';
	const FIXED     = 'fixed';
	const NONE      = 'none';

	const META_DEFAULT = '_psr_provider';

	/**
	 * The product meta keys that declare a provider, in the order they are read.
	 *
	 * The plugin's own method-instance setting `provider_meta_key`, then the shared registry key
	 * `_psr_provider`, then anything the store adds:
	 *
	 *   add_filter(
	 *       'tsm_provider_meta_keys',
	 *       function ( $keys ) {
	 *           $keys[] = 'a_legacy_meta_key';
	 *           return $keys;
	 *       }
	 *   );
	 *
	 * A store migrating from another plugin names its old key and needs no data migration.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		$keys = array();

		$setting = trim( (string) get_option( 'tsm_provider_meta_key', self::META_DEFAULT ) );
		if ( '' !== $setting ) {
			$keys[] = $setting;
		}

		$keys[] = self::META_DEFAULT;

		foreach ( (array) apply_filters( 'tsm_provider_meta_keys', array() ) as $key ) {
			if ( is_string( $key ) && '' !== trim( $key ) ) {
				$keys[] = trim( $key );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * @param WC_Product $product Product (a variation resolves to its parent).
	 * @param array      $rules   Options: printify_meta, printful_meta, sku_pattern, default.
	 * @return array provider|via|sku
	 */
	public static function detect( $product, $rules = array() ) {
		$via  = '';
		$sku  = '';

		if ( $product instanceof WC_Product ) {
			$sku = (string) $product->get_sku();
			if ( '' === $sku && $product->get_parent_id() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$sku = (string) $parent->get_sku();
				}
			}
		}

		// 1. Explicit declaration on the product.
		if ( $product instanceof WC_Product ) {
			$declared = '';
			$found    = '';
			foreach ( self::meta_keys() as $key ) {
				$value = self::meta( $product, $key );
				if ( '' !== (string) $value ) {
					$declared = $value;
					$found    = $key;
					break;
				}
			}
			if ( $declared ) {
				$declared = strtolower( (string) $declared );
				// The store's own words for "I ship this myself" all mean the same
				// driver: the zone's Flat Rate.
				$aliases = array( 'in-house' => self::FIXED, 'inhouse' => self::FIXED, 'self' => self::FIXED, 'own' => self::FIXED, 'site' => self::FIXED, 'flat' => self::FIXED, 'flat-rate' => self::FIXED, 'warehouse' => self::FIXED );
				if ( isset( $aliases[ $declared ] ) ) {
					return array(
						'provider' => $aliases[ $declared ],
						'via'      => 'meta ' . $found . ' (' . $declared . ')',
						'sku'      => $sku,
					);
				}
				if ( in_array( $declared, array( self::TAPSTITCH, self::PRINTIFY, self::PRINTFUL, self::FIXED, self::NONE ), true ) ) {
					return array(
						'provider' => $declared,
						'via'      => 'meta ' . $found,
						'sku'      => $sku,
					);
				}
			}
		}

		// 2. The supplier plugins' own tags.
		$allow_printify = ! isset( $rules['printify_meta'] ) || 'no' !== $rules['printify_meta'];
		$allow_printful = ! isset( $rules['printful_meta'] ) || 'no' !== $rules['printful_meta'];

		if ( $product instanceof WC_Product ) {
			if ( $allow_printify && ( self::meta( $product, 'printify_product_id' ) || self::meta( $product, 'printify_variant_id' ) ) ) {
				return array(
					'provider' => self::PRINTIFY,
					'via'      => 'meta printify_product_id',
					'sku'      => $sku,
				);
			}
			if ( $allow_printful && ( self::meta( $product, '_printful_product_id' ) || self::meta( $product, 'printful_product_id' ) ) ) {
				return array(
					'provider' => self::PRINTFUL,
					'via'      => 'meta _printful_product_id',
					'sku'      => $sku,
				);
			}
		}

		// 3. Declared by shipping class (the mechanism WooCommerce shipping already uses).
		if ( $product instanceof WC_Product ) {
			$class = strtolower( (string) $product->get_shipping_class() );
			if ( '' !== $class && isset( $rules['class_provider'][ $class ] ) ) {
				$p = strtolower( (string) $rules['class_provider'][ $class ] );
				if ( in_array( $p, array( self::TAPSTITCH, self::PRINTIFY, self::PRINTFUL, self::FIXED, self::NONE ), true ) ) {
					return array(
						'provider' => $p,
						'via'      => 'shipping class ' . $class,
						'sku'      => $sku,
					);
				}
			}
			if ( isset( $rules['class_provider']['_default'] ) ) {
				$p = strtolower( (string) $rules['class_provider']['_default'] );
				if ( in_array( $p, array( self::TAPSTITCH, self::PRINTIFY, self::PRINTFUL, self::FIXED, self::NONE ), true ) ) {
					return array(
						'provider' => $p,
						'via'      => '' === $class ? 'class rule _default' : 'class rule (unclassed products)',
						'sku'      => $sku,
					);
				}
			}
		}

		// 4. SKU shape. Tapstitch design ids and Printify ids are both long numbers
		//    on this store, so this is a hint, not a guarantee - the report shows it.
		$pattern = isset( $rules['sku_pattern'] ) ? (string) $rules['sku_pattern'] : '/^\d{15,}$/';
		if ( '' !== $sku && '' !== $pattern && self::sku_matches( $sku, $pattern ) ) {
			return array(
				'provider' => self::TAPSTITCH,
				'via'      => 'sku pattern (weak: numeric SKUs are used by several suppliers here)',
				'sku'      => $sku,
			);
		}

		// 5. Fall through; the caller decides by class map / default.
		$default = isset( $rules['default'] ) ? (string) $rules['default'] : self::TAPSTITCH;
		if ( ! in_array( $default, array( self::TAPSTITCH, self::PRINTIFY, self::PRINTFUL, self::FIXED, self::NONE ), true ) ) {
			$default = self::TAPSTITCH;
		}

		return array(
			'provider' => $default,
			'via'      => '' === $sku ? 'no sku, default' : 'default',
			'sku'      => $sku,
		);
	}

	/**
	 * Meta, looking at the variation first then its parent.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $key     Meta key.
	 * @return mixed
	 */
	public static function meta( $product, $key ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$value = $product->get_meta( $key, true );
		if ( ( '' === $value || null === $value ) && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$value = $parent->get_meta( $key, true );
			}
		}
		return ( '' === $value || null === $value ) ? null : $value;
	}

	/**
	 * Safe SKU match: the pattern is store-authored, so validate before use.
	 *
	 * @param string $sku     SKU.
	 * @param string $pattern Regex with delimiters, or a plain length rule.
	 * @return bool
	 */
	public static function sku_matches( $sku, $pattern ) {
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		// Plain forms: "15+" (digit length) accepted as well as a regex.
		if ( preg_match( '/^\d+\+$/', $pattern ) ) {
			$need = (int) rtrim( $pattern, '+' );
			return (bool) ( preg_match( '/^\d+$/', $sku ) && strlen( $sku ) >= $need );
		}
		$test = @preg_match( $pattern, $sku );
		if ( false === $test ) {
			return (bool) ( preg_match( '/^\d{15,}$/', $sku ) );
		}
		return 1 === $test;
	}

	/**
	 * Provider report for the admin screen: every published product, what it was
	 * identified as, and the Tapstitch category it maps to.
	 *
	 * @param WC_Shipping_Tapstitch $method Method whose rules are used.
	 * @return array[]
	 */
	public static function report( $method ) {
		$rules = array(
			'printify_meta' => $method->get_option( 'printify_meta' ),
			'printful_meta' => $method->get_option( 'printful_meta' ),
			'sku_pattern'   => $method->get_option( 'sku_pattern' ),
			'default'       => $method->get_option( 'provider_default' ),
		);

		$out      = array();
		$products = function_exists( 'wc_get_products' )
			? wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => 100,
					'return' => 'objects',
				)
			)
			: array();

		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$class   = (string) $product->get_shipping_class();
			$detected = self::detect( $product, $rules );
			$slug     = $method->slug_for( $class );
			$out[]    = array(
				'id'       => (int) $product->get_id(),
				'name'     => $product->get_name(),
				'sku'      => $detected['sku'],
				'class'    => $class,
				'provider' => $detected['provider'],
				'via'      => $detected['via'],
				'slug'     => $slug,
				'type'     => $product->is_type( 'variable' ) ? 'variable' : $product->get_type(),
			);
		}

		return $out;
	}
}
