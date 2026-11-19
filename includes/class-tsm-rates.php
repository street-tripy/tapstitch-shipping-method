<?php
/**
 * Rate cache, aggregation and cost maths.
 *
 * Checkout NEVER calls the remote endpoint. It reads:
 *   1. the fresh transient for this zone/category set, else
 *   2. the last good snapshot (all-time option), else
 *   3. the shipped seed file, else
 *   4. the configured floor.
 *
 * Cost model, copied from Tapstitch's published calculation:
 *   total = first + handling + additional * ( qty - 1 )
 * then percentage uplift, floor, cap, rounding.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_Rates {

	const TRANSIENT_PREFIX = 'tapstitch_sm_rows_';
	const STALE_KEY        = 'tapstitch_sm_stale_v1';
	const INDEX_KEY        = 'tapstitch_sm_cache_index';
	const TTL              = 21600; // 6 hours.

	/**
	 * Rows for a set of countries + categories + lane, with layered fallback.
	 *
	 * @param string[] $countries Zone country codes (empty = the whole world).
	 * @param string[] $slugs     Tapstitch category slugs.
	 * @param string   $lane      Shipping line name.
	 * @param string   $fulfillment International|USA.
	 * @return array{rows:array,source:string,age:int}
	 */
	public static function lookup( $countries, $slugs, $lane, $fulfillment ) {
		$key   = self::cache_key( $countries, $slugs, $lane, $fulfillment );
		$cache = get_transient( $key );

		if ( is_array( $cache ) && ! empty( $cache ) ) {
			return array(
				'rows'   => $cache,
				'source' => 'cache',
				'age'    => time() - (int) get_option( 'tapstitch_sm_last_fetch', 0 ),
			);
		}

		$fetched = TSM_API::fetch( $countries, $slugs );

		if ( ! is_wp_error( $fetched ) && ! empty( $fetched ) ) {
			$fetched = self::filter( $fetched, $lane, $fulfillment );
			if ( ! empty( $fetched ) ) {
				set_transient( $key, $fetched, self::TTL );
				self::remember( $key );
				self::save_stale( $fetched );
				update_option( 'tapstitch_sm_last_fetch', time() );
				return array(
					'rows'   => $fetched,
					'source' => 'live',
					'age'    => 0,
				);
			}
		}

		$stale = self::stale();
		if ( ! empty( $stale ) ) {
			$rows = self::filter( $stale, $lane, $fulfillment );
			$rows = self::only( $rows, $countries, $slugs );
			if ( ! empty( $rows ) ) {
				return array(
					'rows'   => $rows,
					'source' => 'snapshot',
					'age'    => time() - (int) get_option( 'tapstitch_sm_last_fetch', 0 ),
				);
			}
		}

		$seed = self::seed_rows();
		if ( ! empty( $seed ) ) {
			$rows = self::filter( $seed, $lane, $fulfillment );
			$rows = self::only( $rows, $countries, $slugs );
			if ( ! empty( $rows ) ) {
				return array(
					'rows'   => $rows,
					'source' => 'seed',
					'age'    => 0,
				);
			}
		}

		return array(
			'rows'   => array(),
			'source' => 'none',
			'age'    => 0,
		);
	}

	/**
	 * Transient key for a lookup set.
	 *
	 * @param string[] $countries Country codes.
	 * @param string[] $slugs     Category slugs.
	 * @param string   $lane      Shipping line.
	 * @param string   $fulfillment Fulfilment origin.
	 * @return string
	 */
	public static function cache_key( $countries, $slugs, $lane, $fulfillment ) {
		$countries = TSM_API::clean_codes( $countries );
		$slugs     = TSM_API::clean_slugs( $slugs );
		sort( $countries );
		sort( $slugs );
		return self::TRANSIENT_PREFIX . md5( TSM_API::endpoint() . '|' . implode( ',', $countries ) . '|' . implode( ',', $slugs ) . '|' . $lane . '|' . $fulfillment );
	}

	/**
	 * Track transient keys so settings changes can clear them.
	 *
	 * @param string $key Transient key.
	 */
	protected static function remember( $key ) {
		$index = get_option( self::INDEX_KEY, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		if ( ! in_array( $key, $index, true ) ) {
			$index[] = $key;
		}
		if ( count( $index ) > 40 ) {
			$index = array_slice( $index, -40 );
		}
		update_option( self::INDEX_KEY, $index );
	}

	/**
	 * All-time snapshot, merged by country+category+lane+fulfillment.
	 *
	 * @param array $rows Rows.
	 */
	public static function save_stale( $rows ) {
		$snapshot = get_option( self::STALE_KEY, array() );
		$existing = isset( $snapshot['rows'] ) && is_array( $snapshot['rows'] ) ? $snapshot['rows'] : array();
		$index    = array();
		foreach ( $existing as $row ) {
			$index[ self::row_key( $row ) ] = $row;
		}
		foreach ( (array) $rows as $row ) {
			$index[ self::row_key( $row ) ] = $row;
		}
		update_option(
			self::STALE_KEY,
			array(
				'fetched' => time(),
				'rows'    => array_values( $index ),
			)
		);
	}

	/**
	 * @return array
	 */
	public static function stale() {
		$snapshot = get_option( self::STALE_KEY, array() );
		return isset( $snapshot['rows'] ) && is_array( $snapshot['rows'] ) ? $snapshot['rows'] : array();
	}

	/**
	 * @param array $row Row.
	 * @return string
	 */
	public static function row_key( $row ) {
		return $row['country'] . '|' . $row['slug'] . '|' . $row['line'] . '|' . $row['fulfillment'];
	}

	/**
	 * @param array  $rows Rows.
	 * @param string $lane Shipping line.
	 * @param string $fulfillment Fulfilment origin ('' = any).
	 * @return array
	 */
	public static function filter( $rows, $lane, $fulfillment = '' ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( '' !== $lane && ! empty( $lane ) && ! self::same( $row['line'], $lane ) ) {
				continue;
			}
			if ( '' !== $fulfillment && ! self::same( $row['fulfillment'], $fulfillment ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param array    $rows Rows.
	 * @param string[] $countries Countries to keep.
	 * @param string[] $slugs Categories to keep.
	 * @return array
	 */
	public static function only( $rows, $countries, $slugs ) {
		$countries = array_map( 'strtoupper', (array) $countries );
		$slugs     = (array) $slugs;
		$out       = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $countries ) && ! in_array( $row['country'], $countries, true ) ) {
				continue;
			}
			if ( ! empty( $slugs ) && ! in_array( $row['slug'], $slugs, true ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param string $a Value.
	 * @param string $b Value.
	 * @return bool
	 */
	protected static function same( $a, $b ) {
		return 0 === strcasecmp( trim( (string) $a ), trim( (string) $b ) );
	}

	/**
	 * Find a rate for a category slug, tolerating slug style differences
	 * between the settings ("tshirts"), the API ("T-Shirts") and old maps.
	 *
	 * @param array  $agg  Aggregated rates.
	 * @param string $slug Category slug from the settings.
	 * @return array|null
	 */
	public static function find( $agg, $slug ) {
		$slug = self::norm( $slug );
		if ( '' === $slug ) {
			return null;
		}
		if ( isset( $agg[ $slug ] ) ) {
			return $agg[ $slug ];
		}
		foreach ( (array) $agg as $key => $rate ) {
			$candidate = self::norm( $key );
			if ( $candidate === $slug ) {
				return $rate;
			}
			if ( isset( $rate['category'] ) && self::norm( $rate['category'] ) === $slug ) {
				return $rate;
			}
			if ( '' !== $candidate && ( false !== strpos( $candidate, $slug ) || false !== strpos( $slug, $candidate ) ) ) {
				return $rate;
			}
		}
		return null;
	}

	/**
	 * "Pants & Sweatpants" and "pants-sweatpants" and "pantsandsweatpants" -> "pantssweatpants".
	 *
	 * @param string $value Raw slug or category name.
	 * @return string
	 */
	public static function norm( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '', $value );
		$value = preg_replace( '/^and/', '', $value );
		$value = str_replace( 'and', '', $value );
		return (string) $value;
	}

	/**
	 * Collapse a zone's rows into one rate per category.
	 *
	 * The worst (most expensive) country in the zone wins, so no destination
	 * can cost the store money.
	 *
	 * @param array  $rows Rows.
	 * @param string $bound min|mid|max.
	 * @return array slug => rate
	 */
	public static function aggregate( $rows, $bound = 'max' ) {
		$by = array();
		foreach ( (array) $rows as $row ) {
			$slug = $row['slug'];
			if ( ! isset( $by[ $slug ] ) ) {
				$by[ $slug ] = array(
					'slug'      => $slug,
					'category'  => $row['category'],
					'first'     => (float) $row['first'],
					'add_min'   => (float) $row['add_min'],
					'add_max'   => (float) $row['add_max'],
					'time_min'  => (int) $row['time_min'],
					'time_max'  => (int) $row['time_max'],
					'weight'    => (int) $row['weight'],
					'countries' => array(),
					'worst'     => $row['country'],
				);
			}
			$agg = &$by[ $slug ];
			if ( (float) $row['first'] > $agg['first'] ) {
				$agg['first']   = (float) $row['first'];
				$agg['worst']   = $row['country'];
				$agg['weight']  = (int) $row['weight'];
			}
			$agg['add_min']  = max( $agg['add_min'], (float) $row['add_min'] );
			$agg['add_max']  = max( $agg['add_max'], (float) $row['add_max'] );
			$agg['time_min'] = $agg['time_min'] > 0 ? min( $agg['time_min'], (int) $row['time_min'] ) : (int) $row['time_min'];
			$agg['time_max'] = max( $agg['time_max'], (int) $row['time_max'] );
			$agg['countries'][ $row['country'] ] = $row['country'];
			unset( $agg );
		}
		foreach ( $by as $slug => $agg ) {
			$by[ $slug ]['additional'] = self::bound( $agg, $bound );
		}
		return $by;
	}

	/**
	 * @param array  $agg Aggregated rate.
	 * @param string $bound min|mid|max.
	 * @return float
	 */
	public static function bound( $agg, $bound ) {
		if ( 'min' === $bound ) {
			return (float) $agg['add_min'];
		}
		if ( 'mid' === $bound ) {
			return round( ( (float) $agg['add_min'] + (float) $agg['add_max'] ) / 2, 2 );
		}
		return (float) $agg['add_max'];
	}

	/**
	 * Customer-facing cost for N items of one category.
	 *
	 * @param array $agg Aggregated rate (first + additional).
	 * @param int   $qty Items in the package of this class.
	 * @param array $o Options: handling, pct, floor, cap, round, bound.
	 * @return float
	 */
	public static function cost( $agg, $qty, $o = array() ) {
		$qty       = max( 1, (int) $qty );
		$handling  = isset( $o['handling'] ) ? (float) $o['handling'] : 0.0;
		$pct       = isset( $o['pct'] ) ? (float) $o['pct'] : 0.0;
		$floor     = isset( $o['floor'] ) ? (float) $o['floor'] : 0.0;
		$cap       = isset( $o['cap'] ) ? (float) $o['cap'] : 0.0;
		$bound     = isset( $o['bound'] ) ? $o['bound'] : 'max';
		$round     = isset( $o['round'] ) ? $o['round'] : 'nearest';

		$first      = (float) $agg['first'];
		$additional = self::bound( $agg, $bound );

		$total = $first + $handling + ( $qty > 1 ? $additional * ( $qty - 1 ) : 0 );

		if ( $pct > 0 ) {
			$total = $total * ( 1 + $pct / 100 );
		}
		if ( $floor > 0 && $total < $floor ) {
			$total = $floor;
		}
		if ( $cap > 0 && $total > $cap ) {
			$total = $cap;
		}

		return self::round_money( $total, $round );
	}

	/**
	 * Price rounding. Rates are never rounded down below the computed cost.
	 *
	 * @param float  $amount Raw.
	 * @param string $mode nearest99|int99|none.
	 * @return float
	 */
	public static function round_money( $amount, $mode = 'nearest99' ) {
		$amount = round( (float) $amount, 2 );

		if ( 'none' === $mode ) {
			return $amount;
		}

		if ( 'int99' === $mode ) {
			return round( floor( $amount ) + 0.99, 2 );
		}

		// nearest99: the closest .99 that is not below the real cost.
		$whole     = floor( $amount );
		$lower     = round( $whole + 0.99, 2 );
		$higher    = round( $whole + 1.99, 2 );
		$candidates = array();
		if ( $lower >= $amount ) {
			$candidates[] = $lower;
		}
		if ( $higher >= $amount ) {
			$candidates[] = $higher;
		}
		if ( empty( $candidates ) ) {
			return round( ceil( $amount * 100 ) / 100, 2 );
		}
		return round( min( $candidates ), 2 );
	}

	/**
	 * The WooCommerce core Flat Rate formula string that matches this rate,
	 * so the store can run on the built-in method if it ever drops the plugin.
	 *
	 * @param array $agg Aggregated rate.
	 * @param array $o   Options (handling, bound).
	 * @return string
	 */
	public static function formula( $agg, $o = array() ) {
		$handling  = isset( $o['handling'] ) ? (float) $o['handling'] : 0.0;
		$bound     = isset( $o['bound'] ) ? $o['bound'] : 'max';
		$first     = round( (float) $agg['first'] + $handling, 2 );
		$additional = self::bound( $agg, $bound );
		if ( $additional <= 0 ) {
			return number_format( $first, 2, '.', '' );
		}
		return number_format( $first, 2, '.', '' ) . ' + ' . number_format( $additional, 2, '.', '' ) . ' * ( [qty] - 1 )';
	}

	/**
	 * Rows shipped inside the plugin, used before the first successful fetch.
	 *
	 * @return array
	 */
	public static function seed_rows() {
		$file = TSM_PATH . 'data/seed-rates.json';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$json = @file_get_contents( $file ); // phpcs:ignore
		if ( ! $json ) {
			return array();
		}
		// A UTF-8 BOM (what Out-File -Encoding utf8 writes) makes json_decode fail and
		// the seed silently disappear. Checked byte-wise: preg_replace() with a
		// leading-slash pattern returns null on PCRE2, which is worse than the BOM.
		if ( 0 === strpos( $json, "\xEF\xBB\xBF" ) ) {
			$json = substr( $json, 3 );
		}
		$parsed = json_decode( $json, true );
		if ( ! is_array( $parsed ) ) {
			return array();
		}
		if ( isset( $parsed['rows'] ) ) {
			$parsed = $parsed['rows'];
		}
		return self::filter_valid( $parsed );
	}

	/**
	 * Step-by-step trace of reading the seed file, for /debug.
	 *
	 * Mirrors seed_rows() exactly and reports the count at every stage, so a
	 * silent drop is visible.
	 *
	 * @return array
	 */
	public static function seed_trace() {
		$file = TSM_PATH . 'data/seed-rates.json';
		$out  = array(
			'file'   => $file,
			'exists' => file_exists( $file ),
			'path'   => TSM_PATH,
		);
		if ( ! $out['exists'] ) {
			return $out;
		}

		$json         = @file_get_contents( $file ); // phpcs:ignore
		$out['read']  = is_string( $json ) ? strlen( $json ) : gettype( $json );
		$out['head']  = is_string( $json ) ? bin2hex( substr( $json, 0, 8 ) ) : '';

		// The regex route, kept here deliberately: on PHP 8.4 / PCRE2 a pattern that
		// begins with a slash makes preg_replace() return null, which is what used to
		// kill the seed. Named "legacy_*" so nobody reads these as the live path.
		$stripped          = preg_replace( '//^\xEF\xBB\xBF/', '', (string) $json );
		$out['legacy_strip']  = is_string( $stripped ) ? strlen( $stripped ) : gettype( $stripped );
		$out['legacy_decode'] = gettype( json_decode( (string) $stripped, true ) );

		// What the plugin actually does now: byte check, no PCRE.
		$clean = (string) $json;
		if ( 0 === strpos( $clean, "\xEF\xBB\xBF" ) ) {
			$clean = substr( $clean, 3 );
		}
		$parsed        = json_decode( $clean, true );
		$out['decode']  = is_array( $parsed ) ? 'array' : gettype( $parsed );
		$out['jsonerr'] = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '';
		$out['keys']    = is_array( $parsed ) ? array_slice( array_keys( $parsed ), 0, 6 ) : array();

		$rows          = ( is_array( $parsed ) && isset( $parsed['rows'] ) ) ? $parsed['rows'] : array();
		$out['rows']   = is_array( $rows ) ? count( $rows ) : gettype( $rows );
		$out['valid']  = count( self::filter_valid( $rows ) );
		$out['seed']   = count( self::seed_rows() );

		return $out;
	}

	/**
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function filter_valid( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['country'] ) || empty( $row['slug'] ) || empty( $row['line'] ) ) {
				continue;
			}
			$row['country']     = strtoupper( (string) $row['country'] );
			$row['slug']        = TSM_API::slug( (string) $row['slug'] );
			$row['first']       = (float) $row['first'];
			$row['add_min']     = (float) $row['add_min'];
			$row['add_max']     = (float) $row['add_max'];
			$row['time_min']    = (int) $row['time_min'];
			$row['time_max']    = (int) $row['time_max'];
			$row['weight']      = (int) $row['weight'];
			$row['category']    = isset( $row['category'] ) ? (string) $row['category'] : $row['slug'];
			$row['fulfillment'] = isset( $row['fulfillment'] ) ? (string) $row['fulfillment'] : 'International';
			if ( $row['first'] > 0 ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Daily refresh: pre-pull rates for every zone that uses this method.
	 *
	 * Called by WP-Cron. Failures are logged, never fatal.
	 */
	public static function cron_refresh() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return;
		}

		$sets = self::zone_sets();
		if ( empty( $sets ) ) {
			return;
		}

		$log = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;

		foreach ( $sets as $set ) {
			$slugs = isset( $set['slugs'] ) ? $set['slugs'] : array();
			if ( empty( $slugs ) ) {
				continue;
			}
			$countries = empty( $set['countries'] ) ? self::all_countries() : $set['countries'];
			$res       = TSM_API::fetch( $countries, $slugs );

			if ( is_wp_error( $res ) || empty( $res ) ) {
				$msg = is_wp_error( $res ) ? $res->get_error_message() : 'empty response';
				if ( $log ) {
					$log->warning( 'Tapstitch rate refresh skipped zone ' . $set['zone'] . ': ' . $msg, array( 'source' => 'tapstitch-shipping' ) );
				}
				continue;
			}

			$res = self::filter( $res, $set['lane'], $set['fulfillment'] );
			if ( empty( $res ) ) {
				continue;
			}
			$key = self::cache_key( $countries, $slugs, $set['lane'], $set['fulfillment'] );
			set_transient( $key, $res, self::TTL );
			self::remember( $key );
			self::save_stale( $res );
			update_option( 'tapstitch_sm_last_fetch', time() );

			if ( $log ) {
				$log->info(
					sprintf( 'Tapstitch rates refreshed for zone %1$s: %2$d rows, %3$d countries.', $set['zone'], count( $res ), count( $countries ) ),
					array( 'source' => 'tapstitch-shipping' )
				);
			}
		}
	}

	/**
	 * Countries + category slugs each zone instance needs.
	 *
	 * Includes zone 0 ("Locations not covered by your other zones"), which
	 * WooCommerce keeps outside get_zones().
	 *
	 * @return array
	 */
	public static function zone_sets() {
		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			return array();
		}

		$ids = array( 0 );
		foreach ( (array) WC_Shipping_Zones::get_zones() as $zone_data ) {
			$ids[] = (int) $zone_data['id'];
		}

		$sets = array();
		foreach ( array_unique( $ids ) as $id ) {
			$zone = new WC_Shipping_Zone( $id );
			foreach ( self::zone_method_rows( (int) $zone->get_id() ) as $row ) {
				if ( 'tapstitch_shipping' !== $row['method_id'] || ! $row['enabled'] ) {
					continue;
				}
				$method = new WC_Shipping_Tapstitch( (int) $row['instance_id'] );
				$sets[] = array(
					'zone'        => (int) $zone->get_id(),
					'name'        => self::zone_name( $zone ),
					'countries'   => array_values( array_unique( array_merge( self::zone_countries( $zone ), $method->extra_countries() ) ) ),
					'slugs'       => $method->tapstitch_slugs(),
					'lane'        => $method->get_option( 'lane' ),
					'fulfillment' => $method->get_option( 'fulfillment' ),
				);
			}
		}
		return $sets;
	}

	/**
	 * Zone method rows straight from the database, without constructing the methods
	 * themselves (a foreign plugin's constructor can register hooks and unregister
	 * shipping methods the moment it is built).
	 *
	 * WooCommerce's own accessor is the zone data store's get_methods(); the method
	 * is reached through WC_Data_Store's __call proxy, so it is not visible to
	 * method_exists(). Reading the table directly is stable across WooCommerce
	 * versions and is read-only.
	 *
	 * @param int $zone_id Zone id.
	 * @return array[] method_id|instance_id|enabled
	 */
	public static function zone_method_rows( $zone_id ) {
		global $wpdb;

		$rows = array();
		if ( isset( $wpdb ) ) {
			$table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT method_id, method_order, instance_id, is_enabled FROM {$table} WHERE zone_id = %d ORDER BY method_order ASC", // phpcs:ignore
					(int) $zone_id
				)
			);
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row->method_id ) ) {
				continue;
			}
			$out[] = array(
				'method_id'   => (string) $row->method_id,
				'instance_id' => (int) $row->instance_id,
				'enabled'     => ! empty( $row->is_enabled ),
			);
		}
		return $out;
	}

	/**
	 * Country codes attached to a zone.
	 *
	 * WooCommerce 3.x - 11.x expose locations as objects (->code, ->type) from
	 * WC_Shipping_Zone::get_zone_locations(); older releases and some filters hand back
	 * arrays. Both shapes are accepted, and the accessor name is probed, because a
	 * "Call to undefined method" here fatals the whole checkout.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @return string[]
	 */
	public static function zone_countries( $zone ) {
		$codes = array();
		foreach ( self::zone_locations( $zone ) as $loc ) {
			$code = $loc['code'];
			$type = $loc['type'];
			if ( '' === $code ) {
				continue;
			}
			if ( 'country' === $type ) {
				$codes[] = strtoupper( $code );
			} elseif ( 'state' === $type ) {
				$parts = explode( ':', $code );
				$codes[] = strtoupper( $parts[0] );
			} elseif ( 'continent' === $type || 'zone' === $type ) {
				foreach ( self::continent_countries( $code ) as $c ) {
					$codes[] = $c;
				}
			}
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * A zone's locations, normalised to code + type.
	 *
	 * WooCommerce 11.x exposes them as objects from get_zone_locations(); older
	 * releases used get_locations() and arrays. Calling a method that the installed
	 * version does not define fatals the request, so the accessor is probed.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @return array[] code|type
	 */
	public static function zone_locations( $zone ) {
		$raw = array();
		if ( is_object( $zone ) ) {
			if ( method_exists( $zone, 'get_zone_locations' ) ) {
				$raw = (array) $zone->get_zone_locations();
			} elseif ( method_exists( $zone, 'get_locations' ) ) {
				$raw = (array) $zone->get_locations();
			}
		}

		$out = array();
		foreach ( $raw as $loc ) {
			$code = '';
			$type = 'country';
			if ( is_object( $loc ) ) {
				if ( isset( $loc->code ) ) {
					$code = (string) $loc->code;
				} elseif ( isset( $loc->location_code ) ) {
					$code = (string) $loc->location_code;
				}
				if ( isset( $loc->type ) ) {
					$type = (string) $loc->type;
				} elseif ( isset( $loc->location_type ) ) {
					$type = (string) $loc->location_type;
				}
			} elseif ( is_array( $loc ) ) {
				if ( isset( $loc['code'] ) ) {
					$code = (string) $loc['code'];
				} elseif ( isset( $loc['location_code'] ) ) {
					$code = (string) $loc['location_code'];
				}
				if ( isset( $loc['type'] ) ) {
					$type = (string) $loc['type'];
				} elseif ( isset( $loc['location_type'] ) ) {
					$type = (string) $loc['location_type'];
				}
			}
			$out[] = array(
				'code' => $code,
				'type' => $type,
			);
		}
		return $out;
	}

	/**
	 * @param string $zone_key Continent key from WC_Continents.
	 * @return string[]
	 */
	public static function continent_countries( $zone_key ) {
		$out = array();
		if ( ! class_exists( 'WC_Continents' ) ) {
			return $out;
		}
		$zones = WC_Continents::instance()->get_zones();
		if ( isset( $zones[ $zone_key ]['countries'] ) ) {
			foreach ( $zones[ $zone_key ]['countries'] as $code => $name ) {
				$out[] = strtoupper( (string) $code );
			}
		}
		return $out;
	}

	/**
	 * A zone's name, using whichever accessor the installed WooCommerce defines.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @return string
	 */
	public static function zone_name( $zone ) {
		if ( ! is_object( $zone ) ) {
			return '';
		}
		foreach ( array( 'get_zone_name', 'get_name' ) as $fn ) {
			if ( method_exists( $zone, $fn ) ) {
				return (string) $zone->$fn();
			}
		}
		if ( method_exists( $zone, 'get_formatted_location' ) ) {
			return (string) $zone->get_formatted_location();
		}
		return '';
	}

	/**
	 * The label a customer sees on this rate.
	 *
	 * One supplier  -> the method title, usually just "Shipping".
	 * Several       -> the multi-supplier format, "Shipping (2 providers)".
	 * A delivery estimate is appended only for a single supplier, so the merged label
	 * stays short and honest about what it covers.
	 *
	 * @param string[] $providers Providers that contributed cost.
	 * @param string   $title     Method title.
 * @param string   $format    Multi-supplier format; %s is replaced by the count.
	 * @param string   $time      Delivery estimate, e.g. "9-14 days".
	 * @param bool     $show_time Append the estimate.
	 * @return string
	 */
	public static function provider_label( $providers, $title, $format = 'Shipping (%s providers)', $time = '', $show_time = false ) {
		$providers = array_values( array_unique( array_filter( (array) $providers ) ) );
		$title     = trim( (string) $title );
		if ( '' === $title ) {
			$title = 'Shipping';
		}

		if ( count( $providers ) > 1 ) {
			$fmt = trim( (string) $format );
			if ( '' === $fmt || ! substr_count( $fmt, '%s' ) ) {
				$fmt = 'Shipping (%s providers)';
			}
			// Only ever the single %s in the format: an unknown %d in a typed format
			// would make sprintf complain and print a half-built label.
			return preg_replace( '/%s/', (string) count( $providers ), $fmt, 1 );
		}

		if ( $show_time && '' !== trim( (string) $time ) ) {
			return $title . ' (' . trim( (string) $time ) . ')';
		}
		return $title;
	}

	/**
	 * Merge supplier-split packages back into one package per destination.
	 *
	 * Printify's plugin splits a cart into "ours" and "theirs" packages, and WooCommerce
	 * then shows a separate shipment - with its own shipping choice - for each one. That
	 * is the opposite of one price for the customer's whole basket, so the split is undone
	 * here before rates are worked out: same destination means one package, and this
	 * method then quotes every supplier inside it and prices it as a single rate.
	 *
	 * Item keys are namespaced by their source package because a supplier's split reindexes
	 * contents numerically, and plain merging would drop items.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public static function merge_packages( $packages ) {
		if ( ! is_array( $packages ) || count( $packages ) < 2 ) {
			return $packages;
		}

		$groups = array();
		foreach ( $packages as $index => $package ) {
			if ( ! is_array( $package ) || empty( $package['contents'] ) ) {
				continue;
			}

			$dest  = isset( $package['destination'] ) ? (array) $package['destination'] : array();
			$class = isset( $package['shipping_class'] ) ? (string) $package['shipping_class'] : '';
			$key   = md5( json_encode( array( $dest, $class ) ) );

			if ( ! isset( $groups[ $key ] ) ) {
				$g = $package;
				unset( $g['managed_by_printify'], $g['printify_shipping_rates'], $g['managed_by_printful'], $g['printful_rates'] );
				$g['contents']       = array();
				$g['contents_cost']  = 0.0;
				$g['weight']         = 0.0;
				$g['tsm_merged_from'] = array();
			} else {
				$g = $groups[ $key ];
			}

			$g['tsm_merged_from'][] = isset( $package['id'] ) ? (string) $package['id'] : ( 'pkg' . $index );

			foreach ( (array) $package['contents'] as $k => $item ) {
				$g['contents'][ $index . ':' . $k ] = $item;
				if ( isset( $item['line_total'] ) ) {
					$g['contents_cost'] += (float) $item['line_total'];
				}
			}
			if ( isset( $package['weight'] ) ) {
				$g['weight'] += (float) $package['weight'];
			}

			$groups[ $key ] = $g;
		}

		$out = array_values( $groups );
		return $out ? $out : $packages;
	}

	/**
	 * Display name of a supplier in the admin breakdown.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	public static function provider_name( $provider ) {
		$names = array(
			'tapstitch' => 'Tapstitch',
			'printify'  => 'Printify',
			'printful'  => 'Printful',
			'fixed'     => 'In-house',
			'in-house'  => 'In-house',
			'unmatched' => 'Other items',
			'none'      => 'Excluded',
		);
		$provider = strtolower( (string) $provider );
		return isset( $names[ $provider ] ) ? $names[ $provider ] : ucfirst( $provider );
	}

	/**
	 * The provider sub-totals an admin sees under the rate label.
	 *
	 * Groups priced by a live-quoting supplier are zero in the per-class maths (their cost
	 * arrives once, for the whole package), so those placeholder lines are dropped and the
	 * supplier's own line is added - otherwise a mixed cart shows a $0.00 row that means
	 * nothing.
	 *
	 * @param array $groups    Each: provider|note|cost.
	 * @param array $suppliers Provider => cost, from the supplier quotes.
	 * @param string $symbol   Currency symbol.
	 * @return array[] provider|note|cost
	 */
	public static function build_breakdown( $groups, $suppliers = array(), $symbol = '$' ) {
		$lines = array();

		foreach ( (array) $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$cost     = (float) ( isset( $group['cost'] ) ? $group['cost'] : 0 );
			$provider = isset( $group['provider'] ) ? (string) $group['provider'] : '';
			if ( $cost <= 0 && isset( $suppliers[ $provider ] ) ) {
				continue;
			}
			$lines[] = array(
				'provider' => self::provider_name( $provider ),
				'note'     => (string) ( isset( $group['note'] ) ? $group['note'] : '' ),
				'cost'     => $symbol . number_format( $cost, 2 ),
			);
		}

		foreach ( (array) $suppliers as $who => $amount ) {
			$lines[] = array(
				'provider' => self::provider_name( $who ),
				'note'     => 'live quote, once for the package',
				'cost'     => $symbol . number_format( (float) $amount, 2 ),
			);
		}

		return $lines;
	}

	/**
	 * Sub-totals stored on a rate, read back for display.
	 *
	 * @param WC_Shipping_Rate $rate Rate.
	 * @return array[]
	 */
	public static function meta_breakdown( $rate ) {
		if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta_data' ) ) {
			return array();
		}
		$meta = (array) $rate->get_meta_data();
		if ( empty( $meta['tapstitch_breakdown'] ) ) {
			return array();
		}
		$json = json_decode( (string) $meta['tapstitch_breakdown'], true );
		if ( ! is_array( $json ) ) {
			return array();
		}

		$out = array();
		foreach ( $json as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$out[] = array(
				'provider' => isset( $line['provider'] ) ? (string) $line['provider'] : '',
				'note'     => isset( $line['note'] ) ? (string) $line['note'] : '',
				'cost'     => isset( $line['cost'] ) ? (string) $line['cost'] : '',
			);
		}
		return $out;
	}

	/**
	 * Every country WooCommerce knows about (used for the catch-all zone).
	 *
	 * @return string[]
	 */
	public static function all_countries() {
		static $all = null;
		if ( null !== $all ) {
			return $all;
		}
		$all = array();
		if ( function_exists( 'WC' ) && WC()->countries ) {
			foreach ( (array) WC()->countries->get_allowed_countries() as $code => $name ) {
				$all[] = strtoupper( (string) $code );
			}
		}
		if ( empty( $all ) && class_exists( 'WC_Continents' ) ) {
			foreach ( WC_Continents::instance()->get_zones() as $zone ) {
				foreach ( $zone['countries'] as $code => $name ) {
					$all[] = strtoupper( (string) $code );
				}
			}
		}
		return $all;
	}

	/**
	 * Status summary for the admin screen.
	 *
	 * @return array
	 */
	public static function status() {
		$snapshot = get_option( self::STALE_KEY, array() );
		$rows     = isset( $snapshot['rows'] ) ? $snapshot['rows'] : array();
		$countries = array();
		$cats      = array();
		$lines     = array();
		foreach ( (array) $rows as $row ) {
			$countries[ $row['country'] ] = true;
			$cats[ $row['slug'] ]         = true;
			$lines[ $row['line'] ]        = true;
		}
		return array(
			'endpoint'   => TSM_API::endpoint(),
			'last_fetch' => (int) get_option( 'tapstitch_sm_last_fetch', 0 ),
			'snapshot'   => (int) ( isset( $snapshot['fetched'] ) ? $snapshot['fetched'] : 0 ),
			'rows'       => count( $rows ),
			'countries'  => count( $countries ),
			'categories' => count( $cats ),
			'lines'      => implode( ', ', array_keys( $lines ) ),
			'seed'       => count( self::seed_rows() ),
		);
	}
}
