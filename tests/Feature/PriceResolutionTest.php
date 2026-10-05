<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\Zone;
use Shopper\Core\Pricing\PricingContext;
use Shopper\CustomerGroups\Models\CustomerGroup;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Enum\AdjustmentType;
use Shopper\PriceList\Enum\CompareAtMode;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Pricing\ActivePriceLists;
use Shopper\PriceList\Pricing\ApplicableLists;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\PriceList\Tests\Stubs\UnavailableRule;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    $this->product = withCatalogPrice(Product::factory()->create(['created_at' => now()]), 2000);
});

it('keeps the catalog price when no price list applies', function (): void {
    expect(priceOf($this->product))
        ->amount->toBe(2000)
        ->originalAmount->toBeNull()
        ->meta->toBe([]);
});

it('sells at the sale price and tells which list set it', function (): void {
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);

    $price = priceOf($this->product);

    expect($price->amount)->toBe(1500)
        ->and($price->originalAmount)->toBe(2000)
        ->and($price->meta['public'])->toBe(['price_list' => ['id' => $list->public_id, 'type' => 'sale']])
        ->and($price->meta['price_list'])->toBe(['id' => $list->public_id, 'name' => $list->name, 'type' => 'sale'])
        ->and($price->meta['source'])->toBe('fixed')
        ->and($price->meta['level'])->toBe('product')
        ->and($price->meta['tier'])->toBe([1, null]);
});

it('falls through to the next list when the top sale is not a decrease', function (): void {
    listPrice(PriceList::factory()->create(['priority' => 10]), $this->product, 2500);
    listPrice(PriceList::factory()->create(['priority' => 1]), $this->product, 1800);

    expect(priceOf($this->product)->amount)->toBe(1800);
});

it('applies an override even above the catalog price', function (): void {
    listPrice(PriceList::factory()->override()->create(), $this->product, 2500);

    expect(priceOf($this->product))
        ->amount->toBe(2500)
        ->originalAmount->toBe(2000);
});

it('applies a list only while it is active and inside its dates', function (Closure $attributes, int $expected): void {
    listPrice(PriceList::factory()->create($attributes()), $this->product, 1500);

    expect(priceOf($this->product)->amount)->toBe($expected);
})->with([
    'draft' => [fn (): array => ['status' => PriceListStatus::Draft], 2000],
    'archived' => [fn (): array => ['status' => PriceListStatus::Archived], 2000],
    'starting now' => [fn (): array => ['starts_at' => now()], 1500],
    'starting in a second' => [fn (): array => ['starts_at' => now()->addSecond()], 2000],
    'ending now' => [fn (): array => ['ends_at' => now()], 2000],
    'ending in a second' => [fn (): array => ['ends_at' => now()->addSecond()], 1500],
]);

it('starts and ends a scheduled sale on the second, even for the lists read before its end', function (): void {
    listPrice(PriceList::factory()->create(['starts_at' => now()->addMinutes(10), 'ends_at' => now()->addMinutes(20)]), $this->product, 1500);

    expect(priceOf($this->product)->amount)->toBe(2000);

    $this->travel(10)->minutes();
    app()->forgetScopedInstances();

    expect(priceOf($this->product)->amount)->toBe(1500);

    app()->forgetScopedInstances();
    resolve(ActivePriceLists::class)->all();
    $this->travel(10)->minutes();

    expect(priceOf($this->product)->amount)->toBe(2000);
});

it('ignores a sale that does not lower the price', function (): void {
    listPrice(PriceList::factory()->create(), $this->product, 2000);

    expect(priceOf($this->product)->meta)->toBe([]);
});

it('picks the highest tier the quantity reaches', function (): void {
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1800);
    listPrice($list, $this->product, 1500, ['min_quantity' => 10]);
    listPrice($list, $this->product, 1200, ['min_quantity' => 50, 'max_quantity' => 99]);

    expect(priceOf($this->product, ['quantity' => 9])->amount)->toBe(1800)
        ->and(priceOf($this->product, ['quantity' => 10])->amount)->toBe(1500)
        ->and(priceOf($this->product, ['quantity' => 50])->amount)->toBe(1200)
        ->and(priceOf($this->product, ['quantity' => 100])->amount)->toBe(1500);
});

it('requires every rule of a list and any value of each rule', function (): void {
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'zone', 'values' => [3, 4]]);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'channel', 'values' => [7]]);

    expect(priceOf($this->product, ['zoneId' => 4, 'channelId' => 7])->amount)->toBe(1500)
        ->and(priceOf($this->product, ['zoneId' => 4, 'channelId' => 8])->amount)->toBe(2000)
        ->and(priceOf($this->product, ['zoneId' => 5, 'channelId' => 7])->amount)->toBe(2000);
});

it('targets customers and the members of active customer groups', function (): void {
    Exceptions::fake();
    $customer = User::factory()->create();
    $member = User::factory()->create();
    $group = CustomerGroup::factory()->create();
    $group->customers()->attach($member);

    $contract = PriceList::factory()->override()->create();
    listPrice($contract, $this->product, 1700);
    PriceListRule::factory()->for($contract)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);

    $wholesale = PriceList::factory()->override()->create();
    listPrice($wholesale, $this->product, 1600);
    PriceListRule::factory()->for($wholesale)->create(['rule_key' => 'customer_group', 'values' => [$group->id]]);

    expect(priceOf($this->product, ['customerId' => $customer->id])->amount)->toBe(1700)
        ->and(priceOf($this->product, ['customerId' => $member->id])->amount)->toBe(1600)
        ->and(priceOf($this->product)->amount)->toBe(2000);

    $group->update(['is_active' => false]);
    app()->forgetScopedInstances();

    expect(priceOf($this->product, ['customerId' => $member->id])->amount)->toBe(2000);

    Exceptions::assertNothingReported();
});

it('never applies a list whose rule is not registered', function (): void {
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'company', 'values' => [1]]);

    expect(priceOf($this->product)->amount)->toBe(2000);
});

it('treats a rule that throws as not applicable and reports it', function (): void {
    Exceptions::fake();

    app(ApplicabilityRuleManager::class)->register(new class implements ApplicabilityRule
    {
        public function key(): string
        {
            return 'broken';
        }

        public function label(): string
        {
            return 'Broken';
        }

        public function passes(array $values, PricingContext $context): bool
        {
            throw new RuntimeException('Rule failed.');
        }
    });

    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'broken', 'values' => [1]]);

    expect(priceOf($this->product)->amount)->toBe(2000);

    Exceptions::assertReported(RuntimeException::class);
});

it('treats a rule it cannot build as not applicable and reports it', function (): void {
    Exceptions::fake();
    app(ApplicabilityRuleManager::class)->register(UnavailableRule::class);
    app()->bind(UnavailableRule::class, fn () => throw new RuntimeException('Service unavailable.'));

    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    PriceListRule::factory()->for($list)->create(['rule_key' => UnavailableRule::KEY, 'values' => [1]]);

    expect(priceOf($this->product)->amount)->toBe(2000);

    Exceptions::assertReported(RuntimeException::class);
});

it('evaluates the rules again for another currency', function (): void {
    app(ApplicabilityRuleManager::class)->register(new class implements ApplicabilityRule
    {
        public function key(): string
        {
            return 'currency';
        }

        public function label(): string
        {
            return 'Currency';
        }

        public function passes(array $values, PricingContext $context): bool
        {
            return in_array($context->currency(), $values, true);
        }
    });

    withCatalogPrice($this->product, 1900, 'EUR');
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    listPrice($list, $this->product, 1500, currency: 'EUR');
    PriceListRule::factory()->for($list)->create(['rule_key' => 'currency', 'values' => ['EUR']]);

    expect(priceOf($this->product)->amount)->toBe(2000)
        ->and(priceOf($this->product, ['currencyCode' => 'EUR'])->amount)->toBe(1500);
});

it('compares rule values strictly', function (): void {
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'channel', 'values' => ['0']]);

    expect(priceOf($this->product)->amount)->toBe(2000)
        ->and(priceOf($this->product, ['channelId' => 0])->amount)->toBe(1500);
});

describe('a product with variants', function (): void {
    beforeEach(function (): void {
        $this->parent = Product::factory()->variant()->create();
        $this->variant = withCatalogPrice(ProductVariant::factory()->for($this->parent)->create(), 2000);
        $this->sibling = withCatalogPrice(ProductVariant::factory()->for($this->parent)->create(), 2000);
    });

    it('prices every variant from the product price of the list', function (): void {
        listPrice(PriceList::factory()->create(), $this->parent, 1500);

        expect(priceOf($this->variant))
            ->amount->toBe(1500)
            ->meta->toHaveKey('level', 'product');
    });

    it('lets the price of a variant win over the price of its product', function (): void {
        $list = PriceList::factory()->create();
        listPrice($list, $this->parent, 1500);
        listPrice($list, $this->variant, 1400);

        expect(priceOf($this->variant))
            ->amount->toBe(1400)
            ->meta->toHaveKey('level', 'variant')
            ->and(priceOf($this->sibling)->amount)->toBe(1500);
    });

    it('ignores the product tiers of a variant priced in the same list', function (): void {
        $list = PriceList::factory()->create();
        listPrice($list, $this->parent, 1000, ['min_quantity' => 10]);
        listPrice($list, $this->variant, 1800);

        expect(priceOf($this->variant, ['quantity' => 10])->amount)->toBe(1800)
            ->and(priceOf($this->sibling, ['quantity' => 10])->amount)->toBe(1000);
    });

    it('adjusts the variants the list does not price', function (): void {
        $list = PriceList::factory()->decrease(1000)->create();
        listPrice($list, $this->variant, 1500);

        expect(priceOf($this->variant))
            ->amount->toBe(1500)
            ->meta->toHaveKey('source', 'fixed')
            ->and(priceOf($this->sibling))
            ->amount->toBe(1800)
            ->meta->toHaveKey('source', 'adjustment');
    });
});

it('prefers the list of higher priority, even when it sells higher', function (): void {
    listPrice(PriceList::factory()->create(['priority' => 5]), $this->product, 1800);
    listPrice(PriceList::factory()->create(['priority' => 1]), $this->product, 1500);

    expect(priceOf($this->product)->amount)->toBe(1800);
});

it('prefers a customer override over a cheaper public sale of the same priority', function (): void {
    $customer = User::factory()->create();

    listPrice(PriceList::factory()->create(), $this->product, 1500);
    $contract = PriceList::factory()->override()->create();
    listPrice($contract, $this->product, 1900);
    PriceListRule::factory()->for($contract)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);

    expect(priceOf($this->product, ['customerId' => $customer->id])->amount)->toBe(1900)
        ->and(priceOf($this->product)->amount)->toBe(1500);
});

it('never lets a sale raise the price a customer already pays', function (): void {
    $customer = User::factory()->create();
    $contract = PriceList::factory()->override()->create();
    listPrice($contract, $this->product, 1400);
    PriceListRule::factory()->for($contract)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);
    listPrice(PriceList::factory()->create(['priority' => 10]), $this->product, 1600);

    expect(priceOf($this->product)->amount)->toBe(1600)
        ->and(priceOf($this->product, ['customerId' => $customer->id])->amount)->toBe(1400);
});

it('lets a sale undercut the regular price of a zone above the catalog', function (): void {
    $zone = Zone::factory()->create();

    foreach ([[PriceListType::Override, 0, 2400], [PriceListType::Sale, 1, 2200]] as [$type, $priority, $amount]) {
        $list = PriceList::factory()->create(['type' => $type, 'priority' => $priority]);
        PriceListRule::factory()->for($list)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);
        listPrice($list, $this->product, $amount);
    }

    expect(priceOf($this->product, ['zoneId' => $zone->id])->amount)->toBe(2200)
        ->and(priceOf($this->product)->amount)->toBe(2000);
});

it('puts an item sold only under contract on sale for its customer', function (): void {
    $customer = User::factory()->create();
    $unpriced = Product::factory()->create();

    foreach ([[PriceListType::Override, 5000], [PriceListType::Sale, 4500]] as [$type, $amount]) {
        $list = PriceList::factory()->create(['type' => $type]);
        PriceListRule::factory()->for($list)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);
        listPrice($list, $unpriced, $amount);
    }

    expect(priceOf($unpriced, ['customerId' => $customer->id])->amount)->toBe(4500)
        ->and(priceOf($unpriced))->toBeNull();
});

it('caps the strike of a public sale at the price a customer under contract pays', function (): void {
    $customer = User::factory()->create();
    $contract = PriceList::factory()->override()->create();
    PriceListRule::factory()->for($contract)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);
    listPrice($contract, $this->product, 1400);
    listPrice(PriceList::factory()->create(['priority' => 1]), $this->product, 1300);

    expect(priceOf($this->product, ['customerId' => $customer->id]))
        ->amount->toBe(1300)
        ->compareAmount->toBe(1400)
        ->and(priceOf($this->product)->compareAmount)->toBe(2000);
});

it('strikes no price for an offer the public history never announced', function (): void {
    $customer = User::factory()->create();
    $members = PriceList::factory()->create();
    PriceListRule::factory()->for($members)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);
    listPrice($members, $this->product, 1500);

    expect(priceOf($this->product, ['customerId' => $customer->id]))
        ->amount->toBe(1500)
        ->compareAmount->toBeNull();
});

it('prefers the lower amount, then the older list, among equally ranked lists', function (): void {
    $older = PriceList::factory()->create();
    $newer = PriceList::factory()->create();
    listPrice($newer, $this->product, 1500);
    listPrice($older, $this->product, 1600);

    expect(priceOf($this->product)->amount)->toBe(1500);

    listPrice($older, $this->product, 1400, ['min_quantity' => 2]);
    listPrice($newer, $this->product, 1400, ['min_quantity' => 2]);

    expect(priceOf($this->product, ['quantity' => 2])->meta['price_list']['id'])->toBe($older->public_id);
});

it('mixes fixed prices and a percentage in the same list', function (): void {
    $other = withCatalogPrice(Product::factory()->create(), 3000);
    $list = PriceList::factory()->decrease(2000)->create();
    listPrice($list, $this->product, 1700);

    expect(priceOf($this->product)->amount)->toBe(1700)
        ->and(priceOf($other)->amount)->toBe(2400);
});

it('rounds an adjusted price to the ending set for its currency only', function (): void {
    withCatalogPrice($this->product, 1371, 'EUR');
    withCatalogPrice($this->product, 13710, 'XAF');
    PriceList::factory()->decrease(1000)->create(['rounding_endings' => ['EUR' => 99, 'XAF' => 900]]);

    expect(priceOf($this->product)->amount)->toBe(1800)
        ->and(priceOf($this->product, ['currencyCode' => 'EUR'])->amount)->toBe(1199)
        ->and(priceOf($this->product, ['currencyCode' => 'XAF'])->amount)->toBe(11900);
});

it('keeps the unrounded amount when rounding would take more than the adjustment itself', function (): void {
    $cheap = withCatalogPrice(Product::factory()->create(), 50);
    $small = withCatalogPrice(Product::factory()->create(), 220);
    PriceList::factory()->decrease(1000)->create(['rounding_endings' => ['USD' => 99]]);

    expect(priceOf($cheap)->amount)->toBe(45)
        ->and(priceOf($small)->amount)->toBe(198);

    $marked = withCatalogPrice(Product::factory()->create(), 1000);
    PriceList::factory()->override()->increase(100)->create(['rounding_endings' => ['USD' => 99], 'priority' => 1]);
    app()->forgetScopedInstances();

    expect(priceOf($marked)->amount)->toBe(1010);
});

it('keeps the unrounded amount when its ending would take it below one unit', function (): void {
    $small = withCatalogPrice(Product::factory()->create(), 100);
    PriceList::factory()->decrease(9000)->create(['rounding_endings' => ['USD' => 99]]);

    expect(priceOf($small)->amount)->toBe(10);
});

it('floors an adjustment that would round to zero to one unit', function (): void {
    $cheap = withCatalogPrice(Product::factory()->create(), 3);
    PriceList::factory()->decrease(9999)->create();

    expect(priceOf($cheap)->amount)->toBe(1);
});

it('keeps a free item free under a percentage list', function (): void {
    $free = withCatalogPrice(Product::factory()->create(), 0);
    PriceList::factory()->override()->increase(1000)->create();

    expect(priceOf($free)->amount)->toBe(0);
});

it('ignores the settings of a list that would give products away or break pricing', function (array $attributes, ?int $fixed, int $expected): void {
    $list = PriceList::factory()->create($attributes);

    if ($fixed !== null) {
        listPrice($list, $this->product, $fixed);
    }

    expect(priceOf($this->product)->amount)->toBe($expected);
})->with([
    'a fixed price of zero' => [[], 0, 2000],
    'a decrease of 100 percent' => [['adjustment_type' => AdjustmentType::Decrease, 'adjustment_value' => 10000], null, 2000],
    'an increase above 100 percent' => [['type' => PriceListType::Override, 'adjustment_type' => AdjustmentType::Increase, 'adjustment_value' => 10001], null, 2000],
    'a rounding ending that is not a number' => [['adjustment_type' => AdjustmentType::Decrease, 'adjustment_value' => 1000, 'rounding_endings' => ['USD' => '9x']], null, 1800],
    'a negative rounding ending' => [['adjustment_type' => AdjustmentType::Decrease, 'adjustment_value' => 1000, 'rounding_endings' => ['USD' => -1]], null, 1800],
]);

it('sells at the fixed price of the currency asked for', function (): void {
    withCatalogPrice($this->product, 1800, 'EUR');
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    listPrice($list, $this->product, 1300, currency: 'EUR');

    expect(priceOf($this->product)->amount)->toBe(1500)
        ->and(priceOf($this->product, ['currencyCode' => 'EUR'])->amount)->toBe(1300);
});

it('never sells an item without catalog price at a sale price', function (): void {
    $unpriced = Product::factory()->create();
    listPrice(PriceList::factory()->create(), $unpriced, 900);

    expect(priceOf($unpriced))->toBeNull();
});

it('applies a fixed override to an item without catalog price, never an adjustment', function (): void {
    $unpriced = Product::factory()->create();
    $list = PriceList::factory()->override()->decrease(1000)->create();
    listPrice($list, $unpriced, 900);

    expect(priceOf($unpriced))
        ->amount->toBe(900)
        ->originalAmount->toBeNull()
        ->and(priceOf(Product::factory()->create()))->toBeNull();
});

it('ignores the list prices of a disabled currency', function (): void {
    withCatalogPrice($this->product, 1900, 'EUR');
    listPrice(PriceList::factory()->create(), $this->product, 1500, currency: 'EUR');
    Currency::query()->where('code', 'EUR')->update(['is_enabled' => false]);
    app()->forgetScopedInstances();

    expect(priceOf($this->product, ['currencyCode' => 'EUR'])->amount)->toBe(1900);
});

it('shows the compare-at price the list asks for', function (CompareAtMode $mode, ?int $expected): void {
    $product = withCatalogPrice(Product::factory()->create(), 2000, compareAmount: 2400);
    listPrice(PriceList::factory()->create(['compare_at_mode' => $mode]), $product, 1500);

    expect(priceOf($product)->compareAmount)->toBe($expected);
})->with([
    'keep' => [CompareAtMode::Keep, 2400],
    'base' => [CompareAtMode::Base, 2000],
    'none' => [CompareAtMode::None, null],
]);

it('shows the compare-at amount of a list price, never above the lowest price of 30 days', function (CompareAtMode $mode, int $expected): void {
    listPrice(PriceList::factory()->create(['compare_at_mode' => $mode]), $this->product, 1500, ['compare_amount' => 2600]);

    expect(priceOf($this->product)->compareAmount)->toBe($expected);
})->with([
    'none' => [CompareAtMode::None, 2600],
    'lowest of 30 days' => [CompareAtMode::Lowest30Days, 2000],
]);

it('drops a compare-at price that is not above the price sold', function (): void {
    $product = withCatalogPrice(Product::factory()->create(), 2000, compareAmount: 2400);
    listPrice(PriceList::factory()->override()->create(['compare_at_mode' => CompareAtMode::Keep]), $product, 2400);

    expect(priceOf($product)->compareAmount)->toBeNull();
});

it('reads the lists again on the next request of the same worker', function (): void {
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500);
    $resolver = resolve(PriceResolver::class);

    expect($resolver->resolve($this->product, new PricingContext('USD'))->amount)->toBe(1500);

    PriceList::query()->whereKey($list->id)->update(['status' => PriceListStatus::Archived]);
    app()->forgetScopedInstances();

    expect(resolve(PriceResolver::class))->toBe($resolver)
        ->and($resolver->resolve($this->product, new PricingContext('USD'))->amount)->toBe(2000);
});

it('applies the lists again once a computation without them fails', function (): void {
    listPrice(PriceList::factory()->create(), $this->product, 1500);

    expect(fn () => resolve(ApplicableLists::class)->withoutLists(fn () => throw new RuntimeException('Resolver failed.')))
        ->toThrow(RuntimeException::class)
        ->and(priceOf($this->product)->amount)->toBe(1500);
});

it('sees a list price changed earlier in the same request', function (): void {
    Queue::fake();
    $price = listPrice(PriceList::factory()->create(), $this->product, 1500);
    priceOf($this->product);

    $price->update(['amount' => 1400]);

    expect(priceOf($this->product)->amount)->toBe(1400);
});

it('sees a change of priority or a deleted rule without waiting for the cache to expire', function (): void {
    $customer = User::factory()->create();
    $low = PriceList::factory()->create(['priority' => 1]);
    listPrice($low, $this->product, 1500);
    listPrice(PriceList::factory()->create(['priority' => 2]), $this->product, 1800);
    $rule = PriceListRule::factory()->for($low)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);

    expect(priceOf($this->product)->amount)->toBe(1800);

    $low->update(['priority' => 5]);
    $rule->delete();

    expect(priceOf($this->product)->amount)->toBe(1500);
});

it('stops selling at the prices of a deleted list in the same request', function (): void {
    Queue::fake();
    $list = PriceList::factory()->decrease(5000)->create();

    expect(priceOf($this->product)->amount)->toBe(1000);

    $list->delete();

    expect(priceOf($this->product)->amount)->toBe(2000);
});

it('runs one query of its own per request while no list exists', function (): void {
    priceOf($this->product);
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    priceOf(Product::query()->with('prices.currency')->find($this->product->id));
    priceOf($this->product, ['quantity' => 2]);

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'price_list')))->toHaveCount(1);
});

it('loads the list prices of a page in one query', function (): void {
    $products = Product::factory()->count(24)->create()->each(fn (Product $product) => withCatalogPrice($product, 2000));
    $list = PriceList::factory()->create();
    $products->each(fn (Product $product) => listPrice($list, $product, 1500));
    $products = Product::query()->with('prices.currency')->whereKey($products->modelKeys())->get();
    $context = new PricingContext('USD');
    $resolver = resolve(PriceResolver::class);
    app()->forgetScopedInstances();
    priceOf($this->product);

    DB::enableQueryLog();
    $resolver->preload($products, $context);
    $amounts = $products->map(fn (Product $product): int => $resolver->resolve($product, $context)->amount);

    expect($amounts->unique()->all())->toBe([1500])
        ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'price_list_prices')))->toHaveCount(1);
});

it('reads the groups of a customer once for every list targeting groups', function (): void {
    $member = User::factory()->create();

    CustomerGroup::factory()->count(3)->create()->each(function (CustomerGroup $group) use ($member): void {
        $group->customers()->attach($member);
        $list = PriceList::factory()->override()->create();
        listPrice($list, $this->product, 1500);
        PriceListRule::factory()->for($list)->create(['rule_key' => 'customer_group', 'values' => [$group->id]]);
    });
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    priceOf($this->product, ['customerId' => $member->id]);
    priceOf($this->product, ['customerId' => $member->id, 'quantity' => 2]);
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'customer_group_user')))->toHaveCount(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'price_list_prices')))->toHaveCount(1);
});

it('forgets the lists read inside a transaction that rolls back', function (): void {
    app()->forgetScopedInstances();

    expect(fn () => DB::transaction(function (): void {
        PriceList::factory()->decrease(2000)->create();

        expect(priceOf($this->product)->amount)->toBe(1600);

        throw new RuntimeException('Rolled back.');
    }))->toThrow(RuntimeException::class, 'Rolled back.');

    expect(priceOf($this->product)->amount)->toBe(2000);
});
