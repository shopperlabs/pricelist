<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Setting;
use Shopper\Core\Models\Zone;
use Shopper\CustomerGroups\Models\CustomerGroup;
use Shopper\PriceList\Enum\AdjustmentType;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Jobs\SyncPriceList;
use Shopper\PriceList\Livewire\PriceListForm;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\PriceList\Rules\CustomerGroupRule;
use Shopper\PriceList\Rules\ZoneRule;
use Shopper\PriceList\Tests\Stubs\UnavailableRule;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    Gate::before(fn (): bool => true);
    $this->actingAs(User::factory()->create());
});

it('keeps the rules it does not change when an administrator renames a price list', function (): void {
    app(ApplicabilityRuleManager::class)->register(UnavailableRule::class);
    $list = PriceList::factory()->create();
    $zone = Zone::factory()->create();
    PriceListRule::factory()->for($list)->create(['rule_key' => ZoneRule::KEY, 'values' => [$zone->id]]);
    PriceListRule::factory()->for($list)->create(['rule_key' => UnavailableRule::KEY, 'values' => [1]]);

    Livewire::test(PriceListForm::class, ['priceList' => $list])
        ->fillForm(['name' => 'Summer'])
        ->call('store')
        ->assertHasNoErrors();

    expect($list->refresh()->name)->toBe('Summer')
        ->and($list->rules()->orderBy('rule_key')->pluck('values', 'rule_key')->all())
        ->toBe([UnavailableRule::KEY => [1], ZoneRule::KEY => [$zone->id]]);
});

it('keeps the inactive customer group of a price list an administrator renames', function (): void {
    $list = PriceList::factory()->create();
    $group = CustomerGroup::factory()->inactive()->create();
    PriceListRule::factory()->for($list)->create(['rule_key' => CustomerGroupRule::KEY, 'values' => [$group->id]]);

    Livewire::test(PriceListForm::class, ['priceList' => $list])
        ->fillForm(['name' => 'Summer'])
        ->call('store')
        ->assertHasNoErrors();

    expect($list->rules()->pluck('values', 'rule_key')->all())->toBe([CustomerGroupRule::KEY => [$group->id]]);
});

it('records the public price a sale sets once an administrator clears its customer groups', function (): void {
    $product = withCatalogPrice(Product::factory()->create(['created_at' => now()]), 10000);
    $list = PriceList::factory()->create();
    PriceListRule::factory()->for($list)->create(['rule_key' => CustomerGroupRule::KEY, 'values' => [CustomerGroup::factory()->create()->id]]);
    listPrice($list, $product, 8000);

    Livewire::test(PriceListForm::class, ['priceList' => $list])
        ->fillForm(['rules' => [CustomerGroupRule::KEY => []]])
        ->call('store')
        ->assertHasNoErrors();

    expect($list->rules()->count())->toBe(0)
        ->and(PriceHistory::query()->orderBy('id')->pluck('amount')->all())->toBe([10000, 8000]);
});

it('keeps a price list an administrator activates for customer groups out of the public history', function (): void {
    $product = withCatalogPrice(Product::factory()->create(['created_at' => now()]), 10000);
    $list = PriceList::factory()->draft()->create();
    listPrice($list, $product, 8000);

    Livewire::test(PriceListForm::class, ['priceList' => $list])
        ->fillForm([
            'status' => PriceListStatus::Active->value,
            'rules' => [CustomerGroupRule::KEY => [CustomerGroup::factory()->create()->id]],
        ])
        ->call('store')
        ->assertHasNoErrors();

    expect(PriceHistory::query()->pluck('amount')->all())->toBe([10000]);
});

it('resyncs a price list only when an edit changes its pricing or its rules', function (): void {
    [$first, $second] = CustomerGroup::factory()->count(2)->create()->all();
    $list = PriceList::factory()->create();
    PriceListRule::factory()->for($list)->create(['rule_key' => CustomerGroupRule::KEY, 'values' => [$first->id]]);
    Queue::fake([SyncPriceList::class]);

    Livewire::test(PriceListForm::class, ['priceList' => $list])
        ->fillForm(['name' => 'Summer'])
        ->call('store')
        ->assertHasNoErrors();

    Queue::assertNotPushed(SyncPriceList::class);

    Livewire::test(PriceListForm::class, ['priceList' => $list->refresh()])
        ->fillForm(['rules' => [CustomerGroupRule::KEY => [$second->id]]])
        ->call('store')
        ->assertHasNoErrors();

    Queue::assertPushed(SyncPriceList::class, 1);
    expect($list->rules()->pluck('values', 'rule_key')->all())->toBe([CustomerGroupRule::KEY => [$second->id]]);
});

it('creates a price list with its customer groups and its adjustment in basis points, then opens it', function (): void {
    Setting::query()->create(['key' => 'currencies', 'value' => [currency('USD')->id]]);
    $group = CustomerGroup::factory()->create();

    Livewire::test(PriceListForm::class)
        ->fillForm([
            'name' => 'VIP',
            'rules' => [CustomerGroupRule::KEY => [$group->id]],
            'adjustment_type' => AdjustmentType::Decrease->value,
            'adjustment_value' => 12.5,
            'rounding_endings' => ['USD' => 99],
        ])
        ->call('store')
        ->assertHasNoErrors()
        ->assertRedirect(route('shopper.price-lists.show', PriceList::query()->sole()));

    $list = PriceList::query()->sole();

    expect($list->adjustment_type)->toBe(AdjustmentType::Decrease)
        ->and($list->adjustment_value)->toBe(1250)
        ->and($list->rounding_endings)->toBe(['USD' => 99])
        ->and($list->compare_at_mode)->toBeNull()
        ->and($list->rules()->pluck('values', 'rule_key')->all())->toBe([CustomerGroupRule::KEY => [$group->id]]);
});
