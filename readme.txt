=== Tapstitch Shipping Method ===
Contributors: street-tripy
Tags: woocommerce, shipping, tapstitch, print on demand, rates
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 11.3
Stable tag: 1.3.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WooCommerce shipping method that charges the shipping rates Tapstitch publishes, by destination country, category and lane, using the first-item + additional-item model. Cached rates, per shipping class, never free by accident.

== Description ==

This method prices a cart the way the supplier prices it: `first + handling + additional ×
( quantity − 1 )`, per shipping class, by the **customer's own country**.

= Rate model =

* `first` / `additional` come from the supplier's published table for that destination, keyed
  `country + category(slug) + lane + fulfilment`.
* `additional` is taken at the **top of the published range** by default — the safe end of a range
  you cannot see inside.
* Rounding defaults to `nearest99` and **never rounds below the supplier's cost**.
* A zone's worst-case rate is computed across the zone's countries (plus any you add), so the
  store cannot be under-priced by pricing to the cheapest country.
* Slugs are normalised: `tanktops`, `tank-tops` and `Tank Tops` resolve to the same rows.

= Never free =

Checkout never calls the supplier API. It reads a cache; the cache has a last-good snapshot behind
it, and a configured floor behind that. If nothing answers, the floor is charged — shipping is
never silently free.

= Preview mode =

Start in `preview`: the plugin logs what it *would* charge, per shipping class, and charges nothing,
so you can compare against the prices you are actually paying before it touches a customer.

= Staff sub-totals =

`woocommerce_after_shipping_rate` renders what each supplier contributed under the rate label, for
users with `manage_woocommerce` only. Customers see the rate label and nothing else. Amounts are
currency-safe: converted into the shop's display currency, with the rounding cents shared out, so
the lines add up to the price charged to the cent.

= Merged label =

When a cart contains goods from more than one source the label reads `Shipping (2 providers)` — the
count, not the names. Supplier names are hidden by default; the switch to reveal them is global.
A delivery-estimate label (`Shipping (9-14 days)`) is added for a single provider and dropped when
parcels have different times.

= No quote, no sale =

`TSM_Guard` blocks checkout when the cart needs shipping and nothing quoted it, including the
WooPayments express-checkout paths (Apple Pay / Google Pay on cart, product and button) that
otherwise let a customer pay a total with no shipping in it. The wording is neutral and names no
contact.

= Provider detection =

Which supplier fulfils a product: explicit provider meta, then the sync plugins' own tags
(`printify_product_id`, `printify_variant_id`, `_printful_product_id`), then a SKU pattern (default:
15+ digits, the shape Tapstitch design ids take), then the shipping class map, then a default. The
meta keys read are configurable (`TSM_Provider::meta_keys()`, filter
`tsm_provider_meta_keys`), so a store migrating from another plugin needs no data migration.

= Companion plugin =

With [Provider Shipping](https://github.com/street-tripy/provider-shipping) installed, this
plugin is the rate source the registry quotes through, and yields the zone's checkout rate to it
when the registry is Live and that zone has a registry method instance. It never yields on a
registry sub-package — that sub-package is the registry asking this method for a price.

= Rate data =

No supplier rate table is shipped: it is generated from your own supplier account.
`data/seed-rates.example.json` shows the shape; copy it to `data/seed-rates.json` (git-ignored) and
fill it with your published rates, or use `GET /tapstitch-sm/v1/probe` and `POST /refresh` (the same
job WP-Cron runs daily).

== Installation ==

Easiest: download `tapstitch-shipping-method-1.3.2.zip` from the repository's [latest release](https://github.com/street-tripy/tapstitch-shipping-method/releases/latest) and use *Plugins → Add New → Upload Plugin* in WordPress (the zip contains one folder, `tapstitch-shipping-method/`).

1. Upload `tapstitch-shipping-method` to `/wp-content/plugins/`.
2. Activate it.
3. *Settings → Shipping → [your zone] → Add shipping method → Tapstitch Shipping*.
4. Map your shipping classes to supplier categories (`hoodies`, `sweatpants`, `t-shirts`, …), set
   the lane and fulfilment you ship on, and set a floor.
5. Start in **preview** mode, read `GET /tapstitch-sm/v1/calc` and the log against real carts, then
   switch to live.

== Frequently Asked Questions ==

= Does checkout call the supplier? =

No. Checkout reads a cache. Refreshes are cron-driven or explicit.

= What if the supplier's API is down? =

The last good snapshot is used; failing that, the configured floor. Shipping is never free by
accident.

= Can it price below the supplier's cost? =

No — the additional cost is taken at the top of the published range by default, and rounding never
rounds below the computed cost.

= Will my customers see the supplier? =

No. The label carries a provider count, names are hidden by default, and the staff sub-totals are
rendered only for users with the WooCommerce management capability.

= I ship from more than one supplier in one cart. =

The merged label counts providers, the sub-totals break the price down per supplier for staff, and
the companion Provider Shipping plugin turns that into a provider registry with its own margin
policy.

== Changelog ==

= 1.3.2 =
* Handover to the provider registry: `TSM_Packages::defers_for()` yields a zone's checkout rate to
  `provider-shipping` when its pipeline is Live and that zone has a registry method instance, so
  `hide_other` no longer wipes the registry's merged rate. This plugin keeps its instance in the
  zone as the registry's **rate source**.
* Deadlock guard: a registry sub-package is always answered, never deferred. Measured with the
  guard off, carts lost their price entirely.
* `TSM_Packages::zone_for_instance()` reads the zone from `woocommerce_shipping_zone_methods`:
  `WC_Shipping_Method::get_zone_id()` does not exist on current WooCommerce, and the accessor that
  does constructs every method in the zone.
* Self-check 57 assertions, six of them the handover rule.

= 1.3.1 =
* **The guard reports its own wiring.** `guard.wired` in `/status` lists every hook the guard
  registered (checkout process, the order-review notice, and the WooPayments express-checkout
  filters), so "guard on" and "guard off" are measurable facts rather than assumptions — the switch
  makes `register()` return early, and the report shows it.
* `in-house` and a store's other words for self-shipped goods (`inhouse`, `self`, `own`, `site`,
  `flat`, `flat-rate`) map to the **zone's existing Flat Rate** instance, so goods the owner ships
  are priced by what the store already decided, never at zero.

= 1.3.0 =
* **No shipping quote = no sale** (`class-tsm-guard.php`). The order is refused server-side
  (`woocommerce_checkout_process`) when the cart needs shipping and no package has a quoted price,
  nothing is chosen, or the chosen method is not among the quotes; the same notice is shown in the
  order-review area. WooPayments express checkout (Apple Pay / Google Pay) is switched off in the
  same situation on cart and product pages — that flow can otherwise complete an order without the
  shipping step being validated. Site-wide switches: `tapstitch_sm_block_no_quote` (default yes) and
  `tapstitch_sm_no_quote_message`, settable at `POST /tapstitch-sm/v1/settings`. The default message
  is neutral and names no contact address. The decision is a pure function (`TSM_Guard::decide`) so
  it is unit-tested.
* Provider detection (`TSM_Provider`): explicit provider meta, then the supplier plugins' own tags,
  then a SKU pattern, then the shipping class map, then a default. The declaration meta keys are
  configurable through `TSM_Provider::meta_keys()` and the `tsm_provider_meta_keys` filter.
* `POST /shipping-classes` creates shipping-class terms, which the core REST API has no route for.
  A product's class has to be written as **both** `shipping_class_id` and `shipping_class`: the id
  alone is ignored, the slug alone creates the term without assigning it.

= 1.2.0 =
* **Provider sub-totals, staff only.** Under the rate label, a store manager with
  `manage_woocommerce` sees what each supplier contributed and from which source; a customer sees
  the rate label and nothing else. The data travels on the rate's own meta, so it also shows when
  WooCommerce re-renders a cached rate. Settings: "Show provider sub-totals to admins", "Show
  sub-totals to users who can".
* Rendered at WooCommerce's own `woocommerce_after_shipping_rate` hook - no template override, no
  JavaScript, escaped text.
* `GET /tapstitch-sm/v1/breakdown` proves the whole path server-side: rate label, cost, the stored
  lines, the printed markup, and the current user's capability.

= 1.1.3 =
* Rate meta is written as key => value pairs. `WC_Shipping_Method::add_rate()` runs each value
  through `wc_clean()`, so a list-of-`['key','value']` form produced empty meta and threw the audit
  trail away. Recorded properly now, with the base-currency cost, read via
  `WC_Shipping_Rate::get_meta_data()`.
* Shipping tax mode is `per_order`, matching how supplier plugins tax shipping.

= 1.1.2 =
* Package merging, rate hiding and method re-registration moved to plugin boot
  (`class-tsm-packages.php`). They cannot work from inside a shipping-method instance: WooCommerce
  builds the cart's shipping packages *before* it constructs the methods that price them, so a hook
  registered by the instance is a request too late. The zone's own instance settings are read per
  package instead.

= 1.1.1 =
* Supplier-split packages are merged back into one package per destination
  (`woocommerce_cart_shipping_packages`, priority 20). A supplier plugin that splits a cart into
  "ours" and "theirs" makes WooCommerce show a separate shipment - each with its own shipping
  choice - per package. Merging first means one shipment, one rate, and the merged label.
* Item keys are namespaced by source package: a supplier split reindexes contents numerically, so a
  plain merge would drop items.
* Self-check covers the merge rules (one destination merges, two destinations never do).

= 1.1.0 =
* **One rate, titled "Shipping".** "Show only this rate" (default on): in the zones this method
  prices, the customer is shown this rate and nothing else; other methods' per-package rates are
  removed from the checkout list. WooCommerce's shipping screens still show every method, and zones
  this method does not price are untouched. A supplier plugin that wipes and rebuilds the method
  list is followed by re-registering this method (`woocommerce_load_shipping_methods`).
* **Mixed carts merge into one price.** Tapstitch goods from the published table, other suppliers'
  goods from their live cost, summed into one label such as `Shipping (2 providers)`. Supplier
  quotes are added once per supplier; a supplier that cannot be quoted falls to the instance's
  "Other items cost", never to zero.
* The method declares `shipping-zones` support. Without it WooCommerce also ran a global instance
  (id 0) on every package, adding an unconfigured rate to the customer's checkout.
* Merged cost rounds up and never below the suppliers' cost.

= 1.0.5 =
* The seed file is read without touching PCRE: a `preg_replace()` whose pattern begins with a slash
  returns null on PHP 8.4 (PCRE2), and the null then failed `json_decode`, so the bundled rate seed
  was unavailable. The BOM check is now a byte comparison on the first three bytes.
* `/debug` reports `seed_trace()`: read length, first bytes, decode type, JSON error, row count and
  valid count, each measured on the server.

= 1.0.4 =
* Zone accessors are probed with `method_exists()` and both shapes accepted:
  `WC_Shipping_Zone::get_zone_by()`, `get_name()` and `get_locations()` do not exist on current
  WooCommerce (the real ones are `WC_Shipping_Zones::get_zone_by()`, `get_zone_name()`,
  `get_zone_locations()`), and calling a phantom one fatals the request that renders shipping - so
  the customer sees no shipping at all. Zone id for an instance also resolves with a direct database
  SELECT as a fallback.
* Seed rate file: written without a UTF-8 BOM and read tolerantly - `json_decode` rejects a BOM,
  which made a whole seed file silently unusable.
* `/debug` reports the seed file as the server sees it, the last fatal, and the zone/method rows;
  `/calc` prices a synthetic package and builds cart lines with `data` (core methods call
  `$values['data']->needs_shipping()`).

= 1.0.0 =
* First release: the published-rate parser (cents → dollars, slug normalisation, lane and
  fulfilment selection), per-shipping-class mapping, the first + additional aggregation model,
  zone worst-case and own-country price modes, delivery-time labels, the rate cache with cron
  refresh, preview mode, the configured floor, and the REST diagnostics.

== Upgrade Notice ==

= 1.3.2 =
Safe to use with a provider registry plugin; yields the checkout rate to it. No data migration.

= 1.0.0 =
Adds a shipping method. Nothing changes until you add it to a zone.
