<?php

declare(strict_types=1);

namespace Shopper\PriceList\Livewire;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;
use Shopper\Livewire\Pages\AbstractPageComponent;
use Shopper\PriceList\Enum\PriceListState;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Livewire\Concerns\DescribesPriceList;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\Traits\HandlesAuthorizationExceptions;

class PriceListIndex extends AbstractPageComponent implements HasActions, HasSchemas, HasTable
{
    use DescribesPriceList;
    use HandlesAuthorizationExceptions;
    use HasTabs;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Url(as: 'tab', except: 'all')]
    public ?string $activeTab = null;

    public function mount(): void
    {
        $this->authorize('products.browse');

        $this->loadDefaultActiveTab();
    }

    #[On('pricelist.saved')]
    public function refreshPriceLists(): void
    {
        unset($this->stateCounts);
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make(__('shopper-pricelist::messages.tabs.all'))
                ->query(fn (Builder $query): Builder => $query->whereNot('status', PriceListStatus::Archived)),
        ];

        foreach (PriceListState::cases() as $state) {
            $tabs[$state->value] = Tab::make($state->getLabel())
                ->icon($state->getIcon())
                ->badge(fn (): ?int => $this->stateCounts[$state->value] ?? null)
                ->badgeColor($state->getColor())
                ->query(fn (Builder $query): Builder => $query->scopes(['inState' => [$state]]));
        }

        return $tabs;
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function stateCounts(): array
    {
        return PriceList::query()
            ->get(['id', 'status', 'starts_at', 'ends_at'])
            ->countBy(fn (PriceList $priceList): string => $priceList->state->value)
            ->all();
    }

    public function createPriceListAction(): Action
    {
        return Action::make('createPriceList')
            ->label(__('shopper-pricelist::messages.actions.add'))
            ->authorize('products.create')
            ->action(fn () => $this->dispatch('openPanel', 'shopper-pricelist.form'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PriceList::query()->with('rules')->withCount('prices'))
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
            ->defaultSort('priority', 'desc')
            ->recordUrl(fn (PriceList $record): string => route('shopper.price-lists.show', $record))
            ->columns([
                TextColumn::make('name')
                    ->label(__('shopper::forms.label.name'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('state')
                    ->label(__('shopper::forms.label.status'))
                    ->badge()
                    ->icon(fn (PriceListState $state): string => $state->getIcon())
                    ->color(fn (PriceListState $state): string => $state->getColor())
                    ->formatStateUsing(fn (PriceListState $state): string => $state->getLabel()),
                TextColumn::make('type')
                    ->label(__('shopper::forms.label.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (PriceListType $state): string => $state->getLabel()),
                TextColumn::make('target')
                    ->label(__('shopper-pricelist::messages.target'))
                    ->state(fn (PriceList $record): string => $this->target($record))
                    ->color('gray'),
                TextColumn::make('adjustment_value')
                    ->label(__('shopper-pricelist::messages.adjustment'))
                    ->state(fn (PriceList $record): ?string => $this->adjustment($record))
                    ->placeholder('-')
                    ->alignRight(),
                TextColumn::make('prices_count')
                    ->label(__('shopper-pricelist::messages.prices'))
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('starts_at')
                    ->label(__('shopper-pricelist::messages.period'))
                    ->state(fn (PriceList $record): string => $this->period($record))
                    ->color('gray'),
                TextColumn::make('priority')
                    ->label(__('shopper-pricelist::messages.priority'))
                    ->numeric()
                    ->alignRight()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('shopper::forms.label.type'))
                    ->options([
                        PriceListType::Sale->value => PriceListType::Sale->getLabel(),
                        PriceListType::Override->value => PriceListType::Override->getLabel(),
                    ]),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('shopper::forms.actions.edit'))
                    ->icon(Untitledui::Edit03)
                    ->iconButton()
                    ->authorize('products.edit')
                    ->action(fn (PriceList $record) => $this->dispatch(
                        'openPanel',
                        'shopper-pricelist.form',
                        ['priceList' => $record->id],
                    )),
                ActionGroup::make([
                    Action::make('archive')
                        ->label(__('shopper-pricelist::messages.actions.archive'))
                        ->icon(Untitledui::Archive)
                        ->requiresConfirmation()
                        ->modalIcon(Untitledui::Archive)
                        ->authorize('products.edit')
                        ->visible(fn (PriceList $record): bool => $record->status !== PriceListStatus::Archived)
                        ->action(function (PriceList $record): void {
                            $record->update(['status' => PriceListStatus::Archived]);

                            unset($this->stateCounts);
                        }),
                    Action::make('delete')
                        ->label(__('shopper::forms.actions.delete'))
                        ->icon(Untitledui::Trash03)
                        ->modalIcon(Untitledui::Trash03)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('products.delete')
                        ->action(function (PriceList $record): void {
                            $record->delete();

                            unset($this->stateCounts);
                        }),
                ]),
            ])
            ->groupedBulkActions([
                BulkAction::make('archive')
                    ->label(__('shopper-pricelist::messages.actions.archive'))
                    ->icon(Untitledui::Archive)
                    ->requiresConfirmation()
                    ->authorize('products.edit')
                    ->action(function (Collection $records): void {
                        $records->each->update(['status' => PriceListStatus::Archived]);

                        unset($this->stateCounts);
                    })
                    ->deselectRecordsAfterCompletion(),
                DeleteBulkAction::make()
                    ->label(__('shopper::forms.actions.delete'))
                    ->icon(Untitledui::Trash03)
                    ->requiresConfirmation()
                    ->authorize('products.delete')
                    ->action(function (Collection $records): void {
                        $records->each->delete();

                        unset($this->stateCounts);

                        Notification::make()
                            ->title(__('shopper::notifications.delete', ['item' => __('shopper-pricelist::messages.single')]))
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyState(view('shopper-pricelist::livewire.tables.empty-states.price-lists'));
    }

    public function render(): View
    {
        return view('shopper-pricelist::livewire.pages.price-list-index')
            ->title(__('shopper-pricelist::messages.title'));
    }

    private function target(PriceList $priceList): string
    {
        $rules = app(ApplicabilityRuleManager::class)->all();

        return $priceList->rules->isEmpty()
            ? __('shopper-pricelist::messages.everyone')
            : $priceList->rules
                ->map(fn (PriceListRule $rule): string => sprintf('%s (%d)', isset($rules[$rule->rule_key]) ? $rules[$rule->rule_key]->label() : $rule->rule_key, count($rule->values)))
                ->implode(', ');
    }
}
