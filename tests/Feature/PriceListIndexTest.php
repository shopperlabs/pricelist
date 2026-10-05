<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Shopper\PriceList\Enum\PriceListState;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Livewire\PriceListIndex;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    Gate::before(fn (): bool => true);
    $this->actingAs(User::factory()->create());
});

it('lists in each tab the price lists whose state it shows, boundaries included', function (): void {
    $lists = collect([
        PriceList::factory()->draft()->create(),
        PriceList::factory()->create(['starts_at' => now()->addSecond()]),
        PriceList::factory()->create(),
        PriceList::factory()->create(['starts_at' => now(), 'ends_at' => now()->addSecond()]),
        PriceList::factory()->create(['ends_at' => now()]),
        PriceList::factory()->create(['starts_at' => now()->subWeek(), 'ends_at' => now()->subDay()]),
        PriceList::factory()->archived()->create(),
    ]);

    $component = Livewire::test(PriceListIndex::class)
        ->assertCanSeeTableRecords($lists->reject(fn (PriceList $list): bool => $list->state === PriceListState::Archived))
        ->assertCanNotSeeTableRecords($lists->filter(fn (PriceList $list): bool => $list->state === PriceListState::Archived));

    foreach (PriceListState::cases() as $state) {
        $component->set('activeTab', $state->value)
            ->assertCanSeeTableRecords($lists->filter(fn (PriceList $list): bool => $list->state === $state))
            ->assertCanNotSeeTableRecords($lists->reject(fn (PriceList $list): bool => $list->state === $state));
    }
});

it('archives the price lists an administrator selects', function (): void {
    $selected = PriceList::factory()->count(2)->create();
    $other = PriceList::factory()->create();

    Livewire::test(PriceListIndex::class)
        ->selectTableRecords($selected)
        ->callAction(TestAction::make('archive')->table()->bulk());

    expect($selected->map(fn (PriceList $list): PriceListStatus => $list->refresh()->status)->unique()->all())->toBe([PriceListStatus::Archived])
        ->and($other->refresh()->status)->toBe(PriceListStatus::Active);
});

it('opens the price list form in the slide-over panel', function (): void {
    $list = PriceList::factory()->create();

    Livewire::test(PriceListIndex::class)
        ->callAction('createPriceList')
        ->assertDispatched('openPanel', 'shopper-pricelist.form')
        ->callAction(TestAction::make('edit')->table($list))
        ->assertDispatched('openPanel', 'shopper-pricelist.form', ['priceList' => $list->id]);
});
