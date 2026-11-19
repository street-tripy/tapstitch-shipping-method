# Tapstitch Shipping Method

A WooCommerce shipping method that charges the shipping rates **Tapstitch publishes** — by
destination country, product category and lane — using Tapstitch's own pricing shape:
*first item + a charge for each additional item*.

Rates are never fetched during checkout. Checkout reads a cache, and the cache has a last-good
snapshot and a configured floor behind it, so shipping is never silently free.

## The rate model

Per shipping class, priced by the **customer's own country**:

```
cost = first + handling + additional × ( quantity − 1 )
```

* `first` / `additional` come from the supplier's published table for that destination.
* `additional` is taken at the **top of the published range** by default — the safe end of a range
  you cannot see inside.
* Rounding defaults to `nearest99` and **never rounds below the supplier's cost**.
* A zone's worst-case rate is computed across the zone's countries (plus any you add), so the store
  cannot be under-priced by pricing to the cheapest country.

The published table is keyed `country + category(slug) + lane + fulfilment`. Slugs are normalised, so
`tanktops` and `tank-tops` match the same rows.

## What you configure

In *Settings → Shipping → [zone] → Tapstitch Shipping*:

| Field | Key | Meaning |
|---|---|---|
| Mode | `mode` | `preview` (log what it *would* charge, charge nothing) or `live` |
| Shipping class map | `class_map` | class → supplier category slug, one pair per line; the default key prices unclassed goods |
| Price by | `price_by` | charge the customer's own country, or the zone's worst case |
| Bound | `bound` | which end of the published additional range to price (`max` — the top — is the safe default) |
| Lane / fulfilment | `lane`, `fulfillment` | which published lane and fulfilment option to price |
| Handling / uplift / floor / cap | `handling`, `pct`, `floor`, `cap` | per-parcel handling, percentage uplift, minimum charge, maximum charge |
| Other items cost | `fixed`, `unmatched` | what goods with no mapping cost, once per parcel — never zero |
| Multi-provider label | `multi_label` | wording when a cart contains more than one provider's goods |
| One rate only | `hide_other` | offer only this method's rate in the zones it prices |
| Provider sub-totals | `admin_breakdown`, `admin_breakdown_cap` | show what each supplier contributed, and to whom |
| Merge suppliers | `combine`, `supplier_claims` | merge supplier-split packages into one package per destination; let supplier plugins claim their own SKUs |
| Detection rules | `printify_meta`, `printful_meta`, `sku_pattern`, `provider_by_class`, `provider_default` | how a product's supplier is decided |
| Debug logging | `debug` | write the per-class trace to the plugin log |

Rounding is `nearest99` in code: the closest `.99` that is **not below** the computed cost. Provider
declaration keys are read through `TSM_Provider::meta_keys()` — the `tsm_provider_meta_key` option,
the shared `_psr_provider` key, and anything added with the `tsm_provider_meta_keys` filter.

## Rate data

The plugin ships with **no supplier rate table** — that data is generated from your own supplier
account. `data/seed-rates.example.json` shows the shape:

```json
{
  "source": "your supplier's published shipping-rates endpoint",
  "currency": "USD",
  "rows": [
    { "country": "US", "slug": "hoodies", "category": "Hoodies", "fulfillment": "International",
      "line": "Standard", "time_min": 5, "time_max": 8, "first": 4.00, "add_min": 2.00,
      "add_max": 3.00, "weight": 700 }
  ]
}
```

Copy it to `data/seed-rates.json` (git-ignored) and fill it with your own published rates, or let
the plugin fetch them: `GET /tapstitch-sm/v1/probe` pulls a destination's table into the cache, and
`POST /tapstitch-sm/v1/refresh` (the same job WP-Cron runs daily) refreshes every zone that uses
this method. If the cache and the file are both empty, the configured floor is used — the store
never quotes free shipping by accident.

## The merged label

When a cart contains goods from more than one source, the label reads `Shipping (2 providers)` — the
provider **count**, not the suppliers' names. Names are hidden by default and the switch to reveal
them is global. Sub-totals (what each supplier contributed) are rendered under the rate for staff
with `manage_woocommerce`, using WooCommerce's own `woocommerce_after_shipping_rate` hook — no
template override, no JavaScript, and they survive WooCommerce's shipping cache because they travel
on the rate.

Sub-total amounts are currency-safe: they are converted into the shop's display currency and the
rounding cents are shared out, so the lines add up to the price the customer is charged, to the cent.

## No quote, no sale

`TSM_Guard` blocks checkout when the cart needs shipping and nothing quoted it — including the
WooPayments express-checkout paths (Apple Pay / Google Pay on cart, product and button), which
otherwise let a customer pay a total with no shipping in it. The message is neutral and names no
contact.

## Handover to a provider registry

Used with the companion [Provider Shipping](https://github.com/street-tripy/provider-shipping)
plugin, this plugin stays the **rate source** the registry quotes through, and yields the zone's
checkout rate to it when the registry is Live and that zone has a registry method instance
(`TSM_Packages::defers_for()`). It never yields on a registry sub-package — that sub-package *is*
the registry asking this method for a price, and deferring there is a deadlock with no rate.

Zone and instance are read from `woocommerce_shipping_zone_methods`, because
`WC_Shipping_Method::get_zone_id()` does not exist on current WooCommerce, and the accessor that
does constructs every method in the zone (which runs supplier plugins' constructors mid-checkout).

## Diagnostics

All routes are under `tapstitch-sm/v1` and require `manage_woocommerce`.

| Route | Answers |
|---|---|
| `GET /status` | version, mode, cache state, guard wiring, shipping classes, class map |
| `GET /selftest` | the maths assertions, PASS/FAIL with expected-and-got |
| `GET /probe?country=US&slug=hoodies` | read the published table for a destination (writes the cache, never at checkout) |
| `POST /refresh` | re-pull rates for every zone that uses this method (the same job WP-Cron runs daily) |
| `GET /calc?zone=1&country=US&state=NY&postcode=10001&items=12:1,15:2` | price a synthetic cart through the zone's methods and report every rate |
| `GET /preview?…` | what a cart *would* be charged, with the per-class trace |
| `GET /breakdown?…` | the staff sub-total lines as rendered |
| `GET /debug` | the seed-file read trace and cache state, stage by stage |
| `POST /settings` | write method instance settings |
| `POST /shipping-classes` | create a missing shipping class (the core REST API has no route for them) |

## Requirements

* WordPress 6.0+, PHP 7.4+
* WooCommerce 6.0+ (developed and tested against 11.x)
* A Tapstitch account whose published shipping rates you price from. No API key is needed for the
  published rate table; the plugin reads the public rates endpoint and caches it.

## Download

The install-ready zip is on the [latest release](https://github.com/street-tripy/tapstitch-shipping-method/releases/latest):
[`tapstitch-shipping-method-1.3.2.zip`](https://github.com/street-tripy/tapstitch-shipping-method/releases/latest/download/tapstitch-shipping-method-1.3.2.zip)
— one folder inside, `tapstitch-shipping-method/`, so it uploads straight into WordPress.

## Install

**In WordPress:** *Plugins → Add New → Upload Plugin* → choose the zip → *Install Now* → *Activate*.

**By FTP:** unzip and upload the folder to

```
wp-content/plugins/tapstitch-shipping-method/
```

then activate, add the method to a shipping zone, map your shipping classes to supplier categories,
and start in **preview** mode: it logs what it would charge, charges nothing, and you compare
against the prices you are actually paying.

## Development

```
php -l tapstitch-shipping-method.php
```
The self-check (`GET /tapstitch-sm/v1/selftest`) is the test suite: 57 assertions covering the rate
aggregation model, rounding that never goes below cost, the published-table parser (cents→dollars,
slug normalisation, lane/fulfilment selection), the delivery-time label, package merging, the
sub-total builder, the guard's decision table, and the registry handover rule.

## Data not included

No supplier rate table, no store data, no credentials, no shop names. Rate data is generated from
your own account; see *Rate data* above.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Tapstitch is a third party's trademark; this plugin is
an independent integration and is not affiliated with or endorsed by them.
