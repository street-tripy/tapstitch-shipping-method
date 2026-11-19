<?php
/**
 * Admin screens: status, rate table, cart preview, static export, self-check.
 *
 * @package tapstitch-shipping-method
 */

defined( 'ABSPATH' ) || exit;

class TSM_Admin {

	const PAGE = 'tapstitch-shipping-method';

	/**
	 * Register the endpoint setting.
	 */
	public static function register() {
		register_setting(
			'tapstitch_sm',
			'tapstitch_sm_endpoint',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			)
		);
	}

	/**
	 * Add the Tools page.
	 */
	public static function menu() {
		add_management_page(
			__( 'Tapstitch Shipping Rates', 'tapstitch-shipping-method' ),
			__( 'Tapstitch rates', 'tapstitch-shipping-method' ),
			self::capability(),
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * @return string
	 */
	public static function capability() {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Small inline styles, no asset files.
	 */
	public static function settings_inline_style() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) { // phpcs:ignore
			return;
		}
		echo '<style>.tsm-card{background:#fff;border:1px solid #dcdfe4;border-radius:8px;padding:16px 18px;margin:14px 0;max-width:1100px}'
			. '.tsm-card h3{margin-top:0}.tsm-kv{display:grid;grid-template-columns:180px 1fr;gap:4px 12px;font-size:13px;max-width:640px}'
			. '.tsm-kv b{color:#505050}.tsm-table{width:100%;border-collapse:collapse;background:#fff;font-size:12.5px}'
			. '.tsm-table th,.tsm-table td{border:1px solid #e2e2e8;padding:6px 8px;text-align:left}.tsm-table th{background:#f6f7f9}'
			. '.tsm-good{color:#0b7a3b}.tsm-warn{color:#a06000}.tsm-bad{color:#b32d2d}.tsm-formula{font-family:Consolas,Monaco,monospace;white-space:pre}'
			. '.tsm-tabs a{margin-right:12px}.tsm-note{color:#666;font-size:12.5px}.tsm-code{width:100%;min-height:220px;font-family:Consolas,Monaco,monospace;font-size:12px}</style>';
	}

	/**
	 * Page router + action handling.
	 */
	public static function page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'tapstitch-shipping-method' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore

		self::handle_actions();

		$tabs = array(
			'overview'  => __( 'Overview', 'tapstitch-shipping-method' ),
			'providers' => __( 'Provider report', 'tapstitch-shipping-method' ),
			'rates'     => __( 'Rate table', 'tapstitch-shipping-method' ),
			'preview'   => __( 'Cart preview', 'tapstitch-shipping-method' ),
			'export'    => __( 'Static export', 'tapstitch-shipping-method' ),
			'doctor'    => __( 'Self-check', 'tapstitch-shipping-method' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Tapstitch shipping rates', 'tapstitch-shipping-method' ) . '</h1>';
		echo '<p class="tsm-tabs">';
		foreach ( $tabs as $key => $label ) {
			$url  = add_query_arg( array( 'page' => self::PAGE, 'tab' => $key ), admin_url( 'admin.php' ) );
			$live = ( $key === $tab ) ? ' style="font-weight:600"' : '';
			echo '<a href="' . esc_url( $url ) . '"' . $live . '>' . esc_html( $label ) . '</a>';
		}
		echo '</p>';

		self::{'render_' . ( array_key_exists( $tab, $tabs ) ? $tab : 'overview' )}();
		echo '</div>';
	}

	/**
	 * Refresh / save endpoint, nonce guarded.
	 */
	protected static function handle_actions() {
		if ( ! isset( $_POST['tsm_action'] ) ) { // phpcs:ignore
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['tsm_action'] ) ); // phpcs:ignore
		if ( ! isset( $_POST['tsm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tsm_nonce'] ) ), 'tsm_admin' ) ) {
			wp_die( esc_html__( 'Security check failed. Reload the page.', 'tapstitch-shipping-method' ) );
		}

		if ( 'save_endpoint' === $action && isset( $_POST['tsm_endpoint'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['tsm_endpoint'] ) ); // phpcs:ignore
			update_option( 'tapstitch_sm_endpoint', $url );
			Tapstitch_Shipping_Method::instance()->clear_cache();
			self::note( sprintf( __( 'Rate endpoint saved: %s', 'tapstitch-shipping-method' ), '<code>' . esc_html( $url ) . '</code>' ) );
		}

		if ( 'refresh' === $action ) {
			TSM_Rates::cron_refresh();
			$status = TSM_Rates::status();
			self::note( sprintf( __( 'Refresh attempted. Snapshot now holds %d rows across %d countries.', 'tapstitch-shipping-method' ), (int) $status['rows'], (int) $status['countries'] ), 'good' );
		}

		if ( 'probe' === $action ) {
			$res = TSM_API::probe();
			if ( is_wp_error( $res ) ) {
				self::note( __( 'Endpoint test FAILED: ', 'tapstitch-shipping-method' ) . esc_html( $res->get_error_message() ), 'bad' );
			} else {
				self::note( sprintf( __( 'Endpoint test OK: %d US rows returned.', 'tapstitch-shipping-method' ), (int) count( $res ) ), 'good' );
			}
		}
	}

	/**
	 * @param string $text Message (already escaped where needed).
	 * @param string $kind good|bad|warn.
	 */
	protected static function note( $text, $kind = '' ) {
		set_transient( 'tsm_notice', array( 'text' => $text, 'kind' => $kind ), 120 );
	}

	/**
	 * Show the pending notice once.
	 */
	protected static function notice() {
		$note = get_transient( 'tsm_notice' );
		delete_transient( 'tsm_notice' );
		if ( ! $note ) {
			return;
		}
		$class = 'warn' === $note['kind'] ? 'notice-warning' : ( 'bad' === $note['kind'] ? 'notice-error' : 'notice-success' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . wp_kses_post( $note['text'] ) . '</p></div>';
	}

	/**
	 * Tab: overview.
	 */
	protected static function render_overview() {
		self::notice();
		$status = TSM_Rates::status();
		$last   = $status['last_fetch'] ? wp_date( 'Y-m-d H:i', $status['last_fetch'] ) : __( 'never', 'tapstitch-shipping-method' );

		echo '<div class="tsm-card"><h3>' . esc_html__( 'Where the rates come from', 'tapstitch-shipping-method' ) . '</h3>';
		echo '<div class="tsm-kv">';
		echo '<b>' . esc_html__( 'Endpoint', 'tapstitch-shipping-method' ) . '</b><span><code>' . esc_html( $status['endpoint'] ) . '</code></span>';
		echo '<b>' . esc_html__( 'Last successful fetch', 'tapstitch-shipping-method' ) . '</b><span>' . esc_html( $last ) . '</span>';
		echo '<b>' . esc_html__( 'Rows in snapshot', 'tapstitch-shipping-method' ) . '</b><span>' . (int) $status['rows'] . ' (' . (int) $status['countries'] . ' ' . esc_html__( 'countries', 'tapstitch-shipping-method' ) . ')</span>';
		echo '<b>' . esc_html__( 'Categories seen', 'tapstitch-shipping-method' ) . '</b><span>' . (int) $status['categories'] . '</span>';
		echo '<b>' . esc_html__( 'Lanes seen', 'tapstitch-shipping-method' ) . '</b><span>' . esc_html( $status['lines'] ) . '</span>';
		echo '<b>' . esc_html__( 'Shipped seed rows', 'tapstitch-shipping-method' ) . '</b><span>' . (int) $status['seed'] . '</span>';
		echo '</div>';

		echo '<form method="post" style="margin-top:14px">';
		wp_nonce_field( 'tsm_admin' );
		echo '<input type="hidden" name="tsm_action" value="probe" />';
		echo '<button class="button button-secondary" type="submit">' . esc_html__( 'Test the endpoint now', 'tapstitch-shipping-method' ) . '</button> ';
		echo '<button class="button button-primary" type="submit" name="tsm_action" value="refresh" form="mts-refresh">' . esc_html__( 'Refresh all zones', 'tapstitch-shipping-method' ) . '</button>';
		echo '</form>';

		echo '<form method="post" id="mts-refresh" style="margin-top:10px">';
		wp_nonce_field( 'tsm_admin' );
		echo '<input type="hidden" name="tsm_action" value="save_endpoint" />';
		echo '<label><b>' . esc_html__( 'Endpoint', 'tapstitch-shipping-method' ) . '</b><br /><input type="url" name="tsm_endpoint" value="' . esc_attr( $status['endpoint'] ) . '" style="width:100%;max-width:640px" /></label>';
		echo '<button class="button" type="submit" style="margin-top:8px">' . esc_html__( 'Save endpoint', 'tapstitch-shipping-method' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<div class="tsm-card"><h3>' . esc_html__( 'Zones using this method', 'tapstitch-shipping-method' ) . '</h3>';
		$sets = TSM_Rates::zone_sets();
		if ( empty( $sets ) ) {
			echo '<p class="tsm-warn">' . esc_html__( 'No shipping zone is using Tapstitch Shipping yet. Go to WooCommerce > Settings > Shipping > a zone > Add shipping method > Tapstitch Shipping.', 'tapstitch-shipping-method' ) . '</p>';
		} else {
			echo '<table class="tsm-table"><tr><th>' . esc_html__( 'Zone', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Countries', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Lane', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Fulfilment', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Categories priced', 'tapstitch-shipping-method' ) . '</th></tr>';
			foreach ( $sets as $set ) {
				echo '<tr><td>' . esc_html( $set['name'] ) . ' #' . (int) $set['zone'] . '</td><td>' . esc_html( empty( $set['countries'] ) ? __( 'everything not in another zone', 'tapstitch-shipping-method' ) : implode( ', ', $set['countries'] ) ) . '</td><td>' . esc_html( $set['lane'] ) . '</td><td>' . esc_html( $set['fulfillment'] ) . '</td><td>' . esc_html( implode( ', ', $set['slugs'] ) ) . '</td></tr>';
			}
			echo '</table>';
		}
		echo '</div>';
	}

	/**
	 * Tab: which supplier each product is identified as.
	 */
	protected static function render_providers() {
		self::notice();
		$sets = TSM_Rates::zone_sets();
		if ( empty( $sets ) ) {
			echo '<div class="tsm-card"><p>' . esc_html__( 'Add the method to a shipping zone first - the provider rules live in its settings.', 'tapstitch-shipping-method' ) . '</p></div>';
			return;
		}

		$zone_id = isset( $_GET['zone'] ) ? absint( wp_unslash( $_GET['zone'] ) ) : (int) $sets[0]['zone']; // phpcs:ignore
		$set     = $sets[0];
		foreach ( $sets as $s ) {
			if ( (int) $s['zone'] === $zone_id ) {
				$set = $s;
			}
		}
		$method = self::method_for_zone( (int) $set['zone'] );
		if ( ! $method ) {
			echo '<div class="tsm-card"><p>' . esc_html__( 'This zone has no Tapstitch Shipping instance.', 'tapstitch-shipping-method' ) . '</p></div>';
			return;
		}

		$report = TSM_Provider::report( $method );
		$zone_methods = $method->zone_method_ids();

		echo '<div class="tsm-card"><h3>' . esc_html__( 'Every published product, and the supplier it is treated as', 'tapstitch-shipping-method' ) . '</h3>';
		echo '<p class="tsm-note">' . esc_html__( 'Detection order: explicit provider meta (the keys in TSM_Provider::meta_keys()), then the sync plugins\' own meta (printify_product_id / _printful_product_id), then the class declaration, then the SKU pattern, then the default provider.', 'tapstitch-shipping-method' ) . '</p>';
		echo '<p class="tsm-note">' . esc_html__( 'Methods present in this zone:', 'tapstitch-shipping-method' ) . ' <code>' . esc_html( implode( ', ', $zone_methods ) ) . '</code></p>';
		echo '<table class="tsm-table"><tr><th>ID</th><th>' . esc_html__( 'Product', 'tapstitch-shipping-method' ) . '</th><th>SKU</th><th>' . esc_html__( 'Class', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Provider', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Decided by', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Tapstitch category', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Priced by', 'tapstitch-shipping-method' ) . '</th></tr>';
		foreach ( $report as $row ) {
			$priced = 'tapstitch' === $row['provider']
				? ( '' !== $row['slug'] ? __( 'this plugin', 'tapstitch-shipping-method' ) . ' (' . esc_html( $row['slug'] ) . ')' : __( 'this plugin, via _default', 'tapstitch-shipping-method' ) )
				: ( in_array( $row['provider'] . '_shipping', $zone_methods, true )
					? esc_html( $row['provider'] ) . ' ' . __( 'method', 'tapstitch-shipping-method' )
					: __( 'fixed cost / unmatched', 'tapstitch-shipping-method' ) );
			echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html( $row['name'] ) . ' <code>' . esc_html( $row['type'] ) . '</code></td><td><code>' . esc_html( $row['sku'] ) . '</code></td><td>' . esc_html( '' === $row['class'] ? __( '(none)', 'tapstitch-shipping-method' ) : $row['class'] ) . '</td><td><b>' . esc_html( $row['provider'] ) . '</b></td><td>' . esc_html( $row['via'] ) . '</td><td>' . esc_html( $row['slug'] ) . '</td><td>' . wp_kses_post( $priced ) . '</td></tr>';
		}
		echo '</table>';
		echo '<p class="tsm-note">' . esc_html__( 'Anything listed as "default" or "sku pattern" needs a shipping class on the product, or a class rule in "Which supplier ships each class". Products with no class and no declaration are priced as the _default provider.', 'tapstitch-shipping-method' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Tab: rate table for a chosen zone.
	 */
	protected static function render_rates() {
		self::notice();
		$sets = TSM_Rates::zone_sets();
		if ( empty( $sets ) ) {
			echo '<div class="tsm-card"><p>' . esc_html__( 'Add the method to a shipping zone first.', 'tapstitch-shipping-method' ) . '</p></div>';
			return;
		}

		$current = isset( $_GET['zone'] ) ? absint( wp_unslash( $_GET['zone'] ) ) : (int) $sets[0]['zone']; // phpcs:ignore
		$set     = $sets[0];
		foreach ( $sets as $s ) {
			if ( (int) $s['zone'] === $current ) {
				$set = $s;
			}
		}

		echo '<div class="tsm-card"><h3>' . esc_html__( 'Zone', 'tapstitch-shipping-method' ) . '</h3><p>';
		foreach ( $sets as $s ) {
			$url = add_query_arg( array( 'page' => self::PAGE, 'tab' => 'rates', 'zone' => (int) $s['zone'] ), admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '"' . ( (int) $s['zone'] === $current ? ' style="font-weight:600"' : '' ) . '>' . esc_html( $s['name'] ) . '</a> &nbsp; ';
		}
		echo '</p>';

		$lookup = TSM_Rates::lookup( empty( $set['countries'] ) ? TSM_Rates::all_countries() : $set['countries'], $set['slugs'], $set['lane'], $set['fulfillment'] );
		$agg    = TSM_Rates::aggregate( $lookup['rows'], 'max' );

		echo '<p class="tsm-note">' . esc_html__( 'Source of these numbers:', 'tapstitch-shipping-method' ) . ' <b>' . esc_html( $lookup['source'] ) . '</b>. '
			. esc_html__( 'First = what Tapstitch charges for one item. Extra = published range for each additional item (the top of the range is used by default). The zone rate is the most expensive country in the zone, so no destination can cost the store money.', 'tapstitch-shipping-method' ) . '</p>';

		if ( empty( $agg ) ) {
			echo '<p class="tsm-bad">' . esc_html__( 'No rows for this zone. Check the lane/fulfilment choice, the category mapping, and the endpoint test.', 'tapstitch-shipping-method' ) . '</p></div>';
			return;
		}

		echo '<table class="tsm-table"><tr><th>' . esc_html__( 'Category', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Worst country', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Item weight', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'First item', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Each extra (min-max)', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Days', 'tapstitch-shipping-method' ) . '</th><th>1 / 2 / 3 ' . esc_html__( 'items', 'tapstitch-shipping-method' ) . '</th></tr>';
		foreach ( $agg as $rate ) {
			echo '<tr><td>' . esc_html( $rate['category'] ) . ' <code>' . esc_html( $rate['slug'] ) . '</code></td><td>' . esc_html( $rate['worst'] ) . '</td><td>' . (int) $rate['weight'] . ' g</td><td>' . esc_html( number_format( (float) $rate['first'], 2 ) ) . '</td><td>' . esc_html( number_format( (float) $rate['add_min'], 2 ) ) . ' - ' . esc_html( number_format( (float) $rate['add_max'], 2 ) ) . '</td><td>' . (int) $rate['time_min'] . '-' . (int) $rate['time_max'] . '</td><td>';
			$demo = array();
			for ( $i = 1; $i <= 3; $i++ ) {
				$demo[] = number_format( (float) TSM_Rates::cost( $rate, $i, array( 'bound' => 'max' ) ), 2 );
			}
			echo esc_html( implode( ' / ', $demo ) ) . '</td></tr>';
		}
		echo '</table>';
		echo '<p class="tsm-note">' . esc_html__( '1 / 2 / 3 items are shown with handling and rounding switched off, i.e. Tapstitch\'s own published maths. Your zone settings add handling on top.', 'tapstitch-shipping-method' ) . '</p>';

		// Per-country detail: what a customer in each country of this zone would be charged.
		$method   = self::method_for_zone( (int) $set['zone'] );
		$handling = $method ? (float) $method->get_option( 'handling' ) : 0.0;
		$round    = $method ? $method->round_mode() : 'nearest99';
		$pct      = $method ? (float) $method->get_option( 'pct' ) : 0.0;

		$by_country = array();
		foreach ( $lookup['rows'] as $row ) {
			$by_country[ $row['country'] ][ $row['slug'] ] = $row;
		}
		ksort( $by_country );

		echo '<h3>' . esc_html__( 'Per-country prices (own-country mode charges exactly these)', 'tapstitch-shipping-method' ) . '</h3>';
		echo '<p class="tsm-note">' . esc_html__( 'Handling and rounding from this zone\'s settings are included. Columns are 1 / 2 / 3 items of that category, on their own.', 'tapstitch-shipping-method' ) . '</p>';
		echo '<table class="tsm-table"><tr><th>' . esc_html__( 'Country', 'tapstitch-shipping-method' ) . '</th>';
		foreach ( $set['slugs'] as $slug ) {
			echo '<th>' . esc_html( $slug ) . '</th>';
		}
		echo '</tr>';
		foreach ( $by_country as $code => $cats ) {
			echo '<tr><td><b>' . esc_html( $code ) . '</b></td>';
			foreach ( $set['slugs'] as $slug ) {
				if ( ! isset( $cats[ $slug ] ) ) {
					echo '<td>-</td>';
					continue;
				}
				$rate  = TSM_Rates::aggregate( array( $cats[ $slug ] ), $method ? $method->get_bound() : 'max' );
				$quote = isset( $rate[ $slug ] ) ? $rate[ $slug ] : null;
				if ( ! $quote ) {
					echo '<td>-</td>';
					continue;
				}
				$prices = array();
				foreach ( array( 1, 2, 3 ) as $q ) {
					$prices[] = number_format( (float) TSM_Rates::cost( $quote, $q, array( 'handling' => $handling, 'pct' => $pct, 'bound' => $method ? $method->get_bound() : 'max', 'round' => $round ) ), 2 );
				}
				echo '<td>' . esc_html( implode( ' / ', $prices ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</table>';

		echo '</div>';
	}

	/**
	 * Tab: preview a cart against real products.
	 */
	protected static function render_preview() {
		self::notice();
		$sets = TSM_Rates::zone_sets();
		if ( empty( $sets ) ) {
			echo '<div class="tsm-card"><p>' . esc_html__( 'Add the method to a shipping zone first.', 'tapstitch-shipping-method' ) . '</p></div>';
			return;
		}

		$zone_id = isset( $_GET['zone'] ) ? absint( wp_unslash( $_GET['zone'] ) ) : (int) $sets[0]['zone']; // phpcs:ignore
		$set     = $sets[0];
		foreach ( $sets as $s ) {
			if ( (int) $s['zone'] === $zone_id ) {
				$set = $s;
			}
		}

		$method    = self::method_for_zone( $zone_id );
		$products  = isset( $_POST['tsm_products'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tsm_products'] ) ) : "12 1"; // phpcs:ignore
		$country   = isset( $_POST['tsm_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['tsm_country'] ) ) ) : 'US'; // phpcs:ignore

		echo '<div class="tsm-card"><h3>' . esc_html__( 'What would this cart be charged?', 'tapstitch-shipping-method' ) . '</h3>';
		echo '<form method="post">';
		wp_nonce_field( 'tsm_admin' );
		echo '<input type="hidden" name="tsm_action" value="probe_none" />';
		echo '<p><label><b>' . esc_html__( 'Products (product_id qty per line)', 'tapstitch-shipping-method' ) . '</b><br /><textarea name="tsm_products" class="tsm-code" style="min-height:120px">' . esc_textarea( $products ) . '</textarea></label></p>';
		echo '<p><label><b>' . esc_html__( 'Destination country', 'tapstitch-shipping-method' ) . '</b> <input type="text" name="tsm_country" value="' . esc_attr( $country ) . '" size="4" /></label>';
		echo ' <label>' . esc_html__( 'Zone', 'tapstitch-shipping-method' ) . ' ';
		echo '<select name="tsm_zone">';
		foreach ( $sets as $s ) {
			echo '<option value="' . (int) $s['zone'] . '"' . selected( $s['zone'], $zone_id, false ) . '>' . esc_html( $s['name'] ) . '</option>';
		}
		echo '</select></label>';
		echo ' <button class="button button-primary" type="submit">' . esc_html__( 'Calculate', 'tapstitch-shipping-method' ) . '</button></p>';
		echo '</form>';

		if ( isset( $_POST['tsm_products'] ) && $method ) { // phpcs:ignore
			$zone_id = isset( $_POST['tsm_zone'] ) ? absint( wp_unslash( $_POST['tsm_zone'] ) ) : $zone_id; // phpcs:ignore
			$lines   = self::preview_cart( $method, $products, $country );
			echo '<table class="tsm-table"><tr><th>' . esc_html__( 'Product', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Class', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Qty', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Charged', 'tapstitch-shipping-method' ) . '</th></tr>';
			foreach ( $lines as $line ) {
				echo '<tr><td>' . esc_html( $line['name'] ) . '</td><td>' . esc_html( $line['class'] ) . '</td><td>' . (int) $line['qty'] . '</td><td>' . esc_html( $line['cost'] ) . '</td></tr>';
			}
			echo '</table>';
		}

		echo '</div>';
	}

	/**
	 * Tab: static export for WooCommerce core Flat Rate.
	 */
	protected static function render_export() {
		self::notice();
		$sets = TSM_Rates::zone_sets();
		echo '<div class="tsm-card"><h3>' . esc_html__( 'Core Flat Rate formulas', 'tapstitch-shipping-method' ) . '</h3>';
		echo '<p class="tsm-note">' . esc_html__( 'WooCommerce core Flat Rate supports cost formulas. Paste these into the class cost fields if you ever want the rates without this plugin: Cost field 0.00, Calculation type "Per class", and each class gets the formula below. Handling is included in the first number.', 'tapstitch-shipping-method' ) . '</p>';

		if ( empty( $sets ) ) {
			echo '<p class="tsm-warn">' . esc_html__( 'Add the method to a zone first, so the plugin knows which countries and categories to price.', 'tapstitch-shipping-method' ) . '</p></div>';
			return;
		}

		$out = array();
		foreach ( $sets as $set ) {
			$method = self::method_for_zone( (int) $set['zone'] );
			if ( ! $method ) {
				continue;
			}
			$lookup = TSM_Rates::lookup( empty( $set['countries'] ) ? TSM_Rates::all_countries() : $set['countries'], $set['slugs'], $set['lane'], $set['fulfillment'] );
			$agg    = TSM_Rates::aggregate( $lookup['rows'], $method->get_bound() );
			$opts   = array(
				'handling' => (float) $method->get_option( 'handling' ),
				'bound'    => $method->get_bound(),
			);
			$out[] = '# ' . $set['name'] . ' (' . ( empty( $set['countries'] ) ? __( 'rest of world', 'tapstitch-shipping-method' ) : implode( ' ', $set['countries'] ) ) . ')';
			foreach ( $agg as $rate ) {
				$out[] = sprintf(
					'%-22s first %8s  extra %8s   formula: %s',
					$rate['slug'],
					number_format( (float) $rate['first'] + $opts['handling'], 2 ),
					number_format( (float) TSM_Rates::bound( $rate, $opts['bound'] ), 2 ),
					TSM_Rates::formula( $rate, $opts )
				);
			}
			foreach ( $method->fixed() as $class => $rule ) {
				$formula = number_format( $rule['first'], 2, '.', '' ) . ( $rule['per_item'] > 0 ? ' + ' . number_format( $rule['per_item'], 2, '.', '' ) . ' * ( [qty] - 1 )' : '' );
				$out[]   = sprintf( '%-22s fixed                              formula: %s', $class, $formula );
			}
			$out[] = '';
		}

		echo '<textarea class="tsm-code" readonly>' . esc_textarea( implode( "\n", $out ) ) . '</textarea>';
		echo '</div>';
	}

	/**
	 * Tab: environment self-check.
	 */
	protected static function render_doctor() {
		self::notice();
		$checks = array();

		$checks[] = array( class_exists( 'WC_Shipping_Zones' ), __( 'WooCommerce is active', 'tapstitch-shipping-method' ), true );
		$checks[] = array( version_compare( get_option( 'woocommerce_version', '0' ), '6.0', '>=' ), __( 'WooCommerce 6.0 or newer', 'tapstitch-shipping-method' ), true );
		$checks[] = array( 0 === strpos( get_option( 'siteurl', '' ), 'https://' ), __( 'Site runs on HTTPS', 'tapstitch-shipping-method' ), false );
		$checks[] = array( false !== wp_next_scheduled( 'tapstitch_sm_refresh' ), __( 'Daily rate refresh is scheduled (WP-Cron)', 'tapstitch-shipping-method' ), true );

		$probe = TSM_API::probe();
		$checks[] = array(
			! is_wp_error( $probe ) && ! empty( $probe ),
			is_wp_error( $probe )
				? __( 'Endpoint test: ', 'tapstitch-shipping-method' ) . $probe->get_error_message()
				: sprintf( __( 'Endpoint test: OK, %d rows', 'tapstitch-shipping-method' ), (int) count( $probe ) ),
			true,
		);

		$status = TSM_Rates::status();
		$checks[] = array( $status['rows'] > 0, sprintf( __( 'Snapshot has %d rows (seed has %d)', 'tapstitch-shipping-method' ), (int) $status['rows'], (int) $status['seed'] ), true );

		$classes = self::product_classes();
		$sets    = TSM_Rates::zone_sets();
		$mapped  = array();
		foreach ( $sets as $set ) {
			$method = self::method_for_zone( (int) $set['zone'] );
			if ( $method ) {
				$mapped = array_merge( $mapped, array_keys( $method->map() ), array_keys( $method->fixed() ) );
			}
		}
		$missing = array_diff( $classes, $mapped, array( '_default', '' ) );
		$checks[] = array(
			empty( $missing ),
			empty( $missing )
				? __( 'Every shipping class used by products is mapped (or the _default line covers it)', 'tapstitch-shipping-method' )
				: sprintf( __( 'Not mapped: %s - add a class_map line for each, and give products a shipping class', 'tapstitch-shipping-method' ), implode( ', ', $missing ) ),
			true,
		);
		$checks[] = array( ! empty( $sets ), sprintf( __( '%d shipping zone(s) use Tapstitch Shipping', 'tapstitch-shipping-method' ), (int) count( $sets ) ), false );

		echo '<div class="tsm-card"><h3>' . esc_html__( 'Self-check', 'tapstitch-shipping-method' ) . '</h3><ul style="font-size:13px">';
		foreach ( $checks as $check ) {
			$ok   = (bool) $check[0];
			$must = ! empty( $check[2] );
			$class = $ok ? 'tsm-good' : ( $must ? 'tsm-bad' : 'tsm-warn' );
			echo '<li class="' . esc_attr( $class ) . '">' . ( $ok ? '&#10003;' : ( $must ? '&#10007;' : '&#9888;' ) ) . ' ' . esc_html( $check[1] ) . '</li>';
		}
		echo '</ul>';
		echo '<p class="tsm-note">' . esc_html__( 'Classes in use by products:', 'tapstitch-shipping-method' ) . ' <code>' . esc_html( empty( $classes ) ? __( 'none', 'tapstitch-shipping-method' ) : implode( ', ', $classes ) ) . '</code></p>';
		echo '</div>';

		// Maths test: no network, no WordPress state.
		$results = TSM_Selftest::run();
		$sum     = TSM_Selftest::summary();
		echo '<div class="tsm-card"><h3>' . esc_html__( 'Rate maths test (runs on your server, offline)', 'tapstitch-shipping-method' ) . '</h3>';
		echo '<p>' . sprintf(
			/* translators: 1: passed, 2: total */
			esc_html__( '%1$d of %2$d checks passed.', 'tapstitch-shipping-method' ),
			(int) $sum['passed'],
			(int) $sum['total']
		) . '</p>';
		echo '<table class="tsm-table"><tr><th>' . esc_html__( 'Check', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Result', 'tapstitch-shipping-method' ) . '</th><th>' . esc_html__( 'Detail', 'tapstitch-shipping-method' ) . '</th></tr>';
		foreach ( $results as $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td class="' . ( $r['pass'] ? 'tsm-good' : 'tsm-bad' ) . '">' . ( $r['pass'] ? 'PASS' : 'FAIL' ) . '</td><td>' . esc_html( $r['detail'] ) . '</td></tr>';
		}
		echo '</table></div>';
	}

	/**
	 * @param int $zone_id Zone id.
	 * @return WC_Shipping_Tapstitch|null
	 */
	public static function method_for_zone( $zone_id ) {
		// Reads the zone's method rows from the data store and builds ONLY this
		// plugin's method. WC_Shipping_Zone::get_shipping_methods() constructs every
		// method in the zone - Printify's and Printful's constructors register hooks,
		// and in admin context the loop calls get_admin_options_html() on them, which
		// is what made the REST status/preview routes fail with a fatal error.
		foreach ( TSM_Rates::zone_method_rows( (int) $zone_id ) as $row ) {
			if ( 'tapstitch_shipping' === $row['method_id'] && $row['enabled'] ) {
				return new WC_Shipping_Tapstitch( $row['instance_id'] );
			}
		}
		return null;
	}

	/**
	 * Shipping classes in use across the catalogue.
	 *
	 * @return string[]
	 */
	public static function product_classes() {
		$out = array();
		foreach ( self::shipping_classes() as $term ) {
			if ( is_object( $term ) && method_exists( $term, 'get' ) ) {
				$out[] = (string) $term->get( 'slug' );
			} elseif ( is_object( $term ) ) {
				$out[] = (string) $term->slug;
			} elseif ( is_array( $term ) && isset( $term['slug'] ) ) {
				$out[] = (string) $term['slug'];
			}
		}
		return $out;
	}

	/**
	 * WooCommerce's shipping-class terms, across the versions that name the
	 * accessor differently.
	 *
	 * @return array
	 */
	public static function shipping_classes() {
		foreach ( array( 'wc_get_shipping_classes', 'wc_get_product_shipping_classes' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				return (array) $fn();
			}
		}
		if ( function_exists( 'get_terms' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_shipping_class',
					'hide_empty' => false,
				)
			);
			return is_wp_error( $terms ) ? array() : (array) $terms;
		}
		return array();
	}

	/**
	 * Price a fake cart against a zone's method.
	 *
	 * @param WC_Shipping_Tapstitch $method Method.
	 * @param string                $products Lines of "product_id qty".
	 * @param string                $country Country code.
	 * @return array
	 */
	public static function preview_cart( $method, $products, $country ) {
		$by_class = array();
		$lines    = preg_split( '/\r\n|\r|\n/', (string) $products );

		foreach ( (array) $lines as $line ) {
			$bits = preg_split( '/\s+/', trim( (string) $line ) );
			if ( count( $bits ) < 1 || ! is_numeric( $bits[0] ) ) {
				continue;
			}
			$product_id = (int) $bits[0];
			$qty        = isset( $bits[1] ) ? max( 1, (int) $bits[1] ) : 1;
			$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			if ( ! $product ) {
				continue;
			}
			$class = (string) $product->get_shipping_class();
			if ( ! isset( $by_class[ $class ] ) ) {
				$by_class[ $class ] = array(
					'qty'  => 0,
					'name' => $product->get_name(),
				);
			}
			$by_class[ $class ]['qty'] += $qty;
		}

		$countries = $method->lookup_countries();
		$lookup_in = empty( $countries ) ? array( $country ) : $countries;
		$found     = TSM_Rates::lookup( $lookup_in, $method->tapstitch_slugs(), $method->get_option( 'lane' ), $method->get_option( 'fulfillment' ) );
		$agg       = TSM_Rates::aggregate( $found['rows'], $method->get_bound() );
		if ( 'country' === $method->price_mode() && ! empty( $found['rows'] ) ) {
			$mine = TSM_Rates::aggregate( TSM_Rates::only( $found['rows'], array( $country ), $method->tapstitch_slugs() ), $method->get_bound() );
			if ( ! empty( $mine ) ) {
				$agg = $mine;
			}
		}
		$fixed     = $method->fixed();

		$out = array();
		foreach ( $by_class as $class => $data ) {
			if ( isset( $fixed[ $class ] ) ) {
				$rule = $fixed[ $class ];
				$cost = $rule['first'] + ( $data['qty'] > 1 ? $rule['per_item'] * ( $data['qty'] - 1 ) : 0 );
			} else {
				$slug = $method->slug_for( $class );
				$rate = ( '' !== $slug ) ? TSM_Rates::find( $agg, $slug ) : null;
				if ( $rate ) {
					$cost = TSM_Rates::cost(
						$rate,
						$data['qty'],
						array(
							'handling' => (float) $method->get_option( 'handling' ),
							'pct'      => (float) $method->get_option( 'pct' ),
							'floor'    => (float) $method->get_option( 'floor' ),
							'cap'      => (float) $method->get_option( 'cap' ),
							'bound'    => $method->get_bound(),
							'round'    => $method->round_mode(),
						)
					);
				} else {
					$cost = (float) $method->get_option( 'fallback' );
				}
			}
			$out[] = array(
				'name' => $data['name'],
				'class' => '' === $class ? __( '(no class)', 'tapstitch-shipping-method' ) : $class,
				'qty'  => (int) $data['qty'],
				'cost' => '$' . number_format( (float) $cost, 2 ),
			);
		}
		return $out;
	}
}
