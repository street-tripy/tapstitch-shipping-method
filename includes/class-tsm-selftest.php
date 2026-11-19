<?php
/**
 * Pure-maths self-test.
 *
 * No WordPress, no network. It checks the rate maths the plugin charges with,
 * and the admin Self-check tab shows the result, so nothing has to be trusted
 * from a screenshot.
 *
 * Expected values are hand-computed from a synthetic supplier row (no published
 * supplier table is used here): first 6.00, additional 2.00-3.00, weight 700 g, 6-10 days.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_Selftest {

	/**
	 * @return array list of [ name, pass, detail ]
	 */
	public static function run() {
		$out   = array();
		$assert = function ( $name, $expected, $actual, $tolerance = 0.005 ) use ( &$out ) {
			$pass = is_string( $expected )
				? ( trim( (string) $expected ) === trim( (string) $actual ) )
				: ( abs( (float) $expected - (float) $actual ) <= $tolerance );
			$out[] = array(
				'name'   => $name,
				'pass'   => $pass,
				'detail' => 'expected ' . ( is_string( $expected ) ? '"' . $expected . '"' : number_format( (float) $expected, 2 ) )
					. ', got ' . ( is_string( $actual ) ? '"' . $actual . '"' : number_format( (float) $actual, 2 ) ),
			);
		};

		$hoodies_us = array(
			'country'     => 'US',
			'slug'        => 'hoodies',
			'category'    => 'Hoodies',
			'fulfillment' => 'International',
			'line'        => 'Standard',
			'time_min'    => 6,
			'time_max'    => 10,
			'first'       => 6.00,
			'add_min'     => 2.00,
			'add_max'     => 3.00,
			'weight'      => 700,
		);

		// 1. API payload -> rows.
		$payload = array(
			'data' => array(
				'list' => array(
					array(
						'country'                     => array( 'code' => 'US', 'name' => 'United States' ),
						'categoryName'                => 'Hoodies',
						'fulfillmentService'          => 'International',
						'shippingLine'                => 'Standard',
						'shippingTimeRange'           => array( 'min' => 6, 'max' => 10 ),
						'shippingCost'                => 600,
						'additionalShippingCostRange' => array( 'min' => 200, 'max' => 300 ),
						'weight'                      => 700,
					),
					array(
						'country'                     => array( 'code' => 'XX' ),
						'categoryName'                => 'Hoodies',
						'shippingLine'                => '',
						'shippingCost'                => 0,
					),
				),
			),
		);
		$rows = TSM_API::normalize( $payload );
		$assert( 'API parse: one usable row, junk row dropped', 1, count( $rows ) );
		$assert( 'API parse: cents to dollars', 6.00, isset( $rows[0]['first'] ) ? $rows[0]['first'] : 0 );
		$assert( 'API parse: additional top of range', 3.00, isset( $rows[0]['add_max'] ) ? $rows[0]['add_max'] : 0 );
		$assert( 'API parse: category slug', 'hoodies', isset( $rows[0]['slug'] ) ? $rows[0]['slug'] : '' );

		// 2. Aggregation.
		$ca          = $hoodies_us;
		$ca['country'] = 'CA';
		$ca['first']   = 7.00;
		$ca['add_min'] = 1.80;
		$ca['add_max'] = 2.40;
		$agg         = TSM_Rates::aggregate( array( $hoodies_us, $ca ), 'max' );
		$rate        = isset( $agg['hoodies'] ) ? $agg['hoodies'] : array();
		$assert( 'Zone aggregation: most expensive country wins', 7.00, isset( $rate['first'] ) ? $rate['first'] : 0 );
		$assert( 'Zone aggregation: highest additional rate wins', 3.00, isset( $rate['add_max'] ) ? $rate['add_max'] : 0 );
		$assert( 'Zone aggregation: worst country label', 'CA', isset( $rate['worst'] ) ? $rate['worst'] : '' );

		$single = TSM_Rates::aggregate( array( $hoodies_us ), 'max' );
		$one    = $single['hoodies'];

		// 3. Tapstitch's own first + additional maths.
		$opts = array(
			'handling' => 1.5,
			'pct'      => 0,
			'floor'    => 0,
			'cap'      => 0,
			'bound'    => 'max',
			'round'    => 'none',
		);
		$assert( '1 item: first + handling', 7.50, TSM_Rates::cost( $one, 1, $opts ) );
		$assert( '2 items: first + handling + 1 extra', 10.50, TSM_Rates::cost( $one, 2, $opts ) );
		$assert( '3 items: first + handling + 2 extra', 13.50, TSM_Rates::cost( $one, 3, $opts ) );
		$assert( 'Additional bound: bottom of range', 2.00, TSM_Rates::bound( $one, 'min' ) );
		$assert( 'Additional bound: middle of range', 2.50, TSM_Rates::bound( $one, 'mid' ) );
		$assert( 'Additional bound: top of range', 3.00, TSM_Rates::bound( $one, 'max' ) );

		// 4. Guards.
		$assert( 'Floor lifts a cheap rate', 12.0, TSM_Rates::cost( $one, 1, array_merge( $opts, array( 'floor' => 12 ) ) ) );
		$assert( 'Cap limits a runaway rate', 99.0, TSM_Rates::cost( $one, 40, array_merge( $opts, array( 'cap' => 99 ) ) ) );
		$assert( 'Percentage uplift on the whole total', 8.25, TSM_Rates::cost( $one, 1, array_merge( $opts, array( 'pct' => 10 ) ) ) );

		// 5. Rounding never undercuts the real cost.
		$assert( 'Rounding 10.83 -> 10.99', 10.99, TSM_Rates::round_money( 10.83, 'nearest99' ) );
		$assert( 'Rounding 10.99 stays', 10.99, TSM_Rates::round_money( 10.99, 'nearest99' ) );
		$assert( 'Rounding 11.5 -> 11.99 (not 10.99)', 11.99, TSM_Rates::round_money( 11.5, 'nearest99' ) );
		$assert( 'Rounding 12.0 int99 -> 12.99', 12.99, TSM_Rates::round_money( 12.0, 'int99' ) );
		$assert( 'No rounding keeps cents', 12.0, TSM_Rates::round_money( 11.995, 'none' ) );
		$round_up = TSM_Rates::round_money( 14.44, 'nearest99' );
		$out[] = array(
			'name'   => 'Rounding is never below cost',
			'pass'   => $round_up >= 14.44,
			'detail' => '14.44 -> ' . number_format( (float) $round_up, 2 ),
		);

		// 6. Core Flat Rate export string must match the plugin's own maths.
		$formula = TSM_Rates::formula( $one, array( 'handling' => 1.5, 'bound' => 'max' ) );
		$assert( 'Flat Rate formula string', '7.50 + 3.00 * ( [qty] - 1 )', $formula );
		$evaluated = 7.50 + 3.00 * ( 3 - 1 );
		$assert( 'Formula and plugin cost agree at 3 items', TSM_Rates::cost( $one, 3, $opts ), $evaluated );

		// 7. Slug tolerance.
		$assert( 'Slug of "Pants & Sweatpants"', 'pants-and-sweatpants', TSM_API::slug( 'Pants & Sweatpants' ) );
		$found = TSM_Rates::find( TSM_Rates::aggregate( array( array_merge( $hoodies_us, array( 'slug' => 'hoodies', 'category' => 'Hoodies' ) ) ), 'max' ), 'hoodies' );
		$assert( 'find() matches an exact slug', 6.00, $found ? $found['first'] : 0 );
		$agg2 = array( 'tank-tops' => array_merge( $hoodies_us, array( 'slug' => 'tank-tops', 'category' => 'Tank Tops' ) ) );
		$found2 = TSM_Rates::find( $agg2, 'tanktops' );
		$assert( 'find() tolerates tanktops vs tank-tops', 6.00, $found2 ? $found2['first'] : 0 );
		$agg3 = array( 'hoodies' => $hoodies_us );
		$assert( 'find() returns null for an unmapped category', 'none', TSM_Rates::find( $agg3, 'stickers' ) ? 'found' : 'none' );

		// Labels: one supplier = the plain title; several = the merged label with the count.
		$assert(
			'Label with one provider is the title',
			'Shipping',
			TSM_Rates::provider_label( array( 'tapstitch' ), 'Shipping', 'Shipping (%s providers)', '6-10 days', false )
		);
		$assert(
			'Label with two providers counts them',
			'Shipping (2 providers)',
			TSM_Rates::provider_label( array( 'tapstitch', 'printify' ), 'Shipping', 'Shipping (%s providers)', '', false )
		);
		$assert(
			'Label with three providers counts them',
			'Shipping (3 providers)',
			TSM_Rates::provider_label( array( 'tapstitch', 'printify', 'printful' ), 'Shipping', 'Shipping (%s providers)', '', false )
		);
		$assert(
			'Label repeats of the same provider count once',
			'Shipping (2 providers)',
			TSM_Rates::provider_label( array( 'tapstitch', 'printify', 'printify' ), 'Shipping', 'Shipping (%s providers)', '', false )
		);
		$assert(
			'Delivery estimate appends for a single provider',
			'Shipping (6-10 days)',
			TSM_Rates::provider_label( array( 'tapstitch' ), 'Shipping', 'Shipping (%s providers)', '6-10 days', true )
		);
		$assert(
			'Merged label carries no delivery estimate',
			'Shipping (2 providers)',
			TSM_Rates::provider_label( array( 'tapstitch', 'printify' ), 'Shipping', 'Shipping (%s providers)', '6-10 days', true )
		);
		$assert(
			'A format without %s falls back to the default wording',
			'Shipping (2 providers)',
			TSM_Rates::provider_label( array( 'tapstitch', 'printify' ), 'Shipping', 'Combined shipping', '', false )
		);

		// Merging supplier quotes must never round down below what the suppliers cost.
		$sum  = 7.50 + 4.00; // one provider's parcel (6.00 + 1.50 handling) + another supplier's live cost
		$join = TSM_Rates::round_money( $sum, 'nearest99' );
		$assert( 'Merged cost rounds up, never below the suppliers\' cost', true, $join >= $sum && abs( $join - 11.99 ) < 0.001 );

		// Merging supplier-split packages: one destination means one shipping price.
		$dest      = array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001' );
		$split     = array(
			array(
				'id'                  => '1e08e819f7545b6e2e0a3070279634',
				'destination'         => $dest,
				'contents'            => array( 0 => array( 'line_total' => 65, 'quantity' => 2, 'data' => 'hoodie' ) ),
				'contents_cost'       => 65,
				'weight'              => 1.7,
				'managed_by_printify' => true,
			),
			array(
				'id'            => '60bb076120872ff4c0085046b283e9',
				'destination'   => $dest,
				'contents'      => array( 0 => array( 'line_total' => 7, 'quantity' => 1, 'data' => 'sticker' ) ),
				'contents_cost' => 7,
				'weight'        => 0.01,
			),
		);
		$merged     = TSM_Rates::merge_packages( $split );
		$assert( 'Supplier split merges back to one package', 1, count( $merged ) );
		$assert( 'Merge keeps both items (numeric keys never collide)', 2, count( reset( $merged )['contents'] ) );
		$assert( 'Merge adds the goods value', 72.0, (float) reset( $merged )['contents_cost'] );
		$assert( 'Merge drops the supplier package flag', false, isset( reset( $merged )['managed_by_printify'] ) );

		$far          = $split;
		$far[1]['destination'] = array( 'country' => 'DE', 'state' => '', 'postcode' => '10115' );
		$kept         = TSM_Rates::merge_packages( $far );
		$assert( 'Different destinations are never merged', 2, count( $kept ) );

		// Admin sub-totals under the rate label.
		$bd = TSM_Rates::build_breakdown(
			array(
				array(
					'provider' => 'tapstitch',
					'note'     => 'hoodies x2 - first $6.00 + $3.00 per extra x2',
					'cost'     => 13.50,
				),
				array(
					'provider' => 'printify',
					'note'     => 'unclassed x1 - supplier goods, quoted once for the package',
					'cost'     => 0.0,
				),
			),
			array( 'printify' => 4.20 ),
			'$'
		);
		$assert( 'Breakdown drops the zero supplier placeholder line', 2, count( $bd ) );
		$assert( 'Breakdown names the suppliers', 'Tapstitch', $bd[0]['provider'] );
		$assert( 'Breakdown adds the supplier line at its live cost', '$4.20', $bd[1]['cost'] );
		$assert( 'Breakdown shows the priced group cost', '$13.50', $bd[0]['cost'] );
		$assert( 'Provider name for an unknown key is readable', 'Wholesale', TSM_Rates::provider_name( 'wholesale' ) );

		// The purchase guard: no quote, no sale.
		$assert( 'Guard allows when shipping is not needed', 'allow', TSM_Guard::decide( false, array(), array() )['action'] );
		// The handover rule: this plugin yields a zone's checkout rate to the provider registry
		// only when the registry is Live *and* that zone has a Provider Shipping instance.
		$assert( 'Registry Off: this plugin keeps pricing the zone', 'no', TSM_Packages::registry_owns_decision( 'off', array( 1 ), 1 ) ? 'yes' : 'no' );
		$assert( 'Registry Shadow: this plugin keeps pricing the zone', 'no', TSM_Packages::registry_owns_decision( 'shadow', array( 1 ), 1 ) ? 'yes' : 'no' );
		$assert( 'Registry Live with an instance in this zone: this plugin yields', 'yes', TSM_Packages::registry_owns_decision( 'live', array( 1 ), 1 ) ? 'yes' : 'no' );
		$assert( 'Registry Live without an instance in this zone: this plugin prices it', 'yes', TSM_Packages::registry_owns_decision( 'live', array( 2 ), 1 ) ? 'no' : 'yes' );
		// ... and it never yields on a registry sub-package, because that sub-package is the
		// registry asking THIS method for the price - yielding there is a deadlock with no rate.
		$assert( 'A registry sub-package is answered, never deferred', 'no', TSM_Packages::should_defer( 'live', array( 1 ), 1, true ) ? 'yes' : 'no' );
		$assert( 'An ordinary checkout package is deferred', 'yes', TSM_Packages::should_defer( 'live', array( 1 ), 1, false ) ? 'yes' : 'no' );
		$assert( 'Guard blocks when no package exists', 'block', TSM_Guard::decide( true, array(), array() )['action'] );
		$assert( 'Guard blocks when a package has no quotes', 'block', TSM_Guard::decide( true, array( 0 => array() ), array( 'flat_rate:1' ) )['action'] );
		$assert( 'Guard blocks when nothing is chosen', 'block', TSM_Guard::decide( true, array( 0 => array( 'tapstitch_shipping_1' => 1 ) ), array() )['action'] );
		$assert( 'Guard blocks when the choice is not a quoted price', 'block', TSM_Guard::decide( true, array( 0 => array( 'tapstitch_shipping_1' => 1 ) ), array( 'flat_rate:9' ) )['action'] );
		$assert( 'Guard allows when every package has its chosen price', 'allow', TSM_Guard::decide( true, array( 0 => array( 'tapstitch_shipping_1' => 1 ), 1 => array( 'printify_shipping_s' => 1 ) ), array( 'tapstitch_shipping_1', 'printify_shipping_s' ) )['action'] );

		return $out;
	}

	/**
	 * @return array [ total, passed, failed ]
	 */
	public static function summary() {
		$results = self::run();
		$pass    = 0;
		foreach ( $results as $r ) {
			if ( $r['pass'] ) {
				$pass++;
			}
		}
		return array(
			'total'  => count( $results ),
			'passed' => $pass,
			'failed' => count( $results ) - $pass,
		);
	}
}
