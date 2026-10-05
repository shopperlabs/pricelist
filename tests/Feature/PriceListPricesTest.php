<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\Setting;
use Shopper\PriceList\Events\PriceListPricesSaved;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Livewire\PriceListPrices;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListQuantityRule;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    $this->denied = [];
    Gate::before(fn (User $user, string $ability): bool => ! in_array($ability, $this->denied, true));
    $this->actingAs(User::factory()->create());

    Setting::query()->create(['key' => 'currencies', 'value' => [currency('USD')->id, currency('XAF')->id]]);

    $this->list = PriceList::factory()->create();
    $this->product = Product::factory()->create();
    $this->variant = ProductVariant::factory()->for(Product::factory(), 'product')->create(['id' => $this->product->id]);
});

/**
 * @return list<array{0: int, 1: ?int, 2: int}>
 */
function ladderOf(PriceList $list, Model $priceable, string $currency = 'USD'): array
{
    return $list->prices()
        ->whereMorphedTo('priceable', $priceable)
        ->where('currency_id', currency($currency)->id)
        ->orderBy('min_quantity')
        ->get()
        ->map(fn (PriceListPrice $price): array => [$price->min_quantity, $price->max_quantity, $price->amount])
        ->all();
}

it('stores the amount an administrator types in a cell in the minor unit of its currency', function (string $code, string $amount, int $stored): void {
    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->set("cells.product-{$this->product->id}.{$code}", $amount)
        ->call('save')
        ->assertHasNoErrors();

    expect(ladderOf($this->list, $this->product, $code))->toBe([[1, null, $stored]]);
})->with([
    'smallest USD amount' => ['USD', '0.01', 1],
    'largest USD amount' => ['USD', '999999.99', 99_999_999],
    'XAF has no minor unit' => ['XAF', '1250000', 1_250_000],
]);

it('rejects a cell amount the resolver would never apply', function (string $code, string $amount): void {
    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->set("cells.product-{$this->product->id}.{$code}", $amount)
        ->call('save')
        ->assertHasErrors(["cells.product-{$this->product->id}.{$code}"]);

    expect($this->list->prices()->count())->toBe(0);
})->with([
    'zero' => ['USD', '0'],
    'beyond the column range' => ['USD', '10000000'],
    'fraction of a currency without minor unit' => ['XAF', '0.5'],
]);

it('replaces the base price of a product without touching its higher tiers, the variant sharing its id or another list', function (): void {
    listPrice($this->list, $this->product, 1500, ['max_quantity' => 9]);
    listPrice($this->list, $this->product, 1200, ['min_quantity' => 10]);
    listPrice($this->list, $this->variant, 1100);
    $other = PriceList::factory()->create();
    listPrice($other, $this->product, 1300);

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->set("cells.product-{$this->product->id}.USD", '14')
        ->call('save')
        ->assertHasNoErrors();

    expect(ladderOf($this->list, $this->product))->toBe([[1, 9, 1400], [10, null, 1200]])
        ->and(ladderOf($this->list, $this->variant))->toBe([[1, null, 1100]])
        ->and(ladderOf($other, $this->product))->toBe([[1, null, 1300]]);
});

it('stores the tiers an administrator sets on a cell', function (): void {
    Repeater::fake();

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->callAction(TestAction::make('tiers')->arguments(['key' => "variant-{$this->variant->id}", 'code' => 'USD']), [
            'tiers' => [
                ['min_quantity' => 1, 'max_quantity' => 9, 'amount' => '15', 'compare_amount' => null],
                ['min_quantity' => 10, 'max_quantity' => null, 'amount' => '12', 'compare_amount' => null],
            ],
        ])
        ->assertHasNoActionErrors()
        ->call('save')
        ->assertHasNoErrors();

    expect(ladderOf($this->list, $this->variant))->toBe([[1, 9, 1500], [10, null, 1200]])
        ->and(ladderOf($this->list, $this->product))->toBe([]);
});

it('rejects tiers covering the same quantities', function (): void {
    Repeater::fake();

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->callAction(TestAction::make('tiers')->arguments(['key' => "product-{$this->product->id}", 'code' => 'USD']), [
            'tiers' => [
                ['min_quantity' => 1, 'max_quantity' => 10, 'amount' => '15', 'compare_amount' => null],
                ['min_quantity' => 5, 'max_quantity' => null, 'amount' => '12', 'compare_amount' => null],
            ],
        ])
        ->assertHasActionErrors(['tiers']);
});

it('removes a product with its variants from this list only', function (): void {
    $child = ProductVariant::factory()->for($this->product, 'product')->create();
    $other = PriceList::factory()->create();

    foreach ([$this->product, $child, $this->variant] as $priceable) {
        listPrice($this->list, $priceable, 1000);
        PriceListQuantityRule::factory()->for($this->list)->for($priceable, 'priceable')->create();
    }

    listPrice($other, $this->product, 1000);

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->callAction(TestAction::make('remove')->arguments(['key' => "product-{$this->product->id}"]));

    expect($this->list->prices()->get()->map->priceable_type->all())->toBe([$this->variant->getMorphClass()])
        ->and($this->list->prices()->sole()->priceable_id)->toBe($this->variant->id)
        ->and($this->list->quantityRules()->sole()->priceable_type)->toBe($this->variant->getMorphClass())
        ->and(ladderOf($other, $this->product))->toBe([[1, null, 1000]]);
});

it('saves the quantity rule of a product and rejects a minimum off the increment', function (): void {
    $action = TestAction::make('quantityRule')->arguments(['key' => "product-{$this->product->id}"]);

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->callAction($action, ['minimum' => 5, 'maximum' => null, 'increment' => 3])
        ->assertHasActionErrors(['increment']);

    expect($this->list->quantityRules()->count())->toBe(0);

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->callAction($action, ['minimum' => 6, 'maximum' => 60, 'increment' => 3])
        ->assertHasNoActionErrors();

    expect($this->list->quantityRules()->sole()->only(['priceable_id', 'minimum', 'maximum', 'increment']))
        ->toBe(['priceable_id' => $this->product->id, 'minimum' => 6, 'maximum' => 60, 'increment' => 3]);
});

it('keeps the prices read-only without the permission to edit products', function (): void {
    $this->denied = ['products.edit'];
    listPrice($this->list, $this->product, 1500);

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->assertActionHidden(TestAction::make('remove')->arguments(['key' => "product-{$this->product->id}"]))
        ->assertActionHidden(TestAction::make('tiers')->arguments(['key' => "product-{$this->product->id}", 'code' => 'USD']))
        ->assertActionHidden('addProducts')
        ->assertActionHidden('archive')
        ->assertActionVisible('simulate')
        ->set("cells.product-{$this->product->id}.USD", '14')
        ->call('save');

    expect(ladderOf($this->list, $this->product))->toBe([[1, null, 1500]]);
});

it('resyncs the product and records the change once prices are saved', function (): void {
    Queue::fake([SyncProductPrices::class]);
    Event::fake([PriceListPricesSaved::class]);
    listPrice($this->list, $this->variant, 1100);

    Livewire::test(PriceListPrices::class, ['priceList' => $this->list])
        ->set("cells.variant-{$this->variant->id}.USD", '9.50')
        ->call('save')
        ->assertHasNoErrors();

    Queue::assertPushed(SyncProductPrices::class, fn (SyncProductPrices $job): bool => $job->productIds === [$this->variant->product_id]);
    Event::assertDispatched(PriceListPricesSaved::class, fn (PriceListPricesSaved $event): bool => $event->changes === [[
        'priceable_type' => $this->variant->getMorphClass(),
        'priceable_id' => $this->variant->id,
        'currency' => 'USD',
        'before' => [['min_quantity' => 1, 'max_quantity' => null, 'amount' => 1100, 'compare_amount' => null]],
        'after' => [['min_quantity' => 1, 'max_quantity' => null, 'amount' => 950, 'compare_amount' => null]],
    ]]);
});
