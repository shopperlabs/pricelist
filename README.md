# Shopper Price Lists

![PHP Version](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/laravel-12.x%20%7C%2013.x-FF2D20?logo=laravel&logoColor=white)
![Shopper](https://img.shields.io/badge/shopper-3.x-6366F1)
![License](https://img.shields.io/badge/license-MIT-blue)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/shopper/pricelist)](https://packagist.org/packages/shopper/pricelist)
[![Total Downloads](https://img.shields.io/packagist/dt/shopper/pricelist)](https://packagist.org/packages/shopper/pricelist)

Price lists for [Shopper](https://laravelshopper.dev): sale and negotiated prices targeted by customer, customer group, channel or zone, with time windows, quantity tiers, percentage adjustments and quantity rules.

A price list has a type:

- `sale` lowers the price. It applies only when its amount is below the regular price of the customer, and can show a compare-at price.
- `override` replaces the price, up or down, without a compare-at price by default. Use it for negotiated B2B rates or a market's own prices.

## Requirements

- PHP `8.3+`
- Laravel `12.x` or `13.x`
- Shopper `3.x`
- A running scheduler and queue worker
- A cache store shared by every server (Redis, Memcached, database), not `file` or `array`, as soon as the application runs on more than one server

While Shopper 3.0 is a release candidate, set `"minimum-stability": "rc"` with `"prefer-stable": true` in your application's `composer.json`.

## Installation

```bash
composer require shopper/pricelist
php artisan shopper:pricelist:install
```

The install command runs the migrations, indexes and records the current public prices, then registers the addon in `App\Providers\AppServiceProvider`. When it cannot edit that file, register the addon yourself in `register()`:

```php
use Shopper\Facades\Shopper;
use Shopper\PriceList\PriceListAddon;

public function register(): void
{
    Shopper::addons([
        new PriceListAddon,
    ]);
}
```

Price lists appear under Catalog, Products, in the admin sidebar. The pages reuse the product permissions (`products.browse`, `products.create`, `products.edit`, `products.delete`).

### Scheduler and worker

The addon schedules `shopper:pricelist:record-prices` every minute, on one server, without overlap. It catches the price lists whose start or end date has passed since its last run and queues their products for indexing and price history. Keep `php artisan schedule:work` (or the `schedule:run` cron entry) running.

Each change to a price list, its prices, its rules or a catalog price queues a `SyncProductPrices` job per product. Run a queue worker. A job is unique until it starts, so a bulk import that touches the same product many times queues it once. Products with many variants across many currencies, zones and channels can take longer than the default worker timeout of 60 seconds: raise `--timeout` for them.

The schedule lock, the job uniqueness and the per-product overlap locks live in the cache. After a killed process, the schedule lock expires within 10 minutes.

Imports that write catalog prices through the query builder fire no model events. Rebuild the index after them:

```bash
php artisan shopper:pricelist:rebuild-index
```

The command runs synchronously, 100 products at a time. It refuses to run while price lists exist and the addon is not registered, because it would then index catalog prices over your lists.

## Price lists

A price list holds:

- a status (`draft`, `active`, `archived`) and an optional window `starts_at` to `ends_at`. The window includes its start and excludes its end. Dates are stored in UTC; the admin shows and takes them in the timezone of the administrator, or `app.timezone`.
- a priority. When several lists apply, the highest priority wins.
- fixed prices per product or variant, per currency, with optional quantity tiers (`min_quantity`, `max_quantity`) and an optional compare-at amount.
- an optional adjustment: a percentage up or down applied to the catalog price of every product the list does not price.
- an optional rounding per currency.
- a compare-at mode.
- quantity rules per product or variant.
- applicability rules: who gets these prices.

### Fixed prices and tiers

A variant without a price in a list, for the requested currency, takes the price of its product in that list. Among the tiers that cover the quantity, the one with the highest `min_quantity` wins.

The tier scope decides which quantity a tier reads:

- `item` (default): the quantity of the cart line.
- `product`: the quantity of all variants of the same product in the cart. Two lines of 5 in different sizes reach a tier that starts at 10.

### Adjustments and rounding

An adjustment is stored in basis points: `1250` is 12.5%. A decrease goes up to 99.99%, an increase up to 100%. The result is rounded half up to the minor unit and never drops below 1.

Rounding sets the ending of adjusted prices, in minor units, per currency. With `{"USD": 99}`, $20.00 minus 10% gives $18.00, rounded down to $17.99. Rounding never raises a price. When it would remove more than the adjustment itself, or drop below 1, the unrounded amount stays. Rounding applies to adjusted prices only, never to fixed ones.

### Compare-at prices

| Mode | Compare-at price |
| --- | --- |
| `keep` | The compare-at amount of the list price, or the compare-at price of the catalog |
| `base` | The compare-at amount of the list price, or the catalog price |
| `lowest_30_days` | The lowest public price of the last 30 days, see [Price history](#price-history-and-the-lowest-price-of-30-days) |
| `none` | The compare-at amount of the list price, if any |

A `sale` list defaults to `lowest_30_days`, an `override` list to `none`. A compare-at price lower than or equal to the price is dropped.

### Quantity rules

A quantity rule sets a minimum, an optional maximum and an increment. The rule of the list that sets the price applies; without one, Shopper's own rule applies. Set it on a list that prices quantity 1: a rule on a list that only prices from 10 units never applies to a customer adding 1. A rule on a product applies to each of its variants without their own rule, line by line, maximum included.

## Who gets a price

Built-in rules:

| Key | Matches |
| --- | --- |
| `customer` | Selected customers |
| `customer_group` | Members of selected active groups, when [`shopper/customer-groups`](https://github.com/shopperlabs/customer-groups) is registered |
| `channel` | Requests sent with one of the selected channels |
| `zone` | Requests sent with one of the selected zones |

A list matches when any value of a rule matches, and every rule of the list matches. A list without rules applies to everyone, so removing the last rule of a list makes it public. A list whose rule is no longer registered never applies. A rule that throws is reported and its list does not apply.

A rule that targets a zone, a channel or a customer that no longer exists stops matching anyone.

To target customer groups, register both addons:

```php
Shopper::addons([
    new CustomerGroupsAddon,
    new PriceListAddon,
]);
```

The `customer_group` rule registers itself only when the `customer-groups` addon is active.

### Restricting who gets a price

Channel and zone rules match the channel and zone the storefront sends in the `X-Shopper-Channel` and `X-Shopper-Zone` headers. Any client can send these headers, so these rules point a price list at a storefront or a market without restricting who gets its prices. To reserve prices for specific buyers (wholesale, B2B, staff), add a customer or customer group rule: those match the authenticated customer only.

## Custom rules

A rule implements `Shopper\PriceList\Contracts\ApplicabilityRule`:

```php
namespace App\Pricing;

use App\Models\User;
use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Contracts\ApplicabilityRule;

final class B2BCompanyRule implements ApplicabilityRule
{
    /** @var array<int, int|null> */
    private array $companies = [];

    public function key(): string
    {
        return 'b2b_company';
    }

    public function label(): string
    {
        return __('Companies');
    }

    public function passes(array $values, PricingContext $context): bool
    {
        if ($context->customerId === null) {
            return false;
        }

        $company = $this->companies[$context->customerId] ??= User::query()
            ->whereKey($context->customerId)
            ->value('company_id');

        return in_array($company, array_map(intval(...), $values), true);
    }
}
```

Register it on the addon, by class name or as an instance:

```php
Shopper::addons([
    (new PriceListAddon)->rules([B2BCompanyRule::class]),
]);
```

`app(ApplicabilityRuleManager::class)->register(...)` from a service provider works too.

- `key()` is stored with each list. Never change it once lists use it.
- `passes()` receives the stored values and the audience of the request: currency, customer, channel and zone. It does not receive the quantity, and the result is memoized per audience for the request. Decide from `$values` and the context only, never from `auth()` or `request()`: prices are also resolved in queued jobs, without a request or an authenticated user.
- A rule that keeps state, like the memo above, registers by class name and is bound `scoped` in the container, so each request (or Octane request) gets a fresh instance:

  ```php
  $this->app->scoped(B2BCompanyRule::class);
  ```

- To show a field in the price list form, also implement `Shopper\PriceList\Contracts\HasRuleForm`. Its `formField()` returns a Filament 5 `Filament\Schemas\Components\Component` named `rules.{key}`. Without it, the rule works but the form does not show it.

## How prices resolve

The addon decorates Shopper's `PriceResolver`, `QuantityRuleResolver` and `ProductPriceIndex` contracts. For a product or a variant in a context:

1. The catalog price is resolved first.
2. Active lists whose window covers now and whose rules match are kept.
3. Each list makes an offer: its fixed price for the quantity, or its adjustment of the catalog price.
4. Offers are ordered by priority, then number of rules, then lowest amount.
5. The regular price is the first `override` offer, or the catalog price.
6. The first `override` offer wins, unless a `sale` offer before it is below the regular price. Without a winner, the catalog price stays.

A sale never raises a price, and it beats the regular price of the customer, negotiated rates included.

The resolved price carries a `meta` array:

```php
[
    'public' => ['price_list' => ['id' => '01JC4M6Q2T8XK3V5N7R9W1Y0ZB', 'type' => 'sale']],
    'price_list' => ['id' => '01JC4M6Q2T8XK3V5N7R9W1Y0ZB', 'name' => 'Summer sale', 'type' => 'sale'],
    'source' => 'fixed',
    'level' => 'variant',
    'tier' => [10, null],
]
```

For an adjusted price, `source` is `adjustment`, and `level` and `tier` are `null`. `level` holds the morph alias of the priced model (`product` or `variant`). Only the `public` key reaches the Store API.

Cart lines and order items keep this array in their `pricing` column. It is a snapshot: renaming or deleting the list later does not change it.

## Cart

Cart lines are repriced at fixed moments, never on each total calculation:

- adding a product, including a merge into an existing line
- changing a line quantity
- removing a line, for the other lines of the same product (product tiers)
- merging carts
- transferring or claiming a cart at login
- changing the zone or the channel of a cart with `CartManager::changeContext()`, which also resets the shipping method and the payment method
- creating a payment session
- completing the cart: when a price changed since the last repricing, the cart is repriced and completion fails with a 422 `price_changed` error, so the customer sees the new total before paying

Shopper dispatches `Shopper\Cart\Events\CartLinesRepriced` with the changed lines. The cart transfer endpoint also returns the changed prices in `meta.price_changes`.

A line breaking the quantity rule fails with a 422 `quantity_rule_violated` error.

### Manual prices

An administrator can set the price of a line:

```php
use Shopper\Cart\CartManager;

app(CartManager::class)->setLinePrice($cart, $line->id, 4500);
app(CartManager::class)->clearLinePrice($cart, $line->id);
```

A manual price locks the price and the quantity of the line: repricing skips it, and changing its quantity fails with a 409 `cart_line_locked` error. `clearLinePrice()` resolves the price again. These methods check no permission and are not exposed in the Store API: check the permission before calling them.

### Discounts and taxes

A list price is the unit price of the line: coupons and automatic discounts apply on top of it.

Enter list prices with the same tax convention as your catalog prices. Whether a price includes tax comes from the tax zone of the shipping address, and a list price is read the same way.

## Store API

Products and variants returned by the product endpoints of the Store API carry:

```json
{
    "calculated_price": {
        "amount": 1799,
        "compare_amount": 2000,
        "original_amount": 2000,
        "currency_code": "USD",
        "meta": { "price_list": { "id": "01JC4M6Q2T8XK3V5N7R9W1Y0ZB", "type": "sale" } }
    },
    "quantity_rule": { "minimum": 1, "maximum": 12, "increment": 1 }
}
```

They are resolved for quantity 1 and the audience of the request. `quantity_rule` is `null` without a rule.

`original_amount` is the catalog price, present even when no compare-at price applies. Show `compare_amount` crossed out, never `original_amount`: displaying the catalog price as a former price can break price display laws.

## Listings

Sorting, filtering and price ranges of product listings read the table `price_list_product_prices`, rebuilt per product by `SyncProductPrices`. At request time, the lists that apply to the audience and the current date pick their rows, so customer, group, channel and zone prices sort correctly.

- Rows are computed for quantity 1: tiers from 2 units do not change the listing price.
- Adjustments apply to the public catalog price.
- The table follows changes through the queue, so a listing can show the previous price for the time the worker takes.

`ProductPriceIndex::minPriceExpression()` returns raw SQL without bindings: the addon inlines integer ids only. A decorator of this contract must keep it that way.

## Price history and the lowest price of 30 days

To show the lowest price of the last 30 days (the EU Omnibus directive), the addon records public prices in `price_list_price_history`: one row per product or variant, currency and scope (global, zone, channel) each time the price a visitor sees changes. Rows are recorded by `SyncProductPrices` and by the scheduled command when a list starts or ends.

A `sale` in `lowest_30_days` mode shows a compare-at price only once its own price is recorded with a proven reference. The compare-at price is the lowest price over the 31 days of history before the sale, capped by the compare-at amount of the list price and by the regular price.

- A list that targets customers (a customer, customer group or custom rule) shows no compare-at price in this mode: history records public prices only, globally and per zone and channel.
- A tier shows no compare-at price either: history records the price of quantity 1.
- When the scheduler or the worker stops while a public list starts or ends, the prices shown in between cannot be proven. The next recorded price carries no reference, and sales on that product show no compare-at price until that row leaves the 31-day window. Two cases escape this check: a list deleted for good, and a list still active, beaten by another list since, that set the price during the outage. Archive lists instead of deleting them.

History grows with every price change. Pruning is opt-in: schedule

```bash
php artisan model:prune --model="Shopper\PriceList\Models\PriceHistory"
```

It deletes the rows older than 31 days that a later row of the same scope replaces, and keeps the row in effect.

When a product or a variant is deleted for good, its list prices, quantity rules, history and index rows go with it. A product in the trash keeps them.

## Events

Three events record who changed what, for an audit log. They are dispatched once the transaction commits, and `actorId` is the id of the user authenticated on the Shopper guard (`null` in a command or a queued job).

| Event | Dispatched when | Payload |
| --- | --- | --- |
| `PriceListSaved` | A list is created or updated, or one of its rules changes | `priceList`, `actorId`, `before`, `after` |
| `PriceListPricesSaved` | Prices are saved or removed in the price editor | `priceList`, `actorId`, `changes` |
| `PriceListDeleted` | A list is deleted | `priceList`, `actorId` |

`before` and `after` hold the changed columns only. On creation, `before` is empty. A rule change is keyed by the rule: `['rules' => ['customer_group' => [3, 7]]]`, with `null` for a rule added or removed.

Each entry of `changes` is one product or variant in one currency, with its tiers before and after:

```php
[
    'priceable_type' => 'variant',
    'priceable_id' => 42,
    'currency' => 'USD',
    'before' => [['min_quantity' => 1, 'max_quantity' => null, 'amount' => 1100, 'compare_amount' => null]],
    'after' => [['min_quantity' => 1, 'max_quantity' => null, 'amount' => 950, 'compare_amount' => null]],
]
```

Prices written outside the editor (a seeder, an import through the models) dispatch no `PriceListPricesSaved`.

```php
use Illuminate\Support\Facades\Event;
use Shopper\PriceList\Events\PriceListPricesSaved;

Event::listen(function (PriceListPricesSaved $event): void {
    foreach ($event->changes as $change) {
        logger()->info('List prices changed', [
            'price_list' => $event->priceList->public_id,
            'actor' => $event->actorId,
            ...$change,
        ]);
    }
});
```

## Overriding the admin pages

```php
Shopper::addons([
    (new PriceListAddon)->usingLivewireComponents([
        'pricelist.index' => App\Livewire\CustomPriceListIndex::class,
    ]),
]);
```

| Key | Page |
| --- | --- |
| `pricelist.index` | Price list index |
| `pricelist.form` | Create and edit form |
| `pricelist.prices` | Price editor of a list |
| `pricelist.simulator` | Price simulator |

Unknown keys are ignored.

## Limitations

- No CSV import of list prices in 1.0. See the roadmap.
- Listings sort and filter on the price of quantity 1, see [Listings](#listings).

## License

MIT. See [LICENSE.md](LICENSE.md).
