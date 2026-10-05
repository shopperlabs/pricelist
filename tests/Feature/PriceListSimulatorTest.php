<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\Setting;
use Shopper\CustomerGroups\Models\CustomerGroup;
use Shopper\PriceList\Livewire\PriceListSimulator;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Rules\CustomerGroupRule;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    Gate::before(fn (): bool => true);
    $this->actingAs(User::factory()->create());

    Setting::query()->create(['key' => 'currencies', 'value' => [currency('USD')->id, currency('EUR')->id]]);

    $this->list = PriceList::factory()->create(['name' => 'Wholesale']);
    $this->product = withCatalogPrice(Product::factory()->create(), 1500);
});

/**
 * @param  array<string, mixed>  $data
 */
function simulatePrice(PriceList $list, string $priceable, array $data = []): Testable
{
    return Livewire::test(PriceListSimulator::class, ['priceList' => $list])
        ->fillForm(['currency' => 'USD', 'quantity' => 1, 'product' => $priceable, ...$data])
        ->call('simulate')
        ->assertHasNoErrors();
}

it('names this list and the tier that sets the simulated price', function (): void {
    $variant = withCatalogPrice(ProductVariant::factory()->for($this->product, 'product')->create(), 1500);
    listPrice($this->list, $variant, 1200, ['max_quantity' => 9]);
    listPrice($this->list, $variant, 900, ['min_quantity' => 10]);

    simulatePrice($this->list, "variant-{$variant->id}", ['quantity' => 12])
        ->assertSet('result.amount', shopper_money_format(900, 'USD'))
        ->assertSet('result.list', 'Wholesale (this list)')
        ->assertSet('result.source', 'Fixed price, variant level, tier 10 and more');
});

it('names the list that wins over this one', function (): void {
    listPrice($this->list, $this->product, 1300);
    PriceList::factory()->decrease(2000)->create(['name' => 'VIP', 'priority' => 10]);

    simulatePrice($this->list, "product-{$this->product->id}")
        ->assertSet('result.amount', shopper_money_format(1200, 'USD'))
        ->assertSet('result.list', 'VIP')
        ->assertSet('result.source', 'List adjustment');
});

it('falls back to the catalog price when no list applies to the customer', function (): void {
    listPrice($this->list, $this->product, 1300);
    PriceListRule::factory()->for($this->list)->create(['rule_key' => CustomerGroupRule::KEY, 'values' => [CustomerGroup::factory()->create()->id]]);

    simulatePrice($this->list, "product-{$this->product->id}")
        ->assertSet('result.amount', shopper_money_format(1500, 'USD'))
        ->assertSet('result.list', null)
        ->assertSet('result.source', null);
});

it('reports a product without price in the simulated currency', function (): void {
    listPrice($this->list, $this->product, 1300);

    simulatePrice($this->list, "product-{$this->product->id}", ['currency' => 'EUR'])
        ->assertSet('result.amount', null)
        ->assertSet('result.list', null);
});
