<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Contracts\ProductPriceIndex;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Pricing\CatalogProductPriceIndex;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\ResolvedPrice;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Tests\Stubs\User;
use Shopper\ShopperPanel;

/**
 * @return array<int, int>
 */
function listingPrices(?PricingContext $context = null): array
{
    $context ??= new PricingContext('USD');
    $query = Product::query();
    $expression = resolve(ProductPriceIndex::class)->minPriceExpression($query, currency($context->currency())->id, $context);

    return $query->select('id')
        ->selectRaw("{$expression} as min_price")
        ->orderBy('min_price')
        ->orderBy('id')
        ->pluck('min_price', 'id')
        ->map(intval(...))
        ->all();
}

/**
 * @return array<int, array{0: int, 1: int}>
 */
function listingRanges(?PricingContext $context = null): array
{
    $context ??= new PricingContext('USD');

    return resolve(ProductPriceIndex::class)
        ->ranges(Product::query()->get(), currency($context->currency())->id, $context)
        ->map(fn (stdClass $row): array => [(int) $row->min_amount, (int) $row->max_amount])
        ->all();
}

beforeEach(function (): void {
    $this->lamp = withCatalogPrice(Product::factory()->create(), 3000);
    $this->chair = withCatalogPrice(Product::factory()->create(), 2000);
});

it('sorts and ranges by catalog price while no list applies', function (): void {
    $query = Product::query();

    expect(resolve(ProductPriceIndex::class)->minPriceExpression($query, currency()->id, new PricingContext('USD')))
        ->toBe(resolve(CatalogProductPriceIndex::class)->minPriceExpression($query, currency()->id, new PricingContext('USD')))
        ->and(listingPrices())->toBe([$this->chair->id => 2000, $this->lamp->id => 3000]);
});

it('sorts a group member by the group prices and a guest by the catalog', function (): void {
    $member = User::factory()->create();
    $list = PriceList::factory()->override()->create();
    listPrice($list, $this->lamp, 1500);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'customer', 'values' => [$member->id]]);

    expect(listingPrices(new PricingContext('USD', $member->id)))->toBe([$this->lamp->id => 1500, $this->chair->id => 2000])
        ->and(listingPrices())->toBe([$this->chair->id => 2000, $this->lamp->id => 3000])
        ->and(listingRanges(new PricingContext('USD', $member->id)))->toBe([$this->lamp->id => [1500, 1500], $this->chair->id => [2000, 2000]]);
});

it('sorts and ranges each currency by its own list prices', function (): void {
    withCatalogPrice($this->lamp, 2800, 'EUR');
    $list = PriceList::factory()->create();
    listPrice($list, $this->lamp, 2500);
    listPrice($list, $this->lamp, 1500, currency: 'EUR');

    expect(listingPrices()[$this->lamp->id])->toBe(2500)
        ->and(listingRanges()[$this->lamp->id])->toBe([2500, 2500])
        ->and(listingPrices(new PricingContext('EUR'))[$this->lamp->id])->toBe(1500)
        ->and(listingRanges(new PricingContext('EUR'))[$this->lamp->id])->toBe([1500, 1500]);
});

it('lists a customer override above a cheaper public sale of the same priority, like the price sold', function (): void {
    $member = User::factory()->create();
    listPrice(PriceList::factory()->create(), $this->lamp, 1500);
    $contract = PriceList::factory()->override()->create();
    listPrice($contract, $this->lamp, 1900);
    PriceListRule::factory()->for($contract)->create(['rule_key' => 'customer', 'values' => [$member->id]]);

    expect(listingPrices(new PricingContext('USD', $member->id))[$this->lamp->id])->toBe(1900)
        ->and(priceOf($this->lamp, ['customerId' => $member->id])->amount)->toBe(1900);
});

it('sorts and ranges a product sold only under contract for its customers only', function (): void {
    $member = User::factory()->create();
    $exclusive = Product::factory()->create();
    $list = PriceList::factory()->override()->create();
    listPrice($list, $exclusive, 4200);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'customer', 'values' => [$member->id]]);

    expect(listingPrices(new PricingContext('USD', $member->id))[$exclusive->id])->toBe(4200)
        ->and(listingRanges(new PricingContext('USD', $member->id))[$exclusive->id])->toBe([4200, 4200])
        ->and(listingRanges())->not->toHaveKey($exclusive->id);
});

it('drops a list price from the listing once it is deleted', function (): void {
    $price = listPrice(PriceList::factory()->create(), $this->lamp, 1500);

    expect(listingPrices()[$this->lamp->id])->toBe(1500);

    $price->delete();

    expect(listingPrices()[$this->lamp->id])->toBe(3000);
});

it('never lists a sale that does not sell below the catalog', function (): void {
    $unpriced = Product::factory()->create();
    listPrice(PriceList::factory()->create(['priority' => 5]), $this->chair, 2000);
    listPrice(PriceList::factory()->create(['priority' => 1]), $this->chair, 1500);
    listPrice(PriceList::factory()->create(), $unpriced, 1000);

    expect(listingPrices()[$this->chair->id])->toBe(1500)
        ->and(priceOf($this->chair)->amount)->toBe(1500)
        ->and(listingRanges())->not->toHaveKey($unpriced->id);
});

it('follows the list of higher priority, like the price sold', function (): void {
    listPrice(PriceList::factory()->create(['priority' => 5]), $this->lamp, 2800);
    listPrice(PriceList::factory()->create(['priority' => 1]), $this->lamp, 1000);

    expect(listingPrices()[$this->lamp->id])->toBe(2800)
        ->and(priceOf($this->lamp)->amount)->toBe(2800);
});

it('lists an override a sale of higher priority does not undercut, like the price sold', function (): void {
    listPrice(PriceList::factory()->override()->create(), $this->lamp, 2400);
    listPrice(PriceList::factory()->create(['priority' => 10]), $this->lamp, 2700);

    expect(listingPrices()[$this->lamp->id])->toBe(2400)
        ->and(priceOf($this->lamp)->amount)->toBe(2400);
});

it('lists a sale under an override priced above the catalog, like the price sold', function (): void {
    listPrice(PriceList::factory()->override()->create(), $this->lamp, 3600);
    listPrice(PriceList::factory()->create(), $this->lamp, 3300);

    expect(listingPrices()[$this->lamp->id])->toBe(3300)
        ->and(priceOf($this->lamp)->amount)->toBe(3300);
});

it('lists the price sold among lists of the same type', function (PriceListType $type, array $offers, int $sold): void {
    foreach ($offers as [$priority, $amount]) {
        listPrice(PriceList::factory()->create(['type' => $type, 'priority' => $priority]), $this->lamp, $amount);
    }

    expect(listingPrices()[$this->lamp->id])->toBe($sold)
        ->and(priceOf($this->lamp)->amount)->toBe($sold);
})->with([
    'override of higher priority above a cheaper one' => [PriceListType::Override, [[5, 3200], [1, 2600]], 3200],
    'cheaper of two overrides of the same priority' => [PriceListType::Override, [[0, 3200], [0, 2600]], 2600],
    'cheaper of two sales of the same priority' => [PriceListType::Sale, [[0, 2800], [0, 2400]], 2400],
]);

it('lists at its live catalog price a product only the lists of others price, before it is reindexed', function (): void {
    $member = User::factory()->create();
    listPrice(PriceList::factory()->create(), $this->chair, 1500);
    $contract = PriceList::factory()->override()->create();
    listPrice($contract, $this->lamp, 2500);
    PriceListRule::factory()->for($contract)->create(['rule_key' => 'customer', 'values' => [$member->id]]);

    Queue::fake([SyncProductPrices::class]);
    Price::query()->where('priceable_id', $this->lamp->id)->first()->update(['amount' => 1000]);

    expect(listingPrices()[$this->lamp->id])->toBe(1000)
        ->and(listingRanges()[$this->lamp->id])->toBe([1000, 1000]);
});

it('ranges each variant by the list that wins it, like the price sold', function (): void {
    $parent = Product::factory()->variant()->create();
    $first = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 4000);
    $second = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 5000);
    listPrice(PriceList::factory()->create(), $first, 3500);
    listPrice(PriceList::factory()->create(['priority' => 5]), $second, 2000);

    expect(listingRanges()[$parent->id])->toBe([2000, 3500])
        ->and(listingPrices()[$parent->id])->toBe(2000)
        ->and([priceOf($first)->amount, priceOf($second)->amount])->toBe([3500, 2000]);
});

it('lists the variants of a product at the price a list sets on the parent product', function (): void {
    $parent = Product::factory()->variant()->create();
    $first = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 4000);
    $second = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 5000);
    listPrice(PriceList::factory()->create(), $parent, 3500);

    expect(listingRanges()[$parent->id])->toBe([3500, 3500])
        ->and(listingPrices()[$parent->id])->toBe(3500)
        ->and([priceOf($first)->amount, priceOf($second)->amount])->toBe([3500, 3500]);
});

it('lists a scheduled sale only inside its dates, whatever the rows indexed ahead', function (): void {
    listPrice(PriceList::factory()->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]), $this->lamp, 1500);

    expect(listingPrices()[$this->lamp->id])->toBe(3000);

    $this->travel(2)->hours();
    app()->forgetScopedInstances();

    expect(listingPrices()[$this->lamp->id])->toBe(1500)
        ->and(listingRanges()[$this->lamp->id])->toBe([1500, 1500]);

    $this->travel(2)->hours();
    app()->forgetScopedInstances();

    expect(listingPrices()[$this->lamp->id])->toBe(3000);
});

it('follows a change of priority before its products are reindexed, past a list that does not price them', function (): void {
    $first = PriceList::factory()->create(['priority' => 1]);
    listPrice($first, $this->lamp, 2500);
    $second = PriceList::factory()->create(['priority' => 2]);
    listPrice($second, $this->lamp, 2700);
    listPrice(PriceList::factory()->create(['priority' => 9]), $this->chair, 1900);

    expect(listingPrices()[$this->lamp->id])->toBe(2700)
        ->and(listingRanges()[$this->lamp->id])->toBe([2700, 2700]);

    Queue::fake([SyncProductPrices::class]);
    $first->update(['priority' => 3]);

    expect(listingPrices()[$this->lamp->id])->toBe(2500);
});

it('lists a product in a currency only a price list prices it in', function (): void {
    listPrice(PriceList::factory()->override()->create(), $this->lamp, 2500, currency: 'EUR');

    expect(listingPrices(new PricingContext('EUR'))[$this->lamp->id])->toBe(2500);
});

it('ranges a product whose variants the list only partly prices', function (): void {
    $parent = Product::factory()->variant()->create();
    $priced = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 4000);
    withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 5000);
    listPrice(PriceList::factory()->create(), $priced, 3500);

    expect(listingRanges()[$parent->id])->toBe([3500, 5000]);
});

it('follows a catalog price change of a product a percentage list prices', function (): void {
    PriceList::factory()->decrease(5000)->create();

    expect(listingPrices()[$this->chair->id])->toBe(1000);

    Price::query()->where('priceable_id', $this->chair->id)->first()->update(['amount' => 1000]);

    expect(listingPrices()[$this->chair->id])->toBe(500);
});

it('stops listing a product a percentage list prices once its catalog price is deleted', function (): void {
    PriceList::factory()->decrease(5000)->create();

    expect(listingRanges()[$this->chair->id])->toBe([1000, 1000]);

    $this->chair->prices()->sole()->delete();

    expect(listingRanges())->not->toHaveKey($this->chair->id);
});

it('lists the variants a draft list prices once it is activated', function (): void {
    $parent = Product::factory()->variant()->create();
    $variant = withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 4000);
    $list = PriceList::factory()->draft()->create();
    listPrice($list, $variant, 3500);

    expect(listingRanges()[$parent->id])->toBe([4000, 4000]);

    $list->update(['status' => PriceListStatus::Active]);

    expect(listingRanges()[$parent->id])->toBe([3500, 3500]);
});

it('lists at their catalog price the products a list stopped discounting', function (): void {
    $list = PriceList::factory()->decrease(1000)->create();
    listPrice($list, $this->chair, 1500);

    expect(listingPrices()[$this->lamp->id])->toBe(2700);

    $list->update(['adjustment_type' => null, 'adjustment_value' => null]);

    expect(listingPrices()[$this->lamp->id])->toBe(3000)
        ->and(listingPrices()[$this->chair->id])->toBe(1500);
});

it('computes a percentage from the catalog price the application resolves', function (): void {
    app()->singleton(PriceResolver::class, fn (): PriceResolver => new class implements PriceResolver
    {
        public function resolve(Priceable&Model $priceable, PricingContext $context): ResolvedPrice
        {
            return new ResolvedPrice(amount: 5000, currencyCode: $context->currency());
        }
    });

    PriceList::factory()->decrease(1000)->create();

    expect(listingPrices()[$this->lamp->id])->toBe(4500);
});

it('rebuilds the whole index and the price history from lists imported without events', function (): void {
    Model::withoutEvents(fn (): PriceListPrice => listPrice(PriceList::factory()->create(), $this->lamp, 1500));

    $this->artisan('shopper:pricelist:rebuild-index')->assertSuccessful();

    expect(listingPrices()[$this->lamp->id])->toBe(1500)
        ->and(PriceHistory::query()->where('priceable_id', $this->lamp->id)->orderBy('id')->pluck('amount')->all())->toBe([3000, 1500]);
});

it('refuses to rebuild the index over existing lists while the addon is not registered', function (): void {
    Model::withoutEvents(fn (): PriceListPrice => listPrice(PriceList::factory()->create(), $this->lamp, 1500));
    $this->app->instance('shopper', new ShopperPanel);

    $this->artisan('shopper:pricelist:rebuild-index')->assertFailed();

    expect(PriceHistory::query()->where('priceable_id', $this->lamp->id)->pluck('amount')->all())->toBe([3000]);
});
