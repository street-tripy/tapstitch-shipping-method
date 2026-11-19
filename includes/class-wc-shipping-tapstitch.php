<?php
/**
 * WC_Shipping_Tapstitch - shipping method.
 *
 * Add it to a shipping zone like any other method. It reads the zone's own
 * country list, pulls Tapstitch's rates for the mapped product categories,
 * and charges Tapstitch's published "first item + additional per extra item"
 * model, per shipping class, plus a handling amount.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	return;
}

class WC_Shipping_Tapstitch extends WC_Shipping_Method {

	/** @var string Rate source used for the last calculation: cache|live|snapshot|seed|none. */
	public $rate_source = '';

	/** @var array Audit trail of what was charged and why. */
	public $rate_trace = array();

	/**
	 * @param int $instance_id Zone method instance id.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'tapstitch_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Tapstitch Shipping', 'tapstitch-shipping-method' );
		$this->method_description = __( 'Charges the shipping rates Tapstitch publishes, by destination country and product category, using their first-item + additional-item model. Rates are cached; checkout never depends on a live request.', 'tapstitch-shipping-method' );
		$this->method_supports    = array();
		// 'shipping-zones' matters: without it WooCommerce also runs a GLOBAL instance of
		// this method (instance id 0) on every package, and that produced a third,
		// unconfigured rate on the customer's checkout.
		$this->supports           = array( 'shipping-zones', 'shipping-instance-methods', 'quantities', 'shipping-calculations' );
		$this->enabled            = 'yes';
		$this->title              = __( 'Standard shipping', 'tapstitch-shipping-method' );

		$this->init();
	}

	/**
	 * Load settings.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		// A zone method with no stored "enabled" flag is enabled (the zone list does the filtering).
		$enabled        = $this->get_option( 'enabled' );
		$this->enabled  = ( '' === $enabled || is_null( $enabled ) ) ? 'yes' : $enabled;
		$this->title    = $this->get_option( 'title' );
		if ( '' === $this->title ) {
			$this->title = __( 'Shipping', 'tapstitch-shipping-method' );
		}

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

		// Sub-totals under the rate label: rendered from the rate's own meta, so it works
		// when WooCommerce re-shows a rate it cached in the session.
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'after_rate_breakdown' ), 10, 2 );

		// One rate on the customer's screen, and one package per destination, are wired by
		// TSM_Packages at plugin boot - see includes/class-tsm-packages.php - because a
		// method instance is constructed too late to affect how packages are built.
	}

	/**
	 * Admin-only provider sub-totals, printed under the rate's own label.
	 *
	 * WooCommerce's shipping template fires woocommerce_after_shipping_rate immediately after
	 * each rate label, which is the supported place to add this - no template override, no
	 * script. The line is rendered only for a logged-in user with the capability named in
	 * "Show sub-totals to users who can", and never for a customer.
	 *
	 * Data is read from the rate's own meta, because WooCommerce keeps chosen rates in the
	 * session and re-renders them on later requests, when this object is a fresh instance.
	 *
	 * @param WC_Shipping_Rate $rate  The rate being displayed.
	 * @param int              $index Package index.
	 */
	public function after_rate_breakdown( $rate = null, $index = 0 ) {
		if ( ! $rate instanceof WC_Shipping_Rate ) {
			return;
		}
		if ( 'yes' !== $this->get_option( 'admin_breakdown' ) ) {
			return;
		}
		if ( (string) $rate->get_method_id() !== $this->id || (int) $rate->get_instance_id() !== (int) $this->instance_id ) {
			return;
		}
		if ( ! $this->user_may_see_breakdown() ) {
			return;
		}

		$lines = TSM_Rates::meta_breakdown( $rate );
		if ( empty( $lines ) ) {
			return;
		}

		echo '<ul class="tsm-breakdown">';
		foreach ( $lines as $line ) {
			echo '<li>'
				. '<span class="tsm-bd-provider">' . esc_html( $line['provider'] ) . '</span>'
				. '<span class="tsm-bd-note">' . esc_html( $line['note'] ) . '</span>'
				. '<span class="tsm-bd-cost">' . esc_html( $line['cost'] ) . '</span>'
				. '</li>';
		}
		echo '</ul>';
	}

	/**
	 * May this visitor see the supplier maths? Store managers only.
	 *
	 * @return bool
	 */
	public function user_may_see_breakdown() {
		static $seen = null;
		if ( null !== $seen ) {
			return $seen;
		}
		$cap = trim( (string) $this->get_option( 'admin_breakdown_cap' ) );
		if ( '' === $cap ) {
			$cap = 'manage_woocommerce';
		}
		$seen = function_exists( 'current_user_can' ) ? (bool) current_user_can( $cap ) : false;
		return $seen;
	}

	/**
	 * Field definitions.
	 *
	 * WooCommerce builds the per-zone settings screen from
	 * $this->instance_form_fields, set here (the core pattern).
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/disable', 'tapstitch-shipping-method' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this method for the zone', 'tapstitch-shipping-method' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Method title', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'description' => __( 'The name the customer sees. Delivery days are appended when "Show delivery estimate" is on.', 'tapstitch-shipping-method' ),
				'default'     => __( 'Shipping', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'combine'     => array(
				'title'   => __( 'Merge suppliers into one rate', 'tapstitch-shipping-method' ),
				'type'    => 'checkbox',
				'label'   => __( 'Quote every supplier in the cart and show a single combined shipping price', 'tapstitch-shipping-method' ),
				'default' => 'yes',
				'description' => __( 'On: Tapstitch goods are priced from Tapstitch\'s published rates, Printify goods from Printify\'s live cost, Printful goods from Printful\'s live cost, and the total is shown as one rate. Off: this method only prices the goods it owns and leaves the rest to that supplier\'s method.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'multi_label' => array(
				'title'       => __( 'Multi-supplier title', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'description' => __( 'Used when a cart is shipped by more than one supplier. %s is replaced by the number of suppliers.', 'tapstitch-shipping-method' ),
				'default'     => __( 'Shipping (%s providers)', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'supplier_claims' => array(
				'title'   => __( 'Let suppliers claim items', 'tapstitch-shipping-method' ),
				'type'    => 'checkbox',
				'label'   => __( 'When Printify says a SKU is its own, price those items at Printify\'s live cost', 'tapstitch-shipping-method' ),
				'default' => 'yes',
				'description' => __( 'Printify decides by SKU from its own account, which is the only reliable answer. Turn this off to force your own declaration (Provider per shipping class) to win, so for example hoodies are always priced on Tapstitch\'s published rates.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'hide_other'  => array(
				'title'   => __( 'Show only this rate', 'tapstitch-shipping-method' ),
				'type'    => 'checkbox',
				'label'   => __( 'At checkout, hide the store\'s other shipping methods for the zone this method prices', 'tapstitch-shipping-method' ),
				'default' => 'yes',
				'description' => __( 'Hiding happens at checkout only. The WooCommerce shipping screens keep every method, and zones this method does not price are untouched. Printify wipes and rebuilds the method list for the packages it claims - this setting puts this rate back and keeps the customer on a single option.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'admin_breakdown' => array(
				'title'   => __( 'Show provider sub-totals to admins', 'tapstitch-shipping-method' ),
				'type'    => 'checkbox',
				'label'   => __( 'Under the rate label, list what each supplier contributed', 'tapstitch-shipping-method' ),
				'default' => 'yes',
				'description' => __( 'Logged-in only, and only for users with the capability below. Customers see the rate label and nothing else - the numbers underneath are for the store owner to check a quote against the supplier.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'admin_breakdown_cap' => array(
				'title'       => __( 'Show sub-totals to users who can', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'description' => __( 'A WordPress capability. WooCommerce shop managers have <code>manage_woocommerce</code>; <code>edit_shop_orders</code> also covers staff who only handle orders. Customers never have it.', 'tapstitch-shipping-method' ),
				'default'     => 'manage_woocommerce',
				'desc_tip'    => true,
			),
			'fulfillment' => array(
				'title'       => __( 'Fulfilment origin', 'tapstitch-shipping-method' ),
				'type'        => 'select',
				'options'     => array(
					'International' => __( 'International (Tapstitch default)', 'tapstitch-shipping-method' ),
					'USA'           => __( 'USA fulfilment', 'tapstitch-shipping-method' ),
				),
				'default'     => 'International',
				'description' => __( 'Match this to Store settings in your Tapstitch account (Order Settings > Fulfillment Preferences).', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'lane'        => array(
				'title'       => __( 'Shipping lane', 'tapstitch-shipping-method' ),
				'type'        => 'select',
				'options'     => array(
					'Special Line'     => __( 'Special Line - about 10 days average', 'tapstitch-shipping-method' ),
					'Special Line Pro' => __( 'Special Line Pro - about 7 days average', 'tapstitch-shipping-method' ),
					'Intl. Express'    => __( 'International Express - about 5 days average', 'tapstitch-shipping-method' ),
					'Standard Shipping' => __( 'Standard Shipping - about 25 days average', 'tapstitch-shipping-method' ),
				),
				'default'     => 'Special Line',
				'description' => __( 'Tapstitch picks the lane itself from your store preferences; the customer chooses nothing. Price the lane Tapstitch will actually use.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'bound'       => array(
				'title'       => __( 'Additional-item rate', 'tapstitch-shipping-method' ),
				'type'        => 'select',
				'options'     => array(
					'max' => __( 'Top of the published range (never lose money)', 'tapstitch-shipping-method' ),
					'mid' => __( 'Middle of the published range', 'tapstitch-shipping-method' ),
					'min' => __( 'Bottom of the published range', 'tapstitch-shipping-method' ),
				),
				'default'     => 'max',
				'description' => __( 'Tapstitch publishes additional-item costs as a range. Multi-item carts pay this per extra item.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'price_by'    => array(
				'title'       => __( 'Which country rate to charge', 'tapstitch-shipping-method' ),
				'type'        => 'select',
				'options'     => array(
					'country'  => __( "The customer's own country (fair and precise)", 'tapstitch-shipping-method' ),
					'zone_max' => __( 'The most expensive country in the zone (safest)', 'tapstitch-shipping-method' ),
				),
				'default'     => 'country',
				'description' => __( 'In "own country" mode a German order pays the German rate. If a destination has no published rate the plugin falls back to the zone\'s most expensive rate, then to the fallback cost - so it is never free.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'class_map'   => array(
				'title'       => __( 'WooCommerce shipping class to Tapstitch category', 'tapstitch-shipping-method' ),
				'type'        => 'textarea',
				'css'         => 'width:400px;height:120px;',
				'description' => __( 'One per line: <code>woo-class-slug=tapstitch-category-slug</code>. Example: <code>hoodies=hoodies</code>, <code>t-shirts=t-shirts</code>. Use <code>_default=hoodies</code> for products with no shipping class. Slugs verified against the Tapstitch API on 2026-11-19: hoodies, sweatpants, t-shirts, sweatshirts, shorts, tank-tops, long-sleeve-shirts, sportswear. Tapstitch publishes no sticker/accessory category, so price those in "Fixed costs".', 'tapstitch-shipping-method' ),
				'default'     => "hoodies=hoodies\nsweatpants=sweatpants\nt-shirts=t-shirts\n_default=hoodies",
				'desc_tip'    => true,
			),
			'fixed'       => array(
				'title'       => __( 'Fixed costs (not shipped by Tapstitch)', 'tapstitch-shipping-method' ),
				'type'        => 'textarea',
				'css'         => 'width:400px;height:100px;',
				'description' => __( 'One per line: <code>woo-class-slug=FIRST+PERITEM</code>. Example: <code>stickers=4.99+0.60</code>. Use these for products fulfilled elsewhere (Printful / Printify / stock on hand).', 'tapstitch-shipping-method' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'provider_by_class' => array(
				'title'       => __( 'Which supplier ships each class', 'tapstitch-shipping-method' ),
				'type'        => 'textarea',
				'css'         => 'width:400px;height:110px;',
				'description' => __( 'One per line: <code>woo-class-slug=tapstitch|printify|printful|fixed|none</code>. Products carry no supplier tag on this store (measured 2026-11-19: no <code>printify_product_id</code>, no <code>_printful_product_id</code>, and numeric SKUs are used by apparel and stickers alike), so declare the supplier per class. Use <code>_default=</code> for unclassed products.', 'tapstitch-shipping-method' ),
				'default'     => "hoodies=tapstitch\nsweatpants=tapstitch\nt-shirts=tapstitch\nmerch=fixed\n_default=tapstitch",
				'desc_tip'    => true,
			),
			'printify_meta' => array(
				'title'     => __( 'Detect Printify from product meta', 'tapstitch-shipping-method' ),
				'type'      => 'checkbox',
				'label'     => __( 'printify_product_id / printify_variant_id means Printify', 'tapstitch-shipping-method' ),
				'default'   => 'yes',
				'desc_tip'  => true,
			),
			'printful_meta' => array(
				'title'    => __( 'Detect Printful from product meta', 'tapstitch-shipping-method' ),
				'type'     => 'checkbox',
				'label'    => __( '_printful_product_id means Printful', 'tapstitch-shipping-method' ),
				'default'  => 'yes',
				'desc_tip' => true,
			),
			'sku_pattern' => array(
				'title'       => __( 'SKU pattern hint (weak)', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '/^\\d{15,}$/',
				'description' => __( 'Only consulted when the product has no explicit meta, no supplier meta and its class is not declared. This store has 19-digit and 20-digit numeric SKUs on BOTH apparel and stickers, so do not rely on it.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'provider_default' => array(
				'title'   => __( 'Provider when nothing matches', 'tapstitch-shipping-method' ),
				'type'    => 'select',
				'options' => array(
					'tapstitch' => __( 'Tapstitch', 'tapstitch-shipping-method' ),
					'fixed'     => __( 'Fixed costs', 'tapstitch-shipping-method' ),
					'printify'  => __( 'Printify', 'tapstitch-shipping-method' ),
					'printful'  => __( 'Printful', 'tapstitch-shipping-method' ),
					'none'      => __( 'Nothing (excluded from shipping)', 'tapstitch-shipping-method' ),
				),
				'default' => 'tapstitch',
				'desc_tip' => true,
			),
			'unmatched' => array(
				'title'       => __( 'Cost when a supplier method is absent', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '12.00',
				'description' => __( 'A Printify/Printful class in a zone without that method: charge this (fixed cost wins if one is set for the class).', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'handling'    => array(
				'title'       => __( 'Handling per order (excl. tax)', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '1.50',
				'description' => __( 'Added once to the first item. Covers payment processing on the shipping charge and packing. Set 0 to charge Tapstitch\'s published rate exactly.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'pct'         => array(
				'title'       => __( 'Uplift on shipping total (%)', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Percentage added to the computed shipping total. 0 = off.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'floor'       => array(
				'title'       => __( 'Minimum charge per class', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Never charge less than this for a category that is in the cart. 0 = off.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'cap'         => array(
				'title'       => __( 'Maximum charge per class', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '99.00',
				'description' => __( 'Protects a customer from an absurd rate if a supplier table goes wrong. 0 = off.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'fallback'    => array(
				'title'       => __( 'Fallback when no rate data', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '12.00',
				'description' => __( 'Used if a destination has no published rate and no snapshot. Shipping is never left free.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'min_total'   => array(
				'title'       => __( 'Minimum shipping for the whole cart', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'If the sum of all classes is below this, charge this instead. 0 = off.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'round'       => array(
				'title'       => __( 'Price rounding', 'tapstitch-shipping-method' ),
				'type'        => 'select',
				'options'     => array(
					'nearest99' => __( 'Nearest .99, never below cost', 'tapstitch-shipping-method' ),
					'int99'     => __( 'Whole unit + .99', 'tapstitch-shipping-method' ),
					'none'      => __( 'Exact to the cent', 'tapstitch-shipping-method' ),
				),
				'default'     => 'nearest99',
				'desc_tip'    => true,
			),
			'extra_countries' => array(
				'title'       => __( 'Extra countries to price (aggregation only)', 'tapstitch-shipping-method' ),
				'type'        => 'textarea',
				'css'         => 'width:400px;height:70px;',
				'description' => __( 'ISO codes added to this zone\'s own countries when working out the zone\'s worst-case rate. Does not change who is eligible - WooCommerce decides that from the zone.', 'tapstitch-shipping-method' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'show_time'   => array(
				'title'       => __( 'Show delivery estimate', 'tapstitch-shipping-method' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'description' => __( 'Appends the published transit range to the method name, e.g. "Standard shipping (9-14 days)". Excludes production time.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'mode'        => array(
				'title'       => __( 'Mode', 'tapstitch-shipping-method' ),
				'type'        => 'select',
				'options'     => array(
					'live'    => __( 'Live - charge the computed rate', 'tapstitch-shipping-method' ),
					'preview' => __( 'Preview - charge a fixed price and log what would have been charged', 'tapstitch-shipping-method' ),
				),
				'default'     => 'preview',
				'description' => __( 'Start in Preview, compare the logged rates against what Tapstitch charges on real orders, then switch to Live.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'preview_cost' => array(
				'title'       => __( 'Preview price per class', 'tapstitch-shipping-method' ),
				'type'        => 'text',
				'default'     => '7.99',
				'description' => __( 'What checkout shows while in Preview mode.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
			'debug'       => array(
				'title'       => __( 'Log every calculation', 'tapstitch-shipping-method' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'description' => __( 'Writes the rate, source and country to WooCommerce status logs. Useful for reconciling against supplier invoices.', 'tapstitch-shipping-method' ),
				'desc_tip'    => true,
			),
		);

		// Global defaults mirror the per-zone fields so the method has sane values before a zone saves it.
		$this->form_fields = $this->instance_form_fields;
	}

	/**
	 * Work out shipping for a package.
	 *
	 * @param array $package Package from WooCommerce.
	 */
	public function calculate_shipping( $package = array() ) {
		if ( 'yes' !== $this->enabled || empty( $package['contents'] ) ) {
			return;
		}

		// The provider registry owns this zone's checkout rate. This method stays as the rate
		// source the registry quotes through, and adds no competing rate of its own - two merged
		// rates at the same filter priority cancel each other out, and the customer loses the
		// merged label and the sub-totals.
		//
		// `WC_Shipping_Method::get_zone_id()` does not exist in WooCommerce 11.3 (measured: fatal
		// at 2026-11-19 08:36), the same family of phantom accessors as get_zone_by()/get_name().
		// The zone is read from the method table, which is where WooCommerce keeps it.
		//
		// The `psr_provider` test (inside `defers_for`) is what keeps this from being a deadlock:
		// when Provider Shipping asks this method to price one provider's items, the sub-package it
		// builds carries that key, and this method must answer.
		if ( class_exists( 'TSM_Packages' ) && TSM_Packages::defers_for( (int) $this->instance_id, $package ) ) {
			$this->log( 'DEFERRED to provider-shipping in zone ' . TSM_Packages::zone_for_instance( (int) $this->instance_id ) . ' (registry pipeline live, zone has a Provider Shipping instance)' );
			return;
		}

		$country = isset( $package['destination']['country'] ) ? strtoupper( (string) $package['destination']['country'] ) : '';
		if ( '' === $country ) {
			return;
		}

		$map      = $this->map();
		$fixed    = $this->fixed();
		$by_class = $this->quantities_by_class( $package );

		if ( empty( $by_class ) ) {
			return;
		}

		$countries = $this->lookup_countries();
		$lookup_in = empty( $countries ) ? array( $country ) : $countries;
		$slugs     = $this->tapstitch_slugs();

		$agg  = array();
		$rows = array();

		if ( ! empty( $slugs ) ) {
			$found           = TSM_Rates::lookup( $lookup_in, $slugs, $this->get_option( 'lane' ), $this->get_option( 'fulfillment' ) );
			$rows            = $found['rows'];
			$this->rate_source = $found['source'];
			$agg             = TSM_Rates::aggregate( $rows, $this->get_bound() );
		}

		// "Own country" mode: charge the customer their destination's rate, fall back to the zone's worst.
		$agg_use = $agg;
		if ( 'country' === $this->price_mode() && ! empty( $rows ) ) {
			$mine = TSM_Rates::aggregate( TSM_Rates::only( $rows, array( $country ), $slugs ), $this->get_bound() );
			if ( ! empty( $mine ) ) {
				$agg_use = $mine;
			}
		}

		$preview   = 'preview' === $this->get_option( 'mode' );
		$combine   = 'yes' === $this->get_option( 'combine' );
		$cost      = 0.0;
		$trace     = array();
		$bd_groups = array();
		$time      = $this->time_label( $rows, $agg, $country );
		$providers = array();

		$zone_methods   = $this->zone_method_ids();
		$free_by_design = false;

		// Suppliers that price themselves are asked once per package. Printify decides
		// which SKUs are its own from its account, so its answer - not a tag on the
		// product - is what splits a mixed cart.
		$printify = array(
			'available' => false,
			'skus'      => array(),
			'standard'  => null,
			'source'    => 'not asked (merging is off for this zone)',
		);
		if ( $combine && class_exists( 'TSM_Suppliers' ) && 'yes' === $this->get_option( 'supplier_claims' ) ) {
			$printify = TSM_Suppliers::printify_quote( $package );
		}

		$by_class = $this->groups_by_supplier( $package, ( $combine && 'yes' === $this->get_option( 'supplier_claims' ) ) ? (array) $printify['skus'] : array() );

		if ( empty( $by_class ) ) {
			return;
		}

		// What each live-quoting supplier charges for this whole package.
		$supplier_cost = array();

		foreach ( $by_class as $group ) {
			$item_cost = 0.0;
			$why       = '';
			$class     = (string) $group['class'];
			$provider  = (string) $group['provider'];
			$slug      = $this->slug_for( $class );
			$qty       = (int) $group['qty'];

			if ( $combine && 'printify' === $provider && class_exists( 'TSM_Suppliers' ) ) {
				if ( ! isset( $supplier_cost['printify'] ) ) {
					$supplier_cost['printify'] = ( null !== $printify['standard'] && (float) $printify['standard'] > 0 )
						? (float) $printify['standard']
						: (float) $this->get_option( 'unmatched' );
					$providers['printify'] = true;
				}
				$item_cost = 0.0;
				$why       = 'printify goods, quoted once for the package (' . $printify['source'] . ')';
			} elseif ( $combine && 'printful' === $provider && class_exists( 'TSM_Suppliers' ) ) {
				if ( ! isset( $supplier_cost['printful'] ) ) {
					$quote                   = TSM_Suppliers::printful_quote( $this->sub_package( $package, 'printful' ) );
					$supplier_cost['printful'] = $quote['available'] ? (float) $quote['cost'] : (float) $this->get_option( 'unmatched' );
					$providers['printful']   = true;
					$why                     = 'printful goods, quoted once for the package (' . $quote['source'] . ')';
				} else {
					$why = 'printful goods, included in the package quote';
				}
				$item_cost = 0.0;
			} elseif ( $combine && 'fixed' === $provider ) {
				if ( ! isset( $supplier_cost['in-house'] ) ) {
					$quote                       = $this->in_house_quote( $package );
					$supplier_cost['in-house']   = $quote['available'] ? (float) $quote['cost'] : (float) $this->get_option( 'unmatched' );
					$providers['in-house']       = true;
					$why                         = 'in-house goods, quoted by the zone at ' . $quote['source'];
				} else {
					$why = 'in-house goods, included in the package quote';
				}
				$item_cost = 0.0;
			} elseif ( in_array( $provider, array( 'printify', 'printful' ), true ) && in_array( $provider . '_shipping', $zone_methods, true ) ) {
				$item_cost      = 0.0;
				$why            = 'left to the ' . $provider . ' method in this zone';
				$free_by_design = true;
			} elseif ( 'none' === $provider ) {
				$item_cost      = 0.0;
				$why            = 'excluded, provider none';
				$free_by_design = true;
			} elseif ( isset( $fixed[ $class ] ) ) {
				$rule      = $fixed[ $class ];
				$item_cost = $rule['first'] + ( $qty > 1 ? $rule['per_item'] * ( $qty - 1 ) : 0 );
				$item_cost = TSM_Rates::round_money( $item_cost, $this->round_mode() );
				$providers['fixed'] = true;
				$why       = 'fixed ' . $this->money( $rule['first'] ) . '+' . $this->money( $rule['per_item'] ) . ' x' . $qty;
			} elseif ( in_array( $provider, array( 'printify', 'printful' ), true ) ) {
				$item_cost = TSM_Rates::round_money( (float) $this->get_option( 'unmatched' ), 'none' );
				$why       = $provider . ' goods, no ' . $provider . ' method in this zone';
			} elseif ( $preview ) {
				$item_cost = TSM_Rates::round_money( (float) $this->get_option( 'preview_cost' ), 'none' );
				$why       = 'preview';
			} elseif ( '' !== $slug && TSM_Rates::find( $agg_use, $slug ) ) {
				$rate      = TSM_Rates::find( $agg_use, $slug );
				$item_cost = TSM_Rates::cost(
					$rate,
					$qty,
					array(
						'handling' => (float) $this->get_option( 'handling' ),
						'pct'      => (float) $this->get_option( 'pct' ),
						'floor'    => (float) $this->get_option( 'floor' ),
						'cap'      => (float) $this->get_option( 'cap' ),
						'bound'    => $this->get_bound(),
						'round'    => $this->round_mode(),
					)
				);
				$providers['tapstitch'] = true;
				$why = $slug . ' first ' . $this->money( $rate['first'] ) . ' + ' . $this->money( TSM_Rates::bound( $rate, $this->get_bound() ) ) . ' per extra x' . $qty . ' [' . $this->rate_source . ']';
			} else {
				$item_cost = TSM_Rates::round_money( (float) $this->get_option( 'fallback' ), 'none' );
				$why       = 'fallback, no published rate for ' . ( '' === $class ? 'uncategorised products' : $class );
			}

			$cost    += (float) $item_cost;
			$trace[] = ( '' === $class ? 'no class' : $class ) . '[' . $provider . '] x' . $qty . ' = ' . $this->money( $item_cost ) . ' (' . $why . ')';
			$bd_groups[] = array(
				'provider' => $provider,
				'note'     => ( '' === $class ? 'unclassed' : $class ) . ' x' . $qty . ' - ' . $why,
				'cost'     => (float) $item_cost,
			);
		}

		// Supplier quotes are added once per supplier, on top of this method's own maths.
		foreach ( $supplier_cost as $who => $amount ) {
			$cost    += (float) $amount;
			$trace[] = 'supplier ' . $who . ' = ' . $this->money( $amount );
		}

		if ( $preview && ! empty( $agg ) ) {
			$would = 0.0;
			foreach ( $by_class as $group ) {
				$class = (string) $group['class'];
				$slug  = $this->slug_for( $class );
				$rate  = ( '' !== $slug ) ? TSM_Rates::find( $agg_use, $slug ) : null;
				$qty   = (int) $group['qty'];
				if ( $rate && 'tapstitch' === $group['provider'] ) {
					$would += TSM_Rates::cost(
						$rate,
						$qty,
						array(
							'handling' => (float) $this->get_option( 'handling' ),
							'pct'      => (float) $this->get_option( 'pct' ),
							'floor'    => (float) $this->get_option( 'floor' ),
							'cap'      => (float) $this->get_option( 'cap' ),
							'bound'    => $this->get_bound(),
							'round'    => $this->round_mode(),
						)
					);
				}
			}
			foreach ( $supplier_cost as $amount ) {
				$would += (float) $amount;
			}
			$this->log( sprintf( 'PREVIEW %s: charged %s, would charge %s (%s)', $country, $this->money( $cost ), $this->money( $would ), implode( '; ', $trace ) ) );
		}

		$min_total = (float) $this->get_option( 'min_total' );
		if ( $min_total > 0 && $cost < $min_total ) {
			$cost = $min_total;
		}

		// Nothing shippable priced: never ship free by accident - unless another
		// supplier method in this zone is the one pricing these goods.
		if ( $cost <= 0 ) {
			if ( $free_by_design ) {
				$this->log( sprintf( 'no rate added for %s: all goods belong to another supplier method in this zone (%s)', $country, implode( '; ', $trace ) ) );
				return;
			}
			$cost = (float) $this->get_option( 'fallback' );
		}

		$cost = TSM_Rates::round_money( $cost, $preview ? 'none' : $this->round_mode() );

		$label = TSM_Rates::provider_label(
			array_keys( $providers ),
			$this->get_title(),
			(string) $this->get_option( 'multi_label' ),
			$time,
			'yes' === $this->get_option( 'show_time' )
		);

		$this->rate_trace = $trace;

		// Sub-totals for the store owner: what each supplier contributed, stored on the rate
		// so they survive WooCommerce's session cache and appear on the next page view too.
		$bd_lines = TSM_Rates::build_breakdown(
			$bd_groups,
			$supplier_cost,
			function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$'
		);

		$this->add_rate(
			array(
				'id'        => $this->id . '_' . $this->instance_id,
				'label'     => $label,
				'cost'      => number_format( (float) $cost, 2, '.', '' ),
				'calc_tax'  => 'per_order',
				// WC_Shipping_Method::add_rate() expects meta as key => value pairs, and
				// wc_clean() on an array yields an empty string - the list form silently
				// destroyed the audit trail.
				'meta_data' => array(
					'tapstitch_rate_source' => $this->rate_source ? $this->rate_source : 'preview',
					'tapstitch_rate_detail' => implode( ' | ', $trace ),
					'tapstitch_lane'        => $this->get_option( 'lane' ) . ' / ' . $this->get_option( 'fulfillment' ),
					'tapstitch_providers'   => implode( '+', array_keys( $providers ) ),
					'tapstitch_cost_usd'    => number_format( (float) $cost, 2, '.', '' ),
					'tapstitch_breakdown'   => function_exists( 'wp_json_encode' ) ? wp_json_encode( $bd_lines ) : json_encode( $bd_lines ),
				),
			)
		);

		if ( 'yes' === $this->get_option( 'debug' ) && ! $preview ) {
			$this->log( sprintf( '%s: %s (%s)', $label, $this->money( $cost ), implode( '; ', $trace ) ) );
		}
	}

	/**
	 * What the store's own Flat Rate charges for the in-house items in this package.
	 *
	 * In-house goods have no supplier to ask, so the price comes from the zone's existing
	 * Flat Rate instance - what the store already decided these items cost to ship. The
	 * sub-package keeps only in-house items, so a mixed cart pays the flat rate for the
	 * owner-shipped part and the supplier's quote for the rest.
	 *
	 * @param array $package Package.
	 * @return array available|cost|label|source
	 */
	protected function in_house_quote( $package ) {
		$out = array(
			'available' => false,
			'cost'      => null,
			'label'     => '',
			'source'    => 'no flat rate in this zone',
		);

		$zone = $this->zone();
		if ( ! $zone || ! class_exists( 'WC_Shipping_Flat_Rate' ) ) {
			return $out;
		}

		$sub  = $this->sub_package( $package, 'fixed' );
		$rows = TSM_Rates::zone_method_rows( (int) $zone->get_id() );

		foreach ( $rows as $row ) {
			if ( ! in_array( $row['method_id'], array( 'flat_rate', 'free_shipping', 'legacy_flat_rate' ), true ) || ! $row['enabled'] ) {
				continue;
			}
			$class = 'flat_rate' === $row['method_id'] || 'legacy_flat_rate' === $row['method_id'] ? 'WC_Shipping_Flat_Rate' : 'WC_Shipping_Free_Shipping';
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$method = new $class( $row['instance_id'] );
			if ( method_exists( $method, 'supports' ) && ! $method->supports( 'shipping-zones' ) ) {
				continue;
			}
			$rates = $method->get_rates_for_package( $sub );
			foreach ( (array) $rates as $rate ) {
				if ( ! is_object( $rate ) ) {
					continue;
				}
				$cost = (float) $rate->get_cost();
				if ( $cost < 0 ) {
					continue;
				}
				if ( ! $out['available'] || $cost < (float) $out['cost'] ) {
					$out['available'] = true;
					$out['cost']      = $cost;
					$out['label']     = (string) $rate->get_label();
					$out['source']    = $row['method_id'] . '#' . $row['instance_id'];
				}
			}
		}

		return $out;
	}

	/**
	 * Items in the package grouped by WooCommerce shipping class.
	 *
	 * @param array $package Package.
	 * @return array class => qty
	 */
	protected function quantities_by_class( $package ) {
		$by = array();
		foreach ( (array) $package['contents'] as $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			if ( $item['data']->is_virtual() ) {
				continue;
			}
			$product = $item['data'];
			$class   = (string) $product->get_shipping_class();
			$qty     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			if ( ! isset( $by[ $class ] ) ) {
				$by[ $class ] = array(
					'qty'    => 0,
					'sample' => null,
					'skus'   => array(),
				);
			}
			$by[ $class ]['qty'] += $qty;
			if ( ! $by[ $class ]['sample'] ) {
				$by[ $class ]['sample'] = $product;
			}
			$sku = trim( (string) $product->get_sku() );
			if ( '' !== $sku ) {
				$by[ $class ]['skus'][] = $sku;
			}
		}
		return $by;
	}

	/**
	 * Cart items grouped by the supplier that ships them, then by shipping class.
	 *
	 * Grouping by class alone is not enough, and this is the measurement that proved it:
	 * a hoodie (Tapstitch) and a sticker (Printify) both have no shipping class, so they
	 * land in ONE class group, and the whole group was priced by whichever supplier the
	 * group happened to match - the customer paid the sticker's cost for both.
	 *
	 * So the supplier check runs per item: Printify's own list of claimed SKUs moves only
	 * those items into its group, and each supplier's goods are priced separately.
	 *
	 * @param array  $package       Package.
	 * @param array  $printify_skus SKUs Printify's API said are its own.
	 * @return array group => provider|class|qty|sample|skus
	 */
	protected function groups_by_supplier( $package, $printify_skus = array() ) {
		$groups = array();

		foreach ( (array) ( isset( $package['contents'] ) ? $package['contents'] : array() ) as $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$product = $item['data'];
			if ( $product->is_virtual() ) {
				continue;
			}

			$class    = (string) $product->get_shipping_class();
			$sku      = trim( (string) $product->get_sku() );
			$provider = $this->provider_for_class( $class, $product );

			if ( '' !== $sku && ! empty( $printify_skus ) && in_array( $sku, (array) $printify_skus, true ) ) {
				$provider = 'printify';
			}

			$key = $provider . '|' . $class;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'provider' => $provider,
					'class'    => $class,
					'qty'      => 0,
					'sample'   => $product,
					'skus'     => array(),
				);
			}
			$groups[ $key ]['qty'] += isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			if ( '' !== $sku ) {
				$groups[ $key ]['skus'][] = $sku;
			}
		}

		return $groups;
	}

	/**
	 * A copy of the package containing only one supplier's goods.
	 *
	 * Used to ask a supplier's own plugin what IT charges for its own items, so a
	 * mixed cart is priced by the suppliers that actually ship it.
	 *
	 * @param array  $package  Package.
	 * @param string $provider Provider to keep.
	 * @return array
	 */
	protected function sub_package( $package, $provider ) {
		$sub = $package;

		if ( method_exists( $this, 'child_package_id' ) ) {
			$sub['id'] = $this->child_package_id( $package, $provider );
		}

		$sub['contents']      = array();
		$sub['contents_cost'] = 0.0;
		$sub['weight']        = 0.0;

		foreach ( (array) ( isset( $package['contents'] ) ? $package['contents'] : array() ) as $key => $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$product = $item['data'];
			$class   = (string) $product->get_shipping_class();
			if ( $this->provider_for_class( $class, $product ) !== $provider ) {
				continue;
			}
			$sub['contents'][ $key ] = $item;
			if ( isset( $item['line_total'] ) ) {
				$sub['contents_cost'] += (float) $item['line_total'];
			}
			$sub['weight'] += (float) $product->get_weight() * (int) $item['quantity'];
		}

		return $sub;
	}

	/**
	 * Package id for a supplier subset (WooCommerce uses it as the rate cache key).
	 *
	 * @param array  $package  Package.
	 * @param string $provider Provider.
	 * @return string
	 */
	public function child_package_id( $package, $provider ) {
		$base = isset( $package['id'] ) ? (string) $package['id'] : 'tsm';
		return $base . '-' . $provider;
	}

	/**
	 * The shipping zone this instance belongs to.
	 *
	 * WooCommerce's lookup lives on the PLURAL class: WC_Shipping_Zones::get_zone_by().
	 * WC_Shipping_Zone has no such method, and calling it fatals the request that
	 * touches it - which at checkout means update_order_review dies and no shipping
	 * is shown at all. The zone id is also resolved straight from the database so a
	 * missing accessor can never take the store down.
	 *
	 * @return WC_Shipping_Zone|null
	 */
	public function zone() {
		static $cache = array();
		$ck = (string) $this->instance_id;
		if ( array_key_exists( $ck, $cache ) ) {
			return $cache[ $ck ];
		}

		$zone = null;
		if ( $this->instance_id && class_exists( 'WC_Shipping_Zones' ) ) {
			$found  = WC_Shipping_Zones::get_zone_by( 'instance_id', (int) $this->instance_id );
			$zone   = is_object( $found ) ? $found : null;
		}
		if ( ! $zone && $this->instance_id && class_exists( 'WC_Shipping_Zone' ) ) {
			$zone_id = self::zone_id_for_instance( (int) $this->instance_id );
			if ( null !== $zone_id ) {
				$zone = new WC_Shipping_Zone( $zone_id );
			}
		}

		$cache[ $ck ] = $zone;
		return $zone;
	}

	/**
	 * @param int $instance_id Zone method instance id.
	 * @return int|null Zone id (0 is the catch-all zone), null when not found.
	 */
	public static function zone_id_for_instance( $instance_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! $instance_id ) {
			return null;
		}
		$table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT zone_id FROM {$table} WHERE instance_id = %d", (int) $instance_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return null === $found ? null : (int) $found;
	}

	/**
	 * Country codes belonging to the zone this instance lives in.
	 *
	 * @return string[] Empty array = the catch-all zone (every country).
	 */
	public function zone_countries() {
		$zone = $this->zone();
		if ( ! $zone || ! class_exists( 'TSM_Rates' ) ) {
			return array();
		}
		return TSM_Rates::zone_countries( $zone );
	}

	/**
	 * ISO codes typed into the instance settings.
	 *
	 * @return string[]
	 */
	public function extra_countries() {
		$out   = array();
		$parts = preg_split( '/[\s,;]+/', strtoupper( (string) $this->get_option( 'extra_countries' ) ) );
		foreach ( (array) $parts as $code ) {
			$code = trim( (string) $code );
			if ( preg_match( '/^[A-Z]{2,3}$/', $code ) ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Countries whose rates are pulled into this instance's maths: the zone's own
	 * list plus any typed extras. Empty result means "the whole world".
	 *
	 * @return string[]
	 */
	public function lookup_countries() {
		return array_values( array_unique( array_merge( $this->zone_countries(), $this->extra_countries() ) ) );
	}

	/**
	 * Tapstitch category slugs this instance prices.
	 *
	 * @return string[]
	 */
	public function tapstitch_slugs() {
		$slugs = array();
		foreach ( $this->map() as $slug ) {
			if ( '' !== $slug ) {
				$slugs[ $slug ] = $slug;
			}
		}
		return array_values( $slugs );
	}

	/**
	 * woo class slug (or _default) => tapstitch category slug.
	 *
	 * @return array
	 */
	public function map() {
		$out = array();
		foreach ( $this->lines( $this->get_option( 'class_map' ) ) as $line ) {
			$pair = explode( '=', $line, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$key = strtolower( trim( $pair[0] ) );
			$val = TSM_API::slug( $pair[1] );
			if ( '' !== $key && '' !== $val ) {
				$out[ $key ] = $val;
			}
		}
		return $out;
	}

	/**
	 * woo class slug => [ first, per_item ].
	 *
	 * @return array
	 */
	public function fixed() {
		$out = array();
		foreach ( $this->lines( $this->get_option( 'fixed' ) ) as $line ) {
			$pair = explode( '=', $line, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$key  = strtolower( trim( $pair[0] ) );
			$rule = trim( $pair[1] );
			$bits = explode( '+', $rule, 2 );
			$first = (float) $bits[0];
			$per   = isset( $bits[1] ) ? (float) $bits[1] : 0;
			if ( '' !== $key && $first > 0 ) {
				$out[ $key ] = array(
					'first'    => $first,
					'per_item' => $per,
				);
			}
		}
		return $out;
	}

	/**
	 * Declared supplier per class: woo class slug (or _default) => provider.
	 *
	 * @return array
	 */
	public function class_provider() {
		$out = array();
		foreach ( $this->lines( $this->get_option( 'provider_by_class' ) ) as $line ) {
			$pair = explode( '=', $line, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$key  = strtolower( trim( $pair[0] ) );
			$val  = strtolower( trim( $pair[1] ) );
			if ( '' !== $key && in_array( $val, array( 'tapstitch', 'printify', 'printful', 'fixed', 'none' ), true ) ) {
				$out[ $key ] = $val;
			}
		}
		return $out;
	}

	/**
	 * Rules handed to TSM_Provider.
	 *
	 * @return array
	 */
	public function provider_rules() {
		return array(
			'printify_meta'  => $this->get_option( 'printify_meta' ),
			'printful_meta'  => $this->get_option( 'printful_meta' ),
			'sku_pattern'    => $this->get_option( 'sku_pattern' ),
			'default'        => $this->get_option( 'provider_default' ),
			'class_provider' => $this->class_provider(),
		);
	}

	/**
	 * Shipping method ids present in this instance's zone (printify_shipping, ...).
	 *
	 * Uses the zone data store: WC_Shipping_Zone::get_shipping_methods() would
	 * CONSTRUCT every method in the zone (Printify, Printful, ...), and doing that
	 * mid-calculation lets another plugin's constructor register hooks and change
	 * the method list under our feet.
	 *
	 * @return string[]
	 */
	public function zone_method_ids() {
		static $cache = array();
		$ck = (string) $this->instance_id;
		if ( isset( $cache[ $ck ] ) ) {
			return $cache[ $ck ];
		}

		$ids = array();
		$zone = $this->zone();
		if ( $zone && class_exists( 'TSM_Rates' ) ) {
			foreach ( TSM_Rates::zone_method_rows( (int) $zone->get_id() ) as $row ) {
				$ids[] = $row['method_id'];
			}
		}
		$cache[ $ck ] = $ids;
		return $ids;
	}

	/**
	 * Who ships this class. Explicit declaration wins, then the product's own evidence.
	 *
	 * @param string $class  Woo shipping class slug.
	 * @param mixed  $sample A product in the class, if known.
	 * @return string tapstitch|printify|printful|fixed|none
	 */
	public function provider_for_class( $class, $sample = null ) {
		$declared = $this->class_provider();
		$class    = strtolower( (string) $class );

		if ( isset( $declared[ $class ] ) ) {
			return $declared[ $class ];
		}

		// Product-level evidence (meta, SKU) before falling back to _default.
		if ( $sample instanceof WC_Product ) {
			$detected = TSM_Provider::detect( $sample, array_merge( $this->provider_rules(), array( 'class_provider' => array() ) ) );
			if ( 'tapstitch' !== $detected['provider'] || 'default' !== $detected['via'] && 'no sku, default' !== $detected['via'] ) {
				return $detected['provider'];
			}
		}

		if ( isset( $declared['_default'] ) ) {
			return $declared['_default'];
		}
		if ( isset( $this->fixed()[ $class ] ) ) {
			return 'fixed';
		}
		return $this->get_option( 'provider_default' ) ? $this->get_option( 'provider_default' ) : 'tapstitch';
	}

	/**
	 * @param string $text Textarea value.
	 * @return string[]
	 */
	protected function lines( $text ) {
		// Split on newlines AND tabs: a mapping pasted from a spreadsheet arrives
		// tab-separated, and silently merging every rule into one line would price
		// everything as the first rule only.
		$lines = preg_split( '/\r\n|\r|\n|\t/', (string) $text );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			$line = preg_replace( '/\s*#.*$/', '', $line );
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Resolve a shipping class to its Tapstitch category, honouring _default.
	 *
	 * @param string $class Woo shipping class slug.
	 * @return string
	 */
	public function slug_for( $class ) {
		$map = $this->map();
		$class = strtolower( (string) $class );
		if ( isset( $map[ $class ] ) ) {
			return $map[ $class ];
		}
		if ( isset( $map['_default'] ) ) {
			return $map['_default'];
		}
		return '';
	}

	/**
	 * The bound to use for additional items.
	 *
	 * @return string
	 */
	public function get_bound() {
		$bound = $this->get_option( 'bound' );
		return in_array( $bound, array( 'min', 'mid', 'max' ), true ) ? $bound : 'max';
	}

	/**
	 * Rounding mode, with the old "nearest" spelling accepted.
	 *
	 * @return string
	 */
	public function round_mode() {
		$mode = (string) $this->get_option( 'round' );
		if ( 'nearest' === $mode ) {
			return 'nearest99';
		}
		if ( in_array( $mode, array( 'up99', 'ceil99' ), true ) ) {
			return 'nearest99';
		}
		if ( in_array( $mode, array( 'nearest99', 'int99', 'none' ), true ) ) {
			return $mode;
		}
		return 'nearest99';
	}

	/**
	 * Per-country or zone-worst pricing.
	 *
	 * @return string country|zone_max
	 */
	public function price_mode() {
		$mode = (string) $this->get_option( 'price_by' );
		return in_array( $mode, array( 'country', 'zone_max' ), true ) ? $mode : 'country';
	}

	/**
	 * Transit range for the label.
	 *
	 * @param array $rows Rows for the zone.
	 * @param array $agg Aggregated rates.
	 * @param string $country Customer country.
	 * @return string
	 */
	protected function time_label( $rows, $agg, $country ) {
		$min = 0;
		$max = 0;
		foreach ( (array) $rows as $row ) {
			if ( $row['country'] !== $country ) {
				continue;
			}
			$min = $min > 0 ? min( $min, (int) $row['time_min'] ) : (int) $row['time_min'];
			$max = max( $max, (int) $row['time_max'] );
		}
		if ( 0 === $max && ! empty( $agg ) ) {
			foreach ( $agg as $rate ) {
				$min = $min > 0 ? min( $min, (int) $rate['time_min'] ) : (int) $rate['time_min'];
				$max = max( $max, (int) $rate['time_max'] );
			}
		}
		if ( $min <= 0 || $max <= 0 ) {
			return '';
		}
		if ( $min === $max ) {
			/* translators: %d: days */
			return sprintf( __( '%d day', 'tapstitch-shipping-method' ), $max );
		}
		/* translators: 1: min days, 2: max days */
		return sprintf( __( '%1$d-%2$d days', 'tapstitch-shipping-method' ), $min, $max );
	}

	/**
	 * @param float $amount Money.
	 * @return string
	 */
	protected function money( $amount ) {
		return '$' . number_format( (float) $amount, 2 );
	}

	/**
	 * @param string $message Log line.
	 */
	protected function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->debug( '[zone instance ' . $this->instance_id . '] ' . $message, array( 'source' => 'tapstitch-shipping' ) );
	}
}
