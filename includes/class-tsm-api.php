<?php
/**
 * Rate source: Tapstitch's published shipping-rate endpoint.
 *
 * Verified 2026-11-19:
 *   GET https://www.tapstitch.com/api/services/site/shipping-rates
 *       ?countryCodes=US,CA&categorySlugs=hoodies,sweatpants,t-shirts
 *   -> {"code":200,"data":{"currency":{"code":"USD"},
 *          "list":[ {"country":{"code":"US"},"categoryName":"Hoodies",
 *                    "fulfillmentService":"International","shippingLine":"Special Line",
 *                    "shippingTimeRange":{"min":9,"max":14},
 *                    "shippingCost":933,
 *                    "additionalShippingCostRange":{"min":711,"max":743},
 *                    "weight":700}, ... ]}}
 *
 * Money values are integer cents in USD (store base currency).
 * Unknown country codes return an empty list (200), never an error.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_API {

	const ENDPOINT = 'https://www.tapstitch.com/api/services/site/shipping-rates';
	const BATCH    = 25;
	const MAX_CHUNKS = 8; // Hard stop so a lookup can never turn into a long series of requests.

	/**
	 * Configurable endpoint (filterable) so a schema change is a setting, not a patch.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$url = get_option( 'tapstitch_sm_endpoint', self::ENDPOINT );
		$url = trim( (string) $url );
		if ( '' === $url || ! preg_match( '#^https?://#', $url ) ) {
			$url = self::ENDPOINT;
		}
		return apply_filters( 'tapstitch_shipping_endpoint', $url );
	}

	/**
	 * Fetch rates for countries x categories.
	 *
	 * @param string[] $countries ISO country codes.
	 * @param string[] $slugs     Tapstitch category slugs (e.g. "hoodies").
	 * @param array    $args      timeout, fulfillment, lane filters.
	 * @return array|WP_Error Normalised rows.
	 */
	public static function fetch( $countries, $slugs, $args = array() ) {
		$countries = self::clean_codes( $countries );
		$slugs     = self::clean_slugs( $slugs );

		if ( empty( $countries ) || empty( $slugs ) ) {
			return new WP_Error( 'tsm_no_args', 'Need at least one country code and one category slug.' );
		}

		$timeout = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : 6;
		$rows    = array();

		foreach ( array_slice( array_chunk( $countries, self::BATCH ), 0, self::MAX_CHUNKS ) as $chunk ) {
			$url = add_query_arg(
				array(
					'countryCodes'  => implode( ',', $chunk ),
					'categorySlugs' => implode( ',', $slugs ),
				),
				self::endpoint()
			);

			$res = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 2,
					'headers'     => array(
						'Accept'       => 'application/json',
						'User-Agent'   => 'Tapstitch-Shipping-Method/' . ( defined( 'TSM_VERSION' ) ? TSM_VERSION : '0' ),
						'Cache-Control' => 'no-cache',
					),
				)
			);

			if ( is_wp_error( $res ) ) {
				if ( empty( $rows ) ) {
					return $res;
				}
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code < 200 || $code > 299 ) {
				if ( empty( $rows ) ) {
					return new WP_Error( 'tsm_http', 'Tapstitch rate endpoint returned HTTP ' . $code );
				}
				continue;
			}

			$parsed = json_decode( wp_remote_retrieve_body( $res ), true );
			$part   = self::normalize( $parsed );

			if ( empty( $part ) && empty( $rows ) ) {
				return new WP_Error( 'tsm_shape', 'Unexpected response from the Tapstitch rate endpoint (no usable rows).' );
			}
			$rows = array_merge( $rows, $part );
		}

		return $rows;
	}

	/**
	 * Turn the API payload into flat rows.
	 *
	 * @param mixed $parsed Decoded JSON.
	 * @return array
	 */
	public static function normalize( $parsed ) {
		$out = array();
		if ( ! is_array( $parsed ) || ! isset( $parsed['data']['list'] ) || ! is_array( $parsed['data']['list'] ) ) {
			return $out;
		}

		foreach ( $parsed['data']['list'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$country = isset( $item['country']['code'] ) ? strtoupper( (string) $item['country']['code'] ) : '';
			$name    = isset( $item['categoryName'] ) ? (string) $item['categoryName'] : '';
			$line    = isset( $item['shippingLine'] ) ? (string) $item['shippingLine'] : '';
			$ful     = isset( $item['fulfillmentService'] ) ? (string) $item['fulfillmentService'] : '';

			if ( '' === $country || '' === $name || '' === $line ) {
				continue;
			}

			$first = isset( $item['shippingCost'] ) ? (float) $item['shippingCost'] / 100 : 0;
			$amin  = isset( $item['additionalShippingCostRange']['min'] ) ? (float) $item['additionalShippingCostRange']['min'] / 100 : 0;
			$amax  = isset( $item['additionalShippingCostRange']['max'] ) ? (float) $item['additionalShippingCostRange']['max'] / 100 : $amin;
			$tmin  = isset( $item['shippingTimeRange']['min'] ) ? (int) $item['shippingTimeRange']['min'] : 0;
			$tmax  = isset( $item['shippingTimeRange']['max'] ) ? (int) $item['shippingTimeRange']['max'] : $tmin;
			$grams = isset( $item['weight'] ) ? (int) $item['weight'] : 0;

			if ( $first <= 0 ) {
				continue;
			}

			$out[] = array(
				'country'     => $country,
				'slug'        => self::slug( $name ),
				'category'    => $name,
				'fulfillment' => $ful,
				'line'        => $line,
				'time_min'    => $tmin,
				'time_max'    => $tmax,
				'first'       => round( $first, 2 ),
				'add_min'     => round( $amin, 2 ),
				'add_max'     => round( max( $amax, $amin ), 2 ),
				'weight'      => $grams,
			);
		}

		return $out;
	}

	/**
	 * "Pants & Sweatpants" -> "pants-sweatpants"
	 *
	 * @param string $name Category name from the API.
	 * @return string
	 */
	public static function slug( $name ) {
		$slug = trim( (string) $name );
		$slug = str_replace( array( '&', ',', '  ' ), array( 'and', ' ', ' ' ), $slug );
		$slug = preg_replace( '/[^a-zA-Z0-9]+/', '-', $slug );
		$slug = trim( (string) preg_replace( '/-+/', '-', $slug ), '-' );
		return strtolower( $slug );
	}

	/**
	 * @param string[] $codes Raw country codes.
	 * @return string[]
	 */
	public static function clean_codes( $codes ) {
		$out = array();
		foreach ( (array) $codes as $code ) {
			$code = strtoupper( trim( (string) $code ) );
			if ( preg_match( '/^[A-Z]{2,3}$/', $code ) ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string[] $slugs Raw category slugs.
	 * @return string[]
	 */
	public static function clean_slugs( $slugs ) {
		$out = array();
		foreach ( (array) $slugs as $slug ) {
			$slug = strtolower( trim( (string) $slug ) );
			$slug = preg_replace( '/[^a-z0-9\-]/', '', $slug );
			if ( '' !== $slug ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * One probe request, used by the admin self-check.
	 *
	 * @return array|WP_Error
	 */
	public static function probe() {
		return self::fetch( array( 'US' ), array( 'hoodies', 't-shirts' ), array( 'timeout' => 8 ) );
	}
}
