<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shopper\Core\Models\Channel;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\Zone;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\PriceList\Tests\Stubs\UnavailableRule;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    $this->product = withCatalogPrice(Product::factory()->create(['created_at' => now()]), 10000);
});

function later(int $days): void
{
    test()->travel($days)->days();
    app()->forgetScopedInstances();
}

it('strikes the lowest public price of the 30 days before the sale', function (): void {
    later(40);
    listPrice(PriceList::factory()->create(), $this->product, 7000);

    expect(priceOf($this->product))
        ->amount->toBe(7000)
        ->compareAmount->toBe(10000);
});

it('refuses to strike a price higher than a sale of the last 30 days', function (): void {
    later(10);
    $first = PriceList::factory()->create();
    listPrice($first, $this->product, 8000);
    later(5);
    $first->update(['status' => PriceListStatus::Archived]);
    later(10);
    listPrice(PriceList::factory()->create(), $this->product, 9000);

    expect(priceOf($this->product))
        ->amount->toBe(9000)
        ->compareAmount->toBeNull();
});

it('forgets the sales that ended more than 30 days ago', function (): void {
    later(1);
    $first = PriceList::factory()->create();
    listPrice($first, $this->product, 5000);
    later(1);
    $first->update(['status' => PriceListStatus::Archived]);
    later(40);
    listPrice(PriceList::factory()->create(), $this->product, 9000);

    expect(priceOf($this->product))
        ->amount->toBe(9000)
        ->compareAmount->toBe(10000);
});

it('counts a lower price that ended just over 30 days before a sale', function (): void {
    later(1);
    $first = PriceList::factory()->create();
    listPrice($first, $this->product, 6000);
    later(1);
    $first->update(['status' => PriceListStatus::Archived]);
    later(30);
    $this->travel(12)->hours();
    listPrice(PriceList::factory()->create(), $this->product, 9000);

    expect(priceOf($this->product)->compareAmount)->toBeNull();
});

it('strikes no price without any history', function (): void {
    listPrice(PriceList::factory()->create(), $this->product, 7000);
    PriceHistory::query()->delete();
    app()->forgetScopedInstances();

    expect(priceOf($this->product)->compareAmount)->toBeNull();
});

it('strikes no price a product created during a sale was never sold at', function (): void {
    PriceList::factory()->decrease(2000)->create();

    expect(priceOf(withCatalogPrice(Product::factory()->create(['created_at' => now()]), 10000)))
        ->amount->toBe(8000)
        ->compareAmount->toBeNull();
});

it('strikes no price before the history covers 30 days of a product older than the add-on', function (): void {
    $product = Model::withoutEvents(fn (): Product => withCatalogPrice(Product::factory()->create(['created_at' => now()->subDays(60)]), 12000));
    $this->artisan('shopper:pricelist:rebuild-index')->assertSuccessful();
    later(1);
    listPrice(PriceList::factory()->create(), $product, 9000);

    expect(priceOf($product))
        ->amount->toBe(9000)
        ->compareAmount->toBeNull();
});

it('records a new sale taking over an amount sold for weeks, without striking it', function (): void {
    later(1);
    $first = PriceList::factory()->create();
    listPrice($first, $this->product, 8000);
    later(40);
    $second = PriceList::factory()->create(['priority' => 1]);
    listPrice($second, $this->product, 8000);

    expect(priceOf($this->product))
        ->amount->toBe(8000)
        ->compareAmount->toBeNull()
        ->and(PriceHistory::query()->orderBy('id')->pluck('price_list_id')->all())->toBe([null, $first->id, $second->id]);
});

it('records the public price going back up once a sale targets customers', function (): void {
    $sale = PriceList::factory()->create();
    listPrice($sale, $this->product, 7000);
    later(1);

    PriceListRule::factory()->for($sale)->create(['rule_key' => 'customer', 'values' => [User::factory()->create()->id]]);

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000, 10000]);
});

it('strikes no price when a targeted sale matches the public price', function (): void {
    $customer = User::factory()->create();
    later(1);
    listPrice(PriceList::factory()->create(), $this->product, 8000);
    later(1);
    $vip = PriceList::factory()->create(['priority' => 1]);
    PriceListRule::factory()->for($vip)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);
    listPrice($vip, $this->product, 8000);

    expect(priceOf($this->product, ['customerId' => $customer->id])->compareAmount)->toBeNull()
        ->and(priceOf($this->product)->compareAmount)->toBe(10000);
});

it('keeps the sales of a zone out of the history of the others', function (): void {
    $zone = Zone::factory()->create();
    later(10);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 8000);
    later(5);
    $zoneSale->update(['status' => PriceListStatus::Archived]);
    later(10);
    listPrice(PriceList::factory()->create(), $this->product, 9000);
    later(0);

    expect(PriceHistory::query()->whereNull('zone_id')->orderBy('id')->pluck('amount')->all())->toBe([10000, 9000])
        ->and(PriceHistory::query()->where('zone_id', $zone->id)->orderBy('id')->pluck('amount')->all())->toBe([8000, 10000, 9000])
        ->and(priceOf($this->product, ['zoneId' => $zone->id])->compareAmount)->toBeNull()
        ->and(priceOf($this->product)->compareAmount)->toBe(10000);
});

it('records the prices of a variant whatever scopes its siblings recorded', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    $parent = Product::factory()->variant()->create();
    $sibling = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 10000);
    $variant = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 10000);
    PriceHistory::factory()->for($sibling, 'priceable')->create([
        'currency_id' => currency()->id,
        'zone_id' => $zone->id,
        'channel_id' => $channel->id,
        'amount' => 10000,
    ]);

    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $variant, 8000);

    expect(PriceHistory::query()->whereMorphedTo('priceable', $variant)->where('zone_id', $zone->id)->pluck('channel_id')->all())->toBe([null]);
});

it('strikes a zone sale from the prices of its 30 days, whatever sold before', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    later(1);
    $sale = PriceList::factory()->create();
    listPrice($sale, $this->product, 7000);
    $channelSale = PriceList::factory()->create();
    PriceListRule::factory()->for($channelSale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($channelSale, $this->product, 6000);
    later(9);
    $sale->update(['status' => PriceListStatus::Archived]);
    $channelSale->update(['status' => PriceListStatus::Archived]);
    later(50);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 8000);

    expect(priceOf($this->product, ['zoneId' => $zone->id])->compareAmount)->toBe(10000)
        ->and(priceOf($this->product, ['zoneId' => $zone->id, 'channelId' => $channel->id])->compareAmount)->toBe(10000);
});

it('counts the zone prices of a shopper before a sale for their zone and channel', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    later(40);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 6000);
    later(10);
    $zoneSale->update(['status' => PriceListStatus::Archived]);
    later(10);
    $both = PriceList::factory()->create();
    PriceListRule::factory()->for($both)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    PriceListRule::factory()->for($both)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($both, $this->product, 8000);

    expect(priceOf($this->product, ['zoneId' => $zone->id, 'channelId' => $channel->id])->compareAmount)->toBeNull();
});

it('strikes no price it can no longer prove for a shopper of a zone and a channel', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    later(40);
    $channelSale = PriceList::factory()->create();
    PriceListRule::factory()->for($channelSale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($channelSale, $this->product, 6000);
    later(10);
    $channelSale->update(['status' => PriceListStatus::Archived]);
    later(10);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 8000);
    later(25);
    $this->product->prices()->sole()->update(['amount' => 10500]);

    expect(priceOf($this->product, ['zoneId' => $zone->id])->compareAmount)->toBe(10000)
        ->and(priceOf($this->product, ['zoneId' => $zone->id, 'channelId' => $channel->id])->compareAmount)->toBeNull();
});

it('caps the strike-through of a shopper of a zone and a channel at the regular channel price', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    $override = PriceList::factory()->override()->create();
    PriceListRule::factory()->for($override)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    $channelPrice = listPrice($override, $this->product, 12000);
    later(40);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 8000);

    $channelPrice->update(['amount' => 9000]);

    expect(priceOf($this->product, ['zoneId' => $zone->id])->compareAmount)->toBe(10000)
        ->and(priceOf($this->product, ['zoneId' => $zone->id, 'channelId' => $channel->id]))
        ->amount->toBe(8000)
        ->compareAmount->toBe(9000);
});

it('strikes no price for a shopper of a zone and a channel while a zone price of the window is unproven', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    $product = Model::withoutEvents(fn (): Product => withCatalogPrice(Product::factory()->create(['created_at' => now()->subDays(60)]), 10000));
    $this->artisan('shopper:pricelist:rebuild-index')->assertSuccessful();
    later(1);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $product, 6000);
    later(24);
    $zoneSale->update(['status' => PriceListStatus::Archived]);
    later(10);
    $channelSale = PriceList::factory()->create();
    PriceListRule::factory()->for($channelSale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($channelSale, $product, 9000);

    expect(priceOf($product, ['channelId' => $channel->id])->compareAmount)->toBe(10000)
        ->and(priceOf($product, ['zoneId' => $zone->id, 'channelId' => $channel->id]))
        ->amount->toBe(9000)
        ->compareAmount->toBeNull();
});

it('strikes a channel sale for a shopper of a zone from a zone price unproven before the window', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    $product = Model::withoutEvents(fn (): Product => withCatalogPrice(Product::factory()->create(['created_at' => now()->subDays(60)]), 10000));
    $this->artisan('shopper:pricelist:rebuild-index')->assertSuccessful();
    later(1);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $product, 6000);
    later(39);
    $channelSale = PriceList::factory()->create();
    PriceListRule::factory()->for($channelSale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($channelSale, $product, 5000);

    expect(priceOf($product, ['zoneId' => $zone->id, 'channelId' => $channel->id]))
        ->amount->toBe(5000)
        ->compareAmount->toBe(6000);
});

it('strikes a channel sale for a shopper of a zone from the zone price an ended zone sale left unproven before the window', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    $product = Model::withoutEvents(fn (): Product => withCatalogPrice(Product::factory()->create(['created_at' => now()->subDays(60)]), 10000));
    $this->artisan('shopper:pricelist:rebuild-index')->assertSuccessful();
    later(1);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $product, 6000);
    later(5);
    $zoneSale->update(['status' => PriceListStatus::Archived]);
    later(34);
    $channelSale = PriceList::factory()->create();
    PriceListRule::factory()->for($channelSale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($channelSale, $product, 5000);

    expect(priceOf($product, ['zoneId' => $zone->id, 'channelId' => $channel->id]))
        ->amount->toBe(5000)
        ->compareAmount->toBe(10000);
});

it('strikes a channel sale for a shopper of a zone from the zone price a new product was first sold at', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    $product = Model::withoutEvents(function () use ($zoneSale): Product {
        $product = withCatalogPrice(Product::factory()->create(['created_at' => now()]), 10000);
        listPrice($zoneSale, $product, 9000);

        return $product;
    });
    $this->artisan('shopper:pricelist:rebuild-index')->assertSuccessful();
    later(10);
    $channelSale = PriceList::factory()->create();
    PriceListRule::factory()->for($channelSale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($channelSale, $product, 8000);

    expect(priceOf($product, ['zoneId' => $zone->id, 'channelId' => $channel->id]))
        ->amount->toBe(8000)
        ->compareAmount->toBe(9000);
});

it('strikes a zone sale from the prices of its zone, not from a global sale its zone never had', function (): void {
    $zone = Zone::factory()->create();
    later(40);
    $zoneOverride = PriceList::factory()->override()->create(['priority' => 10]);
    PriceListRule::factory()->for($zoneOverride)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneOverride, $this->product, 10000);
    later(1);
    $sale = PriceList::factory()->create(['priority' => 0]);
    listPrice($sale, $this->product, 5000);
    later(10);
    $sale->update(['status' => PriceListStatus::Archived]);
    later(9);
    $zoneSale = PriceList::factory()->create(['priority' => 20]);
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 8000);

    expect(priceOf($this->product)->compareAmount)->toBeNull()
        ->and(priceOf($this->product, ['zoneId' => $zone->id]))
        ->amount->toBe(8000)
        ->compareAmount->toBe(10000);
});

it('strikes a zone sale from the zone price in effect through the window, not from a global price it replaced before', function (): void {
    $zone = Zone::factory()->create();
    later(1);
    $zoneOverride = PriceList::factory()->override()->create(['priority' => 10]);
    PriceListRule::factory()->for($zoneOverride)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneOverride, $this->product, 12000);
    later(59);
    $zoneSale = PriceList::factory()->create(['priority' => 20]);
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 11000);

    expect(priceOf($this->product, ['zoneId' => $zone->id]))
        ->amount->toBe(11000)
        ->compareAmount->toBe(12000);
});

it('strikes no price above the channel sale a shopper of a zone and a channel paid, though no list targets both', function (): void {
    $zone = Zone::factory()->create();
    $channel = Channel::factory()->create();
    later(1);
    $zoneOverride = PriceList::factory()->override()->create(['priority' => 10]);
    PriceListRule::factory()->for($zoneOverride)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneOverride, $this->product, 12000);
    later(1);
    $channelSale = PriceList::factory()->create(['priority' => 20]);
    PriceListRule::factory()->for($channelSale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($channelSale, $this->product, 10500);
    later(59);
    $zoneSale = PriceList::factory()->create(['priority' => 30]);
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 11000);

    expect(priceOf($this->product, ['zoneId' => $zone->id, 'channelId' => $channel->id]))
        ->amount->toBe(11000)
        ->compareAmount->toBeNull();
});

it('strikes a zone sale that also checks a rule of the application', function (): void {
    app(ApplicabilityRuleManager::class)->register(UnavailableRule::class);
    $zone = Zone::factory()->create();
    later(40);
    $sale = PriceList::factory()->create();
    PriceListRule::factory()->for($sale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    PriceListRule::factory()->for($sale)->create(['rule_key' => UnavailableRule::KEY, 'values' => [1]]);
    listPrice($sale, $this->product, 8000);

    expect(priceOf($this->product, ['zoneId' => $zone->id]))
        ->amount->toBe(8000)
        ->compareAmount->toBe(10000);
});

it('strikes each currency from its own history', function (): void {
    withCatalogPrice($this->product, 9000, 'EUR');
    later(40);
    $sale = PriceList::factory()->create();
    listPrice($sale, $this->product, 7000);
    listPrice($sale, $this->product, 6000, currency: 'EUR');
    later(0);

    expect(priceOf($this->product)->compareAmount)->toBe(10000)
        ->and(priceOf($this->product, ['currencyCode' => 'EUR'])->compareAmount)->toBe(9000);
});

it('stops recording the prices of a deleted zone or channel', function (string $key, Closure $scope): void {
    $model = $scope();
    $sale = PriceList::factory()->create();
    PriceListRule::factory()->for($sale)->create(['rule_key' => $key, 'values' => [$model->id]]);
    $price = listPrice($sale, $this->product, 8000);
    $model->delete();

    $price->update(['amount' => 7000]);
    $sale->update(['status' => PriceListStatus::Archived]);

    expect(PriceHistory::query()->whereNotNull($key.'_id')->pluck('amount')->all())->toBe([8000]);
})->with([
    'zone' => ['zone', fn () => Zone::factory()->create()],
    'channel' => ['channel', fn () => Channel::factory()->create()],
]);

it('records the prices of a zone only for the products it changes', function (): void {
    $other = withCatalogPrice(Product::factory()->create(), 5000);
    $zone = Zone::factory()->create();
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 8000);

    SyncProductPrices::dispatch([$this->product->id, $other->id]);

    expect(PriceHistory::query()->where('zone_id', $zone->id)->pluck('priceable_id')->all())->toBe([$this->product->id]);
});

it('records the price going back up when a sale is deleted', function (): void {
    later(1);
    $sale = PriceList::factory()->create();
    listPrice($sale, $this->product, 7000);
    later(1);

    $sale->delete();

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000, 10000]);
});

it('records the prices a scheduled sale sets when it starts and ends', function (): void {
    listPrice(PriceList::factory()->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]), $this->product, 7000);

    expect(PriceHistory::query()->pluck('amount')->all())->toBe([10000]);

    $this->travel(2)->hours();
    app()->forgetScopedInstances();
    $this->artisan('shopper:pricelist:record-prices')->assertSuccessful();

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000]);

    $this->travel(2)->hours();
    app()->forgetScopedInstances();
    $this->artisan('shopper:pricelist:record-prices')->assertSuccessful();

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000, 10000]);
});

it('records the prices a scheduled percentage sale sets on every product when it starts', function (): void {
    PriceList::factory()->decrease(3000)->create(['starts_at' => now()->addHour()]);
    $this->travel(2)->hours();
    app()->forgetScopedInstances();

    $this->artisan('shopper:pricelist:record-prices')->assertSuccessful();

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000]);
});

it('records the prices of the moment a sync runs in a request that already priced the product', function (): void {
    PriceList::factory()->create();
    priceOf($this->product);
    $sale = Model::withoutEvents(fn (): PriceList => listPrice(PriceList::factory()->create(), $this->product, 7000)->priceList);
    $this->travel(2)->hours();

    SyncProductPrices::dispatchSync([$this->product->id]);

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000])
        ->and(DB::table(shopper_table('price_list_product_prices'))->whereNotNull('price_list_id')->pluck('price_list_id')->all())->toBe([$sale->id]);
});

it('reads the last run in the timezone of the application', function (): void {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        listPrice(PriceList::factory()->create(['starts_at' => now()->addHour()]), $this->product, 7000);
        $this->artisan('shopper:pricelist:record-prices')->assertSuccessful();
        $this->travel(2)->hours();
        app()->forgetScopedInstances();
        $this->artisan('shopper:pricelist:record-prices')->assertSuccessful();

        expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000]);
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('prunes the rows no price needs any more once the application asks for it', function (): void {
    $zone = Zone::factory()->create();
    later(1);
    $sale = PriceList::factory()->create();
    listPrice($sale, $this->product, 7000);
    $zoneSale = PriceList::factory()->create();
    PriceListRule::factory()->for($zoneSale)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
    listPrice($zoneSale, $this->product, 8000);
    later(1);
    $sale->update(['status' => PriceListStatus::Archived]);
    $zoneSale->update(['status' => PriceListStatus::Archived]);
    later(38);
    $this->product->prices()->sole()->update(['amount' => 9500]);
    later(2);

    $this->artisan('model:prune', ['--model' => [PriceHistory::class]])->assertSuccessful();

    expect(PriceHistory::query()->orderBy('id')->get()->map(fn (PriceHistory $row): array => [$row->zone_id, $row->amount])->all())
        ->toBe([[null, 10000], [$zone->id, 10000], [null, 9500], [$zone->id, 9500]]);
});

it('keeps the price in effect of every priceable, currency and channel when pruning', function (): void {
    withCatalogPrice(ProductVariant::factory()->for(Product::factory()->variant())->create(['id' => $this->product->id]), 6000);
    withCatalogPrice(Product::factory()->create(), 5000);
    withCatalogPrice($this->product, 9000, 'EUR');
    $channel = Channel::factory()->create();
    $sale = PriceList::factory()->create();
    PriceListRule::factory()->for($sale)->create(['rule_key' => 'channel', 'values' => [$channel->id]]);
    listPrice($sale, $this->product, 8000);
    $recorded = PriceHistory::query()->orderBy('id')->pluck('id')->all();
    later(60);

    $this->artisan('model:prune', ['--model' => [PriceHistory::class]])->assertSuccessful();

    expect(PriceHistory::query()->orderBy('id')->pluck('id')->all())->toBe($recorded);
});

it('keeps the rows the 31 day window still reads when pruning', function (): void {
    $this->travel(22)->hours();
    $sale = PriceList::factory()->create();
    listPrice($sale, $this->product, 7000);
    $this->travel(9)->hours();
    $sale->update(['status' => PriceListStatus::Archived]);
    $this->travel(31 * 24 - 19)->hours();

    $this->artisan('model:prune', ['--model' => [PriceHistory::class]])->assertSuccessful();

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 7000, 10000]);
});

it('prunes chunk after chunk the rows recorded out of order', function (): void {
    Event::fake([ModelsPruned::class]);
    $rows = collect([32, 40, 36, 50])->map(fn (int $days): PriceHistory => PriceHistory::factory()->create([
        'priceable_type' => $this->product->getMorphClass(),
        'priceable_id' => $this->product->id,
        'currency_id' => currency()->id,
        'recorded_at' => now()->subDays($days),
    ]));

    expect((new PriceHistory)->pruneAll(1))->toBe(3)
        ->and(PriceHistory::query()->whereKey($rows->pluck('id'))->pluck('id')->all())->toBe([$rows->last()->id]);

    Event::assertDispatchedTimes(ModelsPruned::class, 3);
});

it('schedules the recording every minute without overlap', function (): void {
    /** @var ScheduledEvent $event */
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'shopper:pricelist:record-prices'));

    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10)
        ->and($event->onOneServer)->toBeTrue();
});

it('strikes no price above a sale that ended before any sync recorded it', function (Closure $sale): void {
    $variant = withCatalogPrice(ProductVariant::factory()->for(Product::factory()->variant())->create(), 10000);
    later(40);
    $sale($variant);
    $this->travel(2)->hours();
    app()->forgetScopedInstances();
    $this->artisan('shopper:pricelist:record-prices')->assertSuccessful();
    later(5);
    listPrice(PriceList::factory()->create(), $variant, 8000);

    expect(priceOf($variant))->amount->toBe(8000)->compareAmount->toBeNull();
})->with([
    'its own price' => fn (ProductVariant $variant) => listPrice(PriceList::factory()->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addMinutes(90)]), $variant, 6000),
    'the price of its product' => fn (ProductVariant $variant) => listPrice(PriceList::factory()->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addMinutes(90)]), $variant->product, 6000),
    'a percentage off' => fn () => PriceList::factory()->decrease(4000)->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addMinutes(90)]),
    'a price archived once it started' => function (ProductVariant $variant): void {
        $sale = PriceList::factory()->create(['starts_at' => now()->addHour()]);
        listPrice($sale, $variant, 6000);
        test()->travel(90)->minutes();
        $sale->update(['status' => PriceListStatus::Archived]);
    },
]);

it('keeps striking prices when every public sale of the window was recorded', function (): void {
    later(40);
    $vip = PriceList::factory()->create(['ends_at' => now()->addDays(2)]);
    PriceListRule::factory()->for($vip)->create(['rule_key' => 'customer', 'values' => [User::factory()->create()->id]]);
    listPrice($vip, $this->product, 5000);
    $restricted = PriceList::factory()->create(['ends_at' => now()->addDay()]);
    PriceListRule::factory()->for($restricted)->create(['rule_key' => 'zone', 'values' => [Zone::factory()->create()->id]]);
    PriceListRule::factory()->for($restricted)->create(['rule_key' => 'customer', 'values' => [User::factory()->create()->id]]);
    listPrice($restricted, $this->product, 5000);
    listPrice(PriceList::factory()->archived()->create(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(4)]), $this->product, 5000);
    listPrice(PriceList::factory()->archived()->create(['ends_at' => now()->subDays(35)]), $this->product, 5000);
    listPrice(PriceList::factory()->create(['ends_at' => now()->addDay()]), $this->product, 9000);
    later(5);
    listPrice(PriceList::factory()->create(), $this->product, 8000);

    expect(priceOf($this->product))->compareAmount->toBe(9000);
});

it('strikes no price above an ended sale of a zone or a channel no sync recorded', function (string $key, Closure $scope): void {
    $model = $scope();
    later(40);
    $sale = PriceList::factory()->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addMinutes(90)]);
    PriceListRule::factory()->for($sale)->create(['rule_key' => $key, 'values' => [$model->id]]);
    listPrice($sale, $this->product, 6000);
    later(5);
    $next = PriceList::factory()->create();
    PriceListRule::factory()->for($next)->create(['rule_key' => $key, 'values' => [$model->id]]);
    listPrice($next, $this->product, 8000);

    expect(priceOf($this->product, [$key.'Id' => $model->id]))->amount->toBe(8000)->compareAmount->toBeNull();
})->with([
    'zone' => ['zone', fn () => Zone::factory()->create()],
    'channel' => ['channel', fn () => Channel::factory()->create()],
]);

it('strikes no price above a reused sale whose new run no sync recorded', function (): void {
    later(40);
    $sale = PriceList::factory()->create(['ends_at' => now()->addDays(5)]);
    listPrice($sale, $this->product, 6000);
    later(5);
    $this->travel(1)->hour();
    $this->artisan('shopper:pricelist:record-prices')->assertSuccessful();
    later(30);
    $sale->update(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6)]);
    later(11);
    listPrice(PriceList::factory()->create(), $this->product, 8000);

    expect(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 6000, 10000, 8000])
        ->and(priceOf($this->product))->amount->toBe(8000)->compareAmount->toBeNull();
});

it('keeps striking the price of a currency an unrecorded ended sale never priced', function (): void {
    withCatalogPrice($this->product, 9000, 'EUR');
    later(40);
    listPrice(PriceList::factory()->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addMinutes(90)]), $this->product, 6000, currency: 'EUR');
    later(5);
    listPrice(PriceList::factory()->create(), $this->product, 8000);

    expect(priceOf($this->product)->compareAmount)->toBe(10000);
});
