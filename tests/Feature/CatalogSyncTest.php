<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Models\Channel;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\Zone;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\ResolvedPrice;
use Shopper\PriceList\Enum\AdjustmentType;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Events\PriceListDeleted;
use Shopper\PriceList\Events\PriceListSaved;
use Shopper\PriceList\Jobs\SyncPriceList;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListQuantityRule;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    $this->parent = Product::factory()->variant()->create();
    $this->variant = withCatalogPrice(ProductVariant::factory()->for($this->parent)->create(), 4000);
    $this->sibling = withCatalogPrice(ProductVariant::factory()->for($this->parent)->create(), 5000);
    $this->list = PriceList::factory()->create();
    listPrice($this->list, $this->variant, 3000);
    PriceListQuantityRule::factory()->for($this->list)->for($this->variant, 'priceable')->create(['minimum' => 2]);
});

function unpriced(): PriceResolver
{
    return new class implements PriceResolver
    {
        public function resolve(Priceable&Model $priceable, PricingContext $context): ?ResolvedPrice
        {
            return null;
        }
    };
}

it('forgets the price list data of a deleted variant and reindexes its product', function (): void {
    expect(DB::table(shopper_table('price_list_product_prices'))->where('product_id', $this->parent->id)->count())->toBe(3);

    $this->variant->delete();

    expect(PriceListPrice::query()->count())->toBe(0)
        ->and(PriceListQuantityRule::query()->count())->toBe(0)
        ->and(PriceHistory::query()->where('priceable_id', $this->variant->id)->where('priceable_type', 'variant')->count())->toBe(0)
        ->and(DB::table(shopper_table('price_list_product_prices'))->where('product_id', $this->parent->id)->count())->toBe(0);
});

it('forgets the price list data of a product and of its variants only once it is force deleted', function (): void {
    listPrice($this->list, $this->parent, 3500);

    $this->parent->delete();

    expect(PriceListPrice::query()->count())->toBe(2);

    $this->parent->forceDelete();

    expect(PriceListPrice::query()->count())->toBe(0)
        ->and(PriceListQuantityRule::query()->count())->toBe(0)
        ->and(PriceHistory::query()->count())->toBe(0)
        ->and(DB::table(shopper_table('price_list_product_prices'))->count())->toBe(0);
});

it('replaces every index row of a product, however many it had', function (): void {
    DB::table(shopper_table('price_list_product_prices'))->insert(array_map(fn (int $itemId): array => [
        'price_list_id' => $this->list->id,
        'product_id' => $this->parent->id,
        'item_id' => $itemId,
        'currency_id' => currency('USD')->id,
        'amount' => 1,
    ], range(100_000, 101_000)));

    SyncProductPrices::dispatchSync([$this->parent->id]);

    expect(DB::table(shopper_table('price_list_product_prices'))->where('product_id', $this->parent->id)->count())->toBe(3);
});

it('syncs a product once however many of its catalog prices change', function (): void {
    Queue::fake([SyncProductPrices::class]);

    Price::query()->where('priceable_type', 'variant')->get()->each->update(['amount' => 4500]);

    Queue::assertPushed(SyncProductPrices::class, 1);
});

it('leaves a product alone when its prices are saved without a pricing change', function (): void {
    Queue::fake([SyncProductPrices::class]);

    Price::query()->get()->each->save();
    PriceListPrice::query()->get()->each->save();
    Price::query()->first()->update(['cost_amount' => 123]);

    Queue::assertNotPushed(SyncProductPrices::class);
});

it('waits for a product another sync holds instead of giving up on it', function (): void {
    config(['queue.default' => 'database']);
    $job = new SyncProductPrices([$this->parent->id]);
    $lock = Cache::lock((new WithoutOverlapping((string) $this->parent->id))->getLockKey($job), 300);
    $lock->get();
    PriceHistory::query()->delete();
    dispatch($job);

    foreach (range(1, 6) as $attempt) {
        $this->travel(11)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    expect(PriceHistory::query()->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $lock->release();
    $this->travel(11)->seconds();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect(PriceHistory::query()->orderBy('amount')->pluck('amount')->all())->toBe([3000, 5000]);
});

it('runs a sync that waited more than an hour in the queue', function (): void {
    config(['queue.default' => 'database']);
    PriceHistory::query()->delete();
    dispatch(new SyncProductPrices([$this->parent->id]));
    $this->travel(2)->hours();

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect(PriceHistory::query()->orderBy('amount')->pluck('amount')->all())->toBe([3000, 5000])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('waits for a product another sync holds when jobs run in the request', function (): void {
    $job = new SyncProductPrices([$this->parent->id]);
    $lock = Cache::lock((new WithoutOverlapping((string) $this->parent->id))->getLockKey($job), 300);
    $lock->get();
    PriceHistory::query()->delete();
    Sleep::fake();
    Sleep::whenFakingSleep(fn () => $lock->release());

    dispatch($job);

    Sleep::assertSleptTimes(1);
    expect(PriceHistory::query()->orderBy('amount')->pluck('amount')->all())->toBe([3000, 5000]);
});

it('takes the locks of a sync in product order, whatever order it was given', function (): void {
    $next = Product::factory()->create();
    $job = new SyncProductPrices([$next->id, $this->parent->id]);
    $held = Cache::lock((new WithoutOverlapping((string) $next->id))->getLockKey($job), 300);
    $held->get();
    $parentFree = null;
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($held, $job, &$parentFree): void {
        $parentFree = Cache::lock((new WithoutOverlapping((string) $this->parent->id))->getLockKey($job), 300)->get(fn (): bool => true);
        $held->release();
    });

    dispatch($job);

    expect($parentFree)->toBeFalse();
});

it('splits a sync whose prices would take a worker too long to record', function (): void {
    $next = withCatalogPrice(Product::factory()->create(), 6000);
    $recorded = PriceHistory::query()->count();
    Queue::fake();
    PriceListRule::factory()->for($this->list)->create(['rule_key' => 'zone', 'values' => Zone::factory()->count(100)->create()->modelKeys()]);
    PriceListRule::factory()->for($this->list)->create(['rule_key' => 'channel', 'values' => Channel::factory()->count(100)->create()->modelKeys()]);

    app()->call([(new SyncProductPrices([$this->parent->id, $next->id]))->withFakeQueueInteractions(), 'handle']);

    expect(Queue::pushed(SyncProductPrices::class)->pluck('productIds')->all())->toBe([[$this->parent->id], [$next->id]])
        ->and(PriceHistory::query()->count())->toBe($recorded);
});

it('splits a sync whose recorded scopes would take a worker too long to revisit in every currency', function (): void {
    $next = withCatalogPrice(withCatalogPrice(Product::factory()->create(), 6000), 5500, 'EUR');
    $scopes = Arr::crossJoin(Zone::factory()->count(110)->create()->modelKeys(), Channel::factory()->count(100)->create()->modelKeys());

    foreach (array_chunk($scopes, 500) as $chunk) {
        DB::table(shopper_table('price_list_price_history'))->insert(array_map(fn (array $scope): array => [
            'priceable_type' => $this->variant->getMorphClass(),
            'priceable_id' => $this->variant->id,
            'currency_id' => currency('USD')->id,
            'zone_id' => $scope[0],
            'channel_id' => $scope[1],
            'amount' => 4000,
            'recorded_at' => now()->subDay(),
        ], $chunk));
    }

    $recorded = PriceHistory::query()->count();
    Queue::fake();

    app()->call([(new SyncProductPrices([$this->parent->id, $next->id]))->withFakeQueueInteractions(), 'handle']);

    expect(Queue::pushed(SyncProductPrices::class)->pluck('productIds')->all())->toBe([[$this->parent->id], [$next->id]])
        ->and(PriceHistory::query()->count())->toBe($recorded);
});

it('revisits only the scopes each product recorded', function (): void {
    $next = withCatalogPrice(Product::factory()->create(), 6000);
    $scopes = Arr::crossJoin(Zone::factory()->count(90)->create()->modelKeys(), Channel::factory()->count(90)->create()->modelKeys());

    foreach (array_chunk($scopes, 500) as $chunk) {
        DB::table(shopper_table('price_list_price_history'))->insert(array_map(fn (array $scope): array => [
            'priceable_type' => $this->variant->getMorphClass(),
            'priceable_id' => $this->variant->id,
            'currency_id' => currency('USD')->id,
            'zone_id' => $scope[0],
            'channel_id' => $scope[1],
            'amount' => 4000,
            'recorded_at' => now()->subDay(),
        ], $chunk));
    }

    Queue::fake();
    app()->instance(PriceResolver::class, unpriced());

    app()->call([(new SyncProductPrices([$this->parent->id, $next->id]))->withFakeQueueInteractions(), 'handle']);

    Queue::assertNotPushed(SyncProductPrices::class);
});

it('records a product alone whatever its prices cost a worker', function (): void {
    Queue::fake();
    PriceListRule::factory()->for($this->list)->create(['rule_key' => 'zone', 'values' => Zone::factory()->count(100)->create()->modelKeys()]);
    PriceListRule::factory()->for($this->list)->create(['rule_key' => 'channel', 'values' => Channel::factory()->count(100)->create()->modelKeys()]);
    app()->instance(PriceResolver::class, unpriced());

    app()->call([(new SyncProductPrices([$this->parent->id]))->withFakeQueueInteractions(), 'handle']);

    Queue::assertNotPushed(SyncProductPrices::class);
});

it('splits a sync run in the request into syncs run in the request once it frees its products', function (): void {
    $next = withCatalogPrice(Product::factory()->create(), 6000);
    PriceListRule::factory()->for($this->list)->createQuietly(['rule_key' => 'zone', 'values' => Zone::factory()->count(100)->create()->modelKeys()]);
    PriceListRule::factory()->for($this->list)->createQuietly(['rule_key' => 'channel', 'values' => Channel::factory()->count(100)->create()->modelKeys()]);
    app()->instance(PriceResolver::class, unpriced());
    config(['queue.default' => 'database']);
    $runs = 0;
    Event::listen(JobProcessed::class, function () use (&$runs): void {
        $runs++;
    });

    SyncProductPrices::dispatchSync([$this->parent->id, $next->id]);

    expect($runs)->toBe(3);
    Sleep::assertNeverSlept();
});

it('frees the products of a sync that failed in the request', function (): void {
    $prices = resolve(PriceResolver::class);
    app()->instance(PriceResolver::class, new class implements PriceResolver
    {
        public function resolve(Priceable&Model $priceable, PricingContext $context): never
        {
            throw new RuntimeException('The catalog prices are unavailable.');
        }
    });

    expect(fn () => SyncProductPrices::dispatchSync([$this->parent->id]))->toThrow(RuntimeException::class);

    app()->instance(PriceResolver::class, $prices);
    SyncProductPrices::dispatchSync([$this->parent->id]);

    Sleep::assertNeverSlept();
});

it('never mixes the price list data of a product and of a variant sharing its id', function (): void {
    $variant = withCatalogPrice(ProductVariant::factory()->for($this->parent)->create(['id' => 9001]), 4000);
    listPrice($this->list, $variant, 2500);
    $product = withCatalogPrice(Product::factory()->create(['id' => 9001]), 6000);

    expect(priceOf($variant)->amount)->toBe(2500)
        ->and(priceOf($product)->amount)->toBe(6000);

    $product->forceDelete();

    expect(PriceListPrice::query()->whereMorphedTo('priceable', $variant)->exists())->toBeTrue();
});

it('reindexes a price list only when its pricing changes', function (string $attribute, mixed $value): void {
    Queue::fake([SyncPriceList::class]);

    $this->list->update(['name' => 'Summer']);

    Queue::assertNotPushed(SyncPriceList::class);

    $this->list->update([$attribute => $value]);

    Queue::assertPushed(SyncPriceList::class, 1);
})->with([
    'its type' => ['type', PriceListType::Override],
    'its status' => ['status', PriceListStatus::Draft],
    'its start' => ['starts_at', '2031-01-01 00:00:00'],
    'its end' => ['ends_at', '2031-01-01 00:00:00'],
    'its priority' => ['priority', 3],
    'its adjustment' => ['adjustment_type', AdjustmentType::Decrease],
    'its adjustment value' => ['adjustment_value', 500],
    'its rounding' => ['rounding_endings', ['USD' => 99]],
]);

it('reindexes each price list changed in the same request', function (): void {
    Queue::fake([SyncPriceList::class]);

    PriceList::factory()->count(2)->create();

    Queue::assertPushed(SyncPriceList::class, 2);
});

it('reindexes only once the transaction that changed the prices commits', function (Closure $change): void {
    $recorded = PriceHistory::query()->count();

    DB::transaction(function () use ($change, $recorded): void {
        $change();

        expect(PriceHistory::query()->count())->toBe($recorded);
    });

    expect(PriceHistory::query()->count())->toBeGreaterThan($recorded);
})->with([
    'a new price list' => fn () => PriceList::factory()->decrease(1000)->create(),
    'a price list' => fn () => PriceList::query()->sole()->update(['status' => PriceListStatus::Draft]),
    'a deleted price list' => fn () => PriceList::query()->sole()->delete(),
    'a price list price' => fn () => PriceListPrice::query()->sole()->update(['amount' => 2000]),
    'a catalog price' => fn () => Price::query()->where('amount', 5000)->sole()->update(['amount' => 4500]),
]);

it('tells which administrator changed a price list and what changed', function (): void {
    Event::fake([PriceListSaved::class]);
    config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'], 'shopper.auth.guard' => 'admin']);
    $this->actingAs(User::factory()->create());
    auth()->guard('admin')->setUser($admin = User::factory()->create());

    $this->list->update(['priority' => 7]);

    Event::assertDispatched(PriceListSaved::class, fn (PriceListSaved $event): bool => $event->actorId === $admin->id
        && $event->before === ['priority' => 0]
        && $event->after === ['priority' => 7]);
});

it('tells who deleted a price list', function (): void {
    Event::fake([PriceListDeleted::class]);
    $this->actingAs($admin = User::factory()->create());

    $this->list->delete();

    Event::assertDispatched(PriceListDeleted::class, fn (PriceListDeleted $event): bool => $event->actorId === $admin->id
        && $event->priceList->is($this->list));
});

it('resyncs the product a price list price moves away from', function (): void {
    $product = withCatalogPrice(Product::factory()->create(), 6000);

    PriceListPrice::query()->sole()->update(['priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id]);

    expect(DB::table(shopper_table('price_list_product_prices'))->where('price_list_id', $this->list->id)->pluck('product_id')->all())->toBe([$product->id])
        ->and(PriceHistory::query()->where('priceable_type', 'variant')->where('priceable_id', $this->variant->id)->latest('id')->value('amount'))->toBe(4000);
});

it('resyncs the product a catalog price moves away from', function (): void {
    $product = Product::factory()->create();

    Price::query()->where('amount', 5000)->sole()->update(['priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id]);

    expect(DB::table(shopper_table('price_list_product_prices'))->where('product_id', $this->parent->id)->whereNull('price_list_id')->orderBy('item_id')->pluck('amount')->all())
        ->toBe([4000, null]);
});

it('tells of a price list change only once its transaction commits', function (): void {
    $events = [];
    Event::listen([PriceListSaved::class, PriceListDeleted::class], function (object $event) use (&$events): void {
        $events[] = $event::class;
    });

    rescue(fn () => DB::transaction(function (): void {
        $this->list->update(['priority' => 7]);
        $this->list->delete();

        throw new RuntimeException;
    }), report: false);

    expect($events)->toBe([]);

    PriceList::query()->findOrFail($this->list->id)->update(['priority' => 7]);

    expect($events)->toBe([PriceListSaved::class]);
});
