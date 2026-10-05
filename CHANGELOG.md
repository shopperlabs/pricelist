# Changelog

All notable changes to `shopper/pricelist` will be documented in this file.

## v1.0.0 - 2026-10-05

> [!IMPORTANT]
First release of Price Lists, a free add-on for Shopper 3. Sale and negotiated prices targeted by customer, customer group, channel or zone, with time windows, quantity tiers, percentage adjustments and quantity rules.

### Installation

Shopper 3 is still a release candidate, so your application needs:

```json
"minimum-stability": "RC",
"prefer-stable": true

```
```bash
composer require shopper/pricelist
php artisan shopper:pricelist:install

```
The install command runs the migrations, indexes and records the current public prices, then registers the add-on in `AppServiceProvider`. To register it by hand:

```php
use Shopper\Facades\Shopper;
use Shopper\PriceList\PriceListAddon;

Shopper::addons([
    new PriceListAddon,
]);

```
The add-on schedules `shopper:pricelist:record-prices` every minute and syncs prices through queued jobs: keep the scheduler and a queue worker running.

### New Features

- feat(pricing): `sale` and `override` price lists with a status, a priority and an optional window from `starts_at` to `ends_at`
- feat(pricing): fixed prices per product or variant and per currency, with quantity tiers read on the cart line or on the whole product
- feat(pricing): percentage adjustments of the catalog price, with rounding per currency
- feat(pricing): compare-at modes `keep`, `base`, `lowest_30_days` and `none`, backed by a price history for the lowest price of the last 30 days (EU Omnibus directive)
- feat(pricing): quantity rules (minimum, maximum, increment) per product or variant
- feat(rules): target a list at customers, customer groups (with `shopper/customer-groups`), channels or zones, and register your own rules through `ApplicabilityRule`
- feat(core): the add-on decorates Shopper's `PriceResolver`, `QuantityRuleResolver` and `ProductPriceIndex`, so cart lines, the Store API (`calculated_price`, `quantity_rule`) and product listings use list prices
- feat(admin): price list index, form, price editor and price simulator under Catalog, Products, reusing the product permissions
- feat(events): `PriceListSaved`, `PriceListPricesSaved` and `PriceListDeleted` for an audit log, with the acting user and the changed values
- feat(console): `shopper:pricelist:rebuild-index` to rebuild the price index after imports that fire no model events
- Translations in English, French and Spanish

### Requirements

- PHP `8.3+`
- Laravel `12.x` or `13.x`
- Shopper `^3.0.0-rc.3`, which also matches `3.0.0` stable
- A running scheduler and queue worker
- A cache store shared by every server (Redis, Memcached, database) when the application runs on more than one server

### Contributors

@mckenziearts

**Full Changelog**: https://github.com/shopperlabs/pricelist/commits/v1.0.0
