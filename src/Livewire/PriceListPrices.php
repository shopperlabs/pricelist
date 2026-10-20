<?php

declare(strict_types=1);

namespace Shopper\PriceList\Livewire;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;
use NumberFormatter;
use Shopper\Components\Form\MoneyInput;
use Shopper\Core\Models\Contracts\Product;
use Shopper\Core\Models\Contracts\ProductVariant;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Price;
use Shopper\Livewire\Pages\AbstractPageComponent;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Contracts\HasRuleForm;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Events\PriceListPricesSaved;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Livewire\Concerns\DescribesPriceList;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListQuantityRule;
use Shopper\PriceList\Pricing\PriceListLookup;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\PriceList\Sync\CoveredProducts;
use Shopper\Sidebar\Breadcrumbs\Breadcrumb;
use Shopper\Sidebar\Traits\WithBreadcrumbs;
use Shopper\Traits\HandlesAuthorizationExceptions;

/**
 * @phpstan-type Tier array{min_quantity: int, max_quantity: ?int, amount: int, compare_amount: ?int}
 *
 * @property-read Schema $targetSchema
 * @property-read Collection<int, Currency> $currencies
 * @property-read LengthAwarePaginator<int, Model> $products
 * @property-read SupportCollection<int, Collection<int, Model>> $variants
 * @property-read array<string, array<string, list<Tier>>> $stored
 * @property-read array<string, PriceListQuantityRule> $quantityRules
 */
class PriceListPrices extends AbstractPageComponent implements HasActions, HasSchemas
{
    use DescribesPriceList;
    use HandlesAuthorizationExceptions;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use WithBreadcrumbs;
    use WithPagination;

    private const int PER_PAGE = 25;

    #[Locked]
    public PriceList $priceList;

    #[Url(except: '')]
    public string $search = '';

    /** @var list<string> */
    public array $currencyCodes = [];

    /** @var list<int> */
    public array $expanded = [];

    /** @var list<string> */
    public array $selected = [];

    /** @var list<int> */
    #[Locked]
    public array $added = [];

    /** @var array<string, array<string, mixed>> */
    public array $cells = [];

    /** @var array<string, array<string, list<Tier>>> */
    #[Locked]
    public array $ladders = [];

    /** @var array<string, mixed> */
    public array $target = [];

    #[Locked]
    public string $targetSummary = '';

    public function mount(PriceList $priceList): void
    {
        $this->authorize('products.browse');

        $this->priceList = $priceList;
        $this->currencyCodes = $this->currencies->pluck('code')->all();

        $this->refreshTarget();
    }

    /**
     * @return list<Breadcrumb>
     */
    public function getBreadcrumbs(): array
    {
        return [
            new Breadcrumb(text: $this->priceList->name),
        ];
    }

    #[On('pricelist.saved')]
    public function refreshTarget(): void
    {
        $this->priceList->refresh();

        $values = $this->priceList->rules->pluck('values', 'rule_key')->all();

        $this->targetSchema->fill(['rules' => $values]);
        $this->targetSummary = $this->summarizeTarget($this->targetSchema, $values);
    }

    public function targetSchema(Schema $schema): Schema
    {
        return $schema
            ->components(collect(app(ApplicabilityRuleManager::class)->all())
                ->filter(fn (ApplicabilityRule $rule): bool => $rule instanceof HasRuleForm)
                ->map(fn (HasRuleForm $rule): Component => $rule->formField())
                ->values()
                ->all())
            ->statePath('target');
    }

    /**
     * @return Collection<int, Currency>
     */
    #[Computed]
    public function currencies(): Collection
    {
        return Currency::query()
            ->whereIn('id', shopper_setting('currencies') ?? [])
            ->orderBy('code')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $search = mb_trim($this->search);
        $added = array_map(intval(...), $this->added);
        $variantMorph = resolve(ProductVariant::class)->getMorphClass();

        return $this->listedProducts()
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('sku', "%{$search}%")
                ->orWhereHas('variants', fn (Builder $query): Builder => $query->whereLike('sku', "%{$search}%"))))
            ->when($added !== [], fn (Builder $query): Builder => $query->orderByRaw('case when id in ('.implode(',', $added).') then 0 else 1 end'))
            ->orderBy('name')
            ->withCount([
                'variants',
                'variants as priced_variants_count' => fn (Builder $query): Builder => $query->whereIn(
                    'id',
                    $this->priceList->prices()->toBase()->where('priceable_type', $variantMorph)->select('priceable_id'),
                ),
            ])
            ->with(['prices' => $this->catalogPrices(...)])
            ->paginate(self::PER_PAGE);
    }

    #[Computed]
    public function variants(): SupportCollection
    {
        $productIds = array_values(array_intersect(
            array_map(intval(...), $this->expanded),
            $this->products->getCollection()->pluck('id')->all(),
        ));

        if ($productIds === []) {
            return collect();
        }

        return resolve(ProductVariant::class)::query()
            ->whereIntegerInRaw('product_id', $productIds)
            ->with(['prices' => $this->catalogPrices(...)])
            ->orderBy('position')
            ->get()
            ->groupBy('product_id');
    }

    /**
     * @return array<string, array<string, list<Tier>>>
     */
    #[Computed]
    public function stored(): array
    {
        return $this->storedLadders(array_unique([
            ...$this->visibleKeys(),
            ...array_keys($this->cells),
            ...array_keys($this->ladders),
        ]));
    }

    /**
     * @return array<string, PriceListQuantityRule>
     */
    #[Computed]
    public function quantityRules(): array
    {
        $priceables = $this->parseKeys($this->visibleKeys());

        if ($priceables === []) {
            return [];
        }

        return $this->priceList->quantityRules()
            ->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, array_values($priceables)))
            ->get()
            ->keyBy(fn (PriceListQuantityRule $rule): string => $this->keyFor($rule->priceable_type, $rule->priceable_id))
            ->all();
    }

    public function toggle(int $productId): void
    {
        $this->expanded = in_array($productId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$productId]))
            : [...$this->expanded, $productId];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    #[On('pricelist.products.selected')]
    public function addProducts(array $ids): void
    {
        $this->authorize('products.edit');

        $this->added = array_values(array_unique([...array_map(intval(...), $ids), ...$this->added]));

        $this->resetPage();
    }

    public function resetDraft(): void
    {
        $this->cells = [];
        $this->ladders = [];

        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorize('products.edit');

        [$draft, $errors] = $this->draft();

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $priceables = $this->priceables(array_keys($draft));
        $currencies = $this->currencies->pluck('id', 'code');

        $changes = DB::transaction(function () use ($draft, $priceables, $currencies): array {
            PriceList::query()->whereKey($this->priceList->id)->lockForUpdate()->first();

            $before = $this->storedLadders(array_keys($priceables));
            $now = now();
            $rows = [];
            $changes = [];

            foreach ($priceables as $key => [$type, $id]) {
                foreach ($draft[$key] as $code => $ladder) {
                    $this->priceList->prices()
                        ->where('priceable_type', $type)
                        ->where('priceable_id', $id)
                        ->where('currency_id', $currencies[$code])
                        ->delete();

                    foreach ($ladder as $tier) {
                        $rows[] = [
                            ...$tier,
                            'price_list_id' => $this->priceList->id,
                            'priceable_type' => $type,
                            'priceable_id' => $id,
                            'currency_id' => $currencies[$code],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    $changes[] = [
                        'priceable_type' => $type,
                        'priceable_id' => $id,
                        'currency' => $code,
                        'before' => $before[$key][$code] ?? [],
                        'after' => $ladder,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                PriceListPrice::query()->insert($chunk);
            }

            return $changes;
        });

        $this->synced(array_column($priceables, 2), $changes);

        $this->resetDraft();

        Notification::make()
            ->title(__('shopper-pricelist::messages.prices_saved'))
            ->success()
            ->send();
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('shopper::forms.actions.edit'))
            ->color('gray')
            ->authorize('products.edit')
            ->action(fn () => $this->dispatch(
                'openPanel',
                'shopper-pricelist.form',
                ['priceList' => $this->priceList->id],
            ));
    }

    public function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(__('shopper-pricelist::messages.actions.archive'))
            ->icon(Untitledui::Archive)
            ->modalIcon(Untitledui::Archive)
            ->requiresConfirmation()
            ->authorize('products.edit')
            ->visible(fn (): bool => $this->priceList->status !== PriceListStatus::Archived)
            ->action(function (): void {
                $this->priceList->update(['status' => PriceListStatus::Archived]);

                Notification::make()
                    ->title(__('shopper-pricelist::messages.updated'))
                    ->success()
                    ->send();
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('shopper::forms.actions.delete'))
            ->icon(Untitledui::Trash03)
            ->modalIcon(Untitledui::Trash03)
            ->color('danger')
            ->requiresConfirmation()
            ->authorize('products.delete')
            ->action(function (): void {
                $this->priceList->delete();

                Notification::make()
                    ->title(__('shopper::notifications.delete', ['item' => __('shopper-pricelist::messages.single')]))
                    ->success()
                    ->send();

                $this->redirectRoute('shopper.price-lists', navigate: true);
            });
    }

    public function simulateAction(): Action
    {
        return Action::make('simulate')
            ->label(__('shopper-pricelist::messages.actions.simulate'))
            ->icon(Untitledui::PlayCircle)
            ->color('gray')
            ->action(fn () => $this->dispatch(
                'openPanel',
                'shopper-pricelist.simulator',
                ['priceList' => $this->priceList->id],
            ));
    }

    public function addProductsAction(): Action
    {
        return Action::make('addProducts')
            ->label(__('shopper-pricelist::messages.actions.add_products'))
            ->icon(Untitledui::Plus)
            ->color('gray')
            ->authorize('products.edit')
            ->action(fn () => $this->dispatch(
                'openPanel',
                'shopper-slide-overs.products-picker',
                [
                    'exceptIds' => $this->listedProducts()->pluck('id')->all(),
                    'ability' => 'products.edit',
                    'title' => __('shopper-pricelist::messages.actions.add_products'),
                    'event' => 'pricelist.products.selected',
                ],
            ));
    }

    public function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('shopper-pricelist::messages.actions.remove'))
            ->icon(Untitledui::Trash03)
            ->modalIcon(Untitledui::Trash03)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('shopper-pricelist::messages.remove_description'))
            ->authorize('products.edit')
            ->action(fn (array $arguments) => $this->remove([(string) ($arguments['key'] ?? '')]));
    }

    public function removeSelectedAction(): Action
    {
        return Action::make('removeSelected')
            ->label(fn (): string => __('shopper-pricelist::messages.actions.remove_selected', ['count' => count($this->selected)]))
            ->icon(Untitledui::Trash03)
            ->modalIcon(Untitledui::Trash03)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('shopper-pricelist::messages.remove_description'))
            ->authorize('products.edit')
            ->visible(fn (): bool => $this->selected !== [])
            ->action(fn () => $this->remove(array_map(strval(...), $this->selected)));
    }

    public function tiersAction(): Action
    {
        return Action::make('tiers')
            ->authorize('products.edit')
            ->modalHeading(fn (array $arguments): string => __('shopper-pricelist::messages.tiers_heading', [
                'name' => $this->priceableName($this->cell($arguments)[0]),
                'currency' => $this->cell($arguments)[1],
            ]))
            ->modalDescription(__('shopper-pricelist::messages.tiers_scope', ['scope' => $this->priceList->tier_scope->getLabel()]))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel(__('shopper-pricelist::messages.actions.apply'))
            ->fillForm(fn (array $arguments): array => ['tiers' => $this->ladder(...$this->cell($arguments))])
            ->schema(fn (array $arguments): array => [
                Repeater::make('tiers')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make(__('shopper-pricelist::messages.min_quantity'))->markAsRequired(),
                        TableColumn::make(__('shopper-pricelist::messages.max_quantity')),
                        TableColumn::make(__('shopper-pricelist::messages.unit_price'))->markAsRequired(),
                        TableColumn::make(__('shopper-pricelist::messages.compare_amount')),
                    ])
                    ->schema([
                        TextInput::make('min_quantity')
                            ->hiddenLabel()
                            ->integer()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('max_quantity')
                            ->hiddenLabel()
                            ->integer()
                            ->gte('min_quantity')
                            ->nullable(),
                        MoneyInput::make('amount')
                            ->hiddenLabel()
                            ->currency($this->cell($arguments)[1])
                            ->amountRules()
                            ->minValue(0.01)
                            ->required(),
                        MoneyInput::make('compare_amount')
                            ->hiddenLabel()
                            ->currency($this->cell($arguments)[1])
                            ->amountRules()
                            ->minValue(0.01)
                            ->nullable(),
                    ])
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel(__('shopper-pricelist::messages.actions.add_tier'))
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $ranges = collect((array) $value)
                            ->map(fn (mixed $tier): array => [
                                (int) data_get($tier, 'min_quantity'),
                                filled(data_get($tier, 'max_quantity')) ? (int) data_get($tier, 'max_quantity') : PHP_INT_MAX,
                            ])
                            ->sortBy(0)
                            ->values();

                        foreach ($ranges as $index => [$min]) {
                            if ($index > 0 && $min <= $ranges[$index - 1][1]) {
                                $fail(__('shopper-pricelist::messages.tiers_overlap'));

                                return;
                            }
                        }
                    }]),
            ])
            ->action(function (array $data, array $arguments): void {
                [$key, $code] = $this->cell($arguments);

                $ladder = collect($data['tiers'] ?? [])
                    ->map(fn (array $tier): array => $this->tier(
                        (int) $tier['min_quantity'],
                        filled($tier['max_quantity'] ?? null) ? (int) $tier['max_quantity'] : null,
                        (int) $tier['amount'],
                        filled($tier['compare_amount'] ?? null) ? (int) $tier['compare_amount'] : null,
                    ))
                    ->sortBy('min_quantity')
                    ->values()
                    ->all();

                $this->ladders[$key][$code] = $ladder;
                $this->cells[$key][$code] = $this->format($this->baseAmount($ladder), $code);
            });
    }

    public function quantityRuleAction(): Action
    {
        return Action::make('quantityRule')
            ->authorize('products.edit')
            ->modalHeading(fn (array $arguments): string => __('shopper-pricelist::messages.quantity_rule_heading', [
                'name' => $this->priceableName((string) ($arguments['key'] ?? '')),
            ]))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('shopper-pricelist::messages.actions.apply'))
            ->fillForm(fn (array $arguments): array => $this->quantityRule((string) ($arguments['key'] ?? ''))?->only(['minimum', 'maximum', 'increment'])
                ?? ['minimum' => 1, 'maximum' => null, 'increment' => 1])
            ->schema([
                Grid::make(3)
                    ->schema([
                        TextInput::make('minimum')
                            ->label(__('shopper-pricelist::messages.quantity_minimum'))
                            ->integer()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('maximum')
                            ->label(__('shopper-pricelist::messages.quantity_maximum'))
                            ->integer()
                            ->gte('minimum')
                            ->nullable(),
                        TextInput::make('increment')
                            ->label(__('shopper-pricelist::messages.quantity_increment'))
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ((int) $value > 0 && (int) $get('minimum') % (int) $value !== 0) {
                                    $fail(__('shopper-pricelist::messages.quantity_increment_invalid'));
                                }
                            }]),
                    ]),
                Text::make(__('shopper-pricelist::messages.quantity_rule_help')),
            ])
            ->extraModalFooterActions(fn (Action $action, array $arguments): array => $this->quantityRule((string) ($arguments['key'] ?? '')) === null ? [] : [
                $action->makeModalSubmitAction('removeRule', ['remove' => true])
                    ->label(__('shopper::forms.actions.delete'))
                    ->color('danger'),
            ])
            ->action(function (array $data, array $arguments): void {
                $key = (string) ($arguments['key'] ?? '');
                [$type, $id] = $this->priceables([$key])[$key] ?? abort(404);
                $rule = $this->quantityRule($key);

                if ($arguments['remove'] ?? false) {
                    $rule?->delete();
                } else {
                    $this->priceList->quantityRules()->updateOrCreate(
                        ['priceable_type' => $type, 'priceable_id' => $id],
                        [
                            'minimum' => (int) $data['minimum'],
                            'maximum' => filled($data['maximum']) ? (int) $data['maximum'] : null,
                            'increment' => (int) $data['increment'],
                        ],
                    );
                }

                unset($this->quantityRules);

                Notification::make()
                    ->title(__('shopper-pricelist::messages.quantity_rule_saved'))
                    ->success()
                    ->send();
            });
    }

    public function render(): View
    {
        [$draft, $errors] = $this->draft();

        if ($draft === [] && $errors === []) {
            $this->cells = [];
            $this->ladders = [];
        }

        $currencies = $this->visibleCurrencies();

        foreach ($this->visibleKeys() as $key) {
            foreach ($currencies as $currency) {
                if (! array_key_exists($currency->code, (array) ($this->cells[$key] ?? []))) {
                    $this->cells[$key][$currency->code] = $this->format($this->baseAmount($this->ladder($key, $currency->code)), $currency->code);
                }
            }
        }

        $bag = $this->getErrorBag();

        foreach (array_keys($bag->getMessages()) as $field) {
            if (str_starts_with($field, 'cells.')) {
                $bag->forget($field);
            }
        }

        foreach ($errors as $field => $message) {
            $bag->add($field, $message);
        }

        return view('shopper-pricelist::livewire.pages.price-list-prices', [
            'visibleCurrencies' => $currencies,
            'rowKeys' => $this->visibleKeys(),
            'period' => $this->period($this->priceList),
            'timezone' => $this->timezone(),
            'adjustment' => $this->adjustment($this->priceList),
            'hints' => $this->hints($currencies),
            'changes' => count($errors) + collect($draft)->sum(fn (array $codes): int => count($codes)),
            'canEdit' => Gate::allows('products.edit'),
        ])->title($this->priceList->name);
    }

    /**
     * @return Collection<int, Currency>
     */
    private function visibleCurrencies(): Collection
    {
        return $this->currencies->whereIn('code', $this->currencyCodes)->values();
    }

    private function catalogPrices(Relation $query): void
    {
        $query->whereIn('currency_id', $this->visibleCurrencies()->modelKeys())->with('currency');
    }

    private function listedProducts(): Builder
    {
        $product = resolve(Product::class);
        $variant = resolve(ProductVariant::class);
        $priceables = fn (string $relation, string $type) => $this->priceList->{$relation}()->toBase()
            ->where('priceable_type', $type)
            ->select('priceable_id');

        return $product::query()->where(fn (Builder $query): Builder => $query
            ->whereIn('id', $priceables('prices', $product->getMorphClass()))
            ->orWhereIn('id', $priceables('quantityRules', $product->getMorphClass()))
            ->orWhereIn('id', $variant::query()->whereIn('id', $priceables('prices', $variant->getMorphClass()))->select('product_id'))
            ->orWhereIn('id', $variant::query()->whereIn('id', $priceables('quantityRules', $variant->getMorphClass()))->select('product_id'))
            ->orWhereIntegerInRaw('id', array_map(intval(...), $this->added)));
    }

    /**
     * @return list<string>
     */
    private function visibleKeys(): array
    {
        return [
            ...$this->products->getCollection()->map(fn (Model $product): string => 'product-'.$product->getKey())->all(),
            ...$this->variants->flatten()->map(fn (Model $variant): string => 'variant-'.$variant->getKey())->all(),
        ];
    }

    private function keyFor(string $type, int $id): string
    {
        return ($type === resolve(Product::class)->getMorphClass() ? 'product-' : 'variant-').$id;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function parseKey(string $key): ?array
    {
        if (! preg_match('/^(product|variant)-(\d+)$/', $key, $matches)) {
            return null;
        }

        $model = $matches[1] === 'product' ? resolve(Product::class) : resolve(ProductVariant::class);

        return [$model->getMorphClass(), (int) $matches[2]];
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array{0: string, 1: int}>
     */
    private function parseKeys(array $keys): array
    {
        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $this->parseKey($key)])
            ->filter()
            ->all();
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    private function priceables(array $keys): array
    {
        $parsed = $this->parseKeys($keys);
        $productMorph = resolve(Product::class)->getMorphClass();
        $ids = collect($parsed)->groupBy(fn (array $priceable): string => $priceable[0] === $productMorph ? 'product' : 'variant')
            ->map(fn (SupportCollection $group): array => $group->pluck(1)->all());

        $products = resolve(Product::class)::query()
            ->whereIntegerInRaw('id', $ids->get('product', []))
            ->pluck('id')
            ->flip();
        $variants = resolve(ProductVariant::class)::query()
            ->whereIntegerInRaw('id', $ids->get('variant', []))
            ->pluck('product_id', 'id');

        return collect($parsed)
            ->map(fn (array $priceable): ?array => match (true) {
                $priceable[0] === $productMorph && isset($products[$priceable[1]]) => [...$priceable, $priceable[1]],
                $priceable[0] !== $productMorph && isset($variants[$priceable[1]]) => [...$priceable, (int) $variants[$priceable[1]]],
                default => null,
            })
            ->filter()
            ->all();
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array<string, list<Tier>>>
     */
    private function storedLadders(array $keys): array
    {
        $priceables = $this->parseKeys($keys);

        if ($priceables === []) {
            return [];
        }

        $prices = $this->priceList->prices()
            ->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, array_values($priceables)))
            ->with('currency')
            ->orderBy('min_quantity')
            ->get();

        $ladders = [];

        foreach ($prices as $price) {
            $ladders[$this->keyFor($price->priceable_type, $price->priceable_id)][$price->currency->code][] = $this->tier($price->min_quantity, $price->max_quantity, $price->amount, $price->compare_amount);
        }

        return $ladders;
    }

    /**
     * @return Tier
     */
    private function tier(int $min, ?int $max, int $amount, ?int $compare): array
    {
        return ['min_quantity' => $min, 'max_quantity' => $max, 'amount' => $amount, 'compare_amount' => $compare];
    }

    /**
     * @param  list<Tier>  $ladder
     */
    private function baseAmount(array $ladder): ?int
    {
        return collect($ladder)->firstWhere('min_quantity', 1)['amount'] ?? null;
    }

    private function amount(mixed $value, string $code): ?int
    {
        if (! is_string($value)) {
            return 0;
        }

        $value = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $value));

        if ($value === '') {
            return null;
        }

        $noDivision = is_no_division_currency($code);

        if (! preg_match($noDivision ? '/^\d{1,9}$/' : '/^\d{1,6}(\.\d{0,2})?$/', $value)) {
            return 0;
        }

        return $noDivision ? (int) $value : (int) round((float) $value * 100);
    }

    private function format(?int $amount, string $code): string
    {
        if ($amount === null) {
            return '';
        }

        if (is_no_division_currency($code)) {
            return (string) $amount;
        }

        $separator = (new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL))->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return number_format($amount / 100, 2, $separator ?: '.', '');
    }

    /**
     * @param  list<Tier>  $ladder
     * @return list<Tier>
     */
    private function withBaseAmount(array $ladder, ?int $amount): array
    {
        $base = collect($ladder)->firstWhere('min_quantity', 1);
        $rest = array_values(array_filter($ladder, fn (array $tier): bool => $tier['min_quantity'] !== 1));

        if ($amount === null) {
            return $rest;
        }

        return [
            $this->tier(1, $base === null ? (isset($rest[0]) ? $rest[0]['min_quantity'] - 1 : null) : $base['max_quantity'], $amount, $base['compare_amount'] ?? null),
            ...$rest,
        ];
    }

    /**
     * @return list<Tier>
     */
    private function ladder(string $key, string $code): array
    {
        $ladder = $this->ladders[$key][$code] ?? $this->stored[$key][$code] ?? [];

        if (! array_key_exists($code, (array) ($this->cells[$key] ?? []))) {
            return $ladder;
        }

        $amount = $this->amount($this->cells[$key][$code], $code);

        return $amount !== null && $amount < 1 ? $ladder : $this->withBaseAmount($ladder, $amount);
    }

    /**
     * @return array{0: array<string, array<string, list<Tier>>>, 1: array<string, string>}
     */
    private function draft(): array
    {
        $codes = $this->currencies->pluck('code')->all();
        $draft = [];
        $errors = [];

        foreach (array_keys($this->parseKeys(array_unique([...array_keys($this->cells), ...array_keys($this->ladders)]))) as $key) {
            $pairs = array_unique([...array_keys((array) ($this->cells[$key] ?? [])), ...array_keys($this->ladders[$key] ?? [])]);

            foreach (array_intersect($pairs, $codes) as $code) {
                if (array_key_exists($code, (array) ($this->cells[$key] ?? [])) && ($this->amount($this->cells[$key][$code], $code) ?? 1) < 1) {
                    $errors["cells.{$key}.{$code}"] = __('shopper-pricelist::messages.invalid_amount');

                    continue;
                }

                $ladder = $this->ladder($key, $code);

                if ($ladder !== ($this->stored[$key][$code] ?? [])) {
                    $draft[$key][$code] = $ladder;
                }
            }
        }

        return [$draft, $errors];
    }

    /**
     * @param  array{key?: mixed, code?: mixed}  $arguments
     * @return array{0: string, 1: string}
     */
    private function cell(array $arguments): array
    {
        $key = (string) ($arguments['key'] ?? '');
        $code = (string) ($arguments['code'] ?? '');

        abort_if($this->parseKey($key) === null || ! $this->currencies->contains('code', $code), 404);

        return [$key, $code];
    }

    private function priceableName(string $key): string
    {
        [$type, $id] = $this->parseKey($key) ?? abort(404);

        if ($type === resolve(Product::class)->getMorphClass()) {
            return (string) resolve(Product::class)::query()->whereKey($id)->value('name');
        }

        $variant = resolve(ProductVariant::class)::query()->with('product:id,name')->findOrFail($id);

        return $variant->getRelationValue('product')->getAttribute('name').' / '.$variant->name;
    }

    private function quantityRule(string $key): ?PriceListQuantityRule
    {
        [$type, $id] = $this->parseKey($key) ?? abort(404);

        return $this->priceList->quantityRules()
            ->where('priceable_type', $type)
            ->where('priceable_id', $id)
            ->first();
    }

    /**
     * @param  Collection<int, Currency>  $currencies
     * @return array<string, array<string, array{placeholder: string, catalog: ?string, tiers: int}>>
     */
    private function hints(Collection $currencies): array
    {
        $hints = [];

        foreach ($this->products->getCollection() as $product) {
            $productKey = 'product-'.$product->getKey();

            foreach ($currencies as $currency) {
                $hints[$productKey][$currency->code] = $this->hint($productKey, $product, $currency->code, null);
            }

            foreach ($this->variants->get($product->getKey(), collect()) as $variant) {
                foreach ($currencies as $currency) {
                    $hints['variant-'.$variant->getKey()][$currency->code] = $this->hint(
                        'variant-'.$variant->getKey(),
                        $variant,
                        $currency->code,
                        $this->baseAmount($this->ladder($productKey, $currency->code)),
                    );
                }
            }
        }

        return $hints;
    }

    /**
     * @return array{placeholder: string, catalog: ?string, tiers: int}
     */
    private function hint(string $key, Model $priceable, string $code, ?int $inherited): array
    {
        /** @var Price|null $price */
        $price = $priceable->getPrice($code); // @phpstan-ignore method.notFound
        $ladder = $this->ladder($key, $code);
        $extra = count($ladder) - ($this->baseAmount($ladder) === null ? 0 : 1);

        if ($inherited === null && $price !== null && $this->priceList->adjustsPrices()) {
            $inherited = $this->priceList->adjust($price->amount, $code);
        }

        return [
            'placeholder' => $this->format($inherited, $code),
            'catalog' => $price === null ? null : shopper_money_format($price->amount, $code),
            'tiers' => $extra > 0 ? count($ladder) : 0,
        ];
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function remove(array $keys): void
    {
        $this->authorize('products.edit');

        $priceables = $this->priceables($keys);
        $productMorph = resolve(Product::class)->getMorphClass();
        $variantMorph = resolve(ProductVariant::class)->getMorphClass();
        $productIds = collect($priceables)->filter(fn (array $priceable): bool => $priceable[0] === $productMorph)->pluck(1)->all();

        $targets = collect($priceables)
            ->mapWithKeys(fn (array $priceable, string $key): array => [$key => [$priceable[0], $priceable[1]]])
            ->merge(resolve(ProductVariant::class)::query()
                ->whereIntegerInRaw('product_id', $productIds)
                ->pluck('id')
                ->mapWithKeys(fn (mixed $id): array => ['variant-'.$id => [$variantMorph, (int) $id]]))
            ->all();

        $changes = [];

        if ($targets !== []) {
            $changes = DB::transaction(function () use ($targets): array {
                PriceList::query()->whereKey($this->priceList->id)->lockForUpdate()->first();

                $before = $this->storedLadders(array_keys($targets));

                $this->priceList->prices()->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, array_values($targets)))->delete();
                $this->priceList->quantityRules()->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, array_values($targets)))->delete();

                return collect($before)
                    ->flatMap(fn (array $codes, string $key): array => collect($codes)
                        ->map(fn (array $ladder, string $code): array => [
                            'priceable_type' => $targets[$key][0],
                            'priceable_id' => $targets[$key][1],
                            'currency' => $code,
                            'before' => $ladder,
                            'after' => [],
                        ])
                        ->values()
                        ->all())
                    ->all();
            });
        }

        $this->synced(array_column($priceables, 2), $changes);

        $removed = [...array_keys($priceables), ...array_keys($targets)];

        $this->cells = array_diff_key($this->cells, array_flip($removed));
        $this->ladders = array_diff_key($this->ladders, array_flip($removed));
        $this->selected = array_values(array_diff($this->selected, $removed));
        $this->added = array_values(array_diff($this->added, $productIds));

        unset($this->products, $this->variants, $this->stored, $this->quantityRules);

        Notification::make()
            ->title(__('shopper-pricelist::messages.products_removed'))
            ->success()
            ->send();
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  list<array{priceable_type: string, priceable_id: int, currency: string, before: list<Tier>, after: list<Tier>}>  $changes
     */
    private function synced(array $productIds, array $changes): void
    {
        app()->forgetInstance(PriceListLookup::class);

        $productIds = array_values(array_unique($productIds));
        sort($productIds);

        foreach (array_chunk($productIds, CoveredProducts::BATCH) as $chunk) {
            SyncProductPrices::dispatch($chunk);
        }

        if ($changes !== []) {
            PriceListPricesSaved::dispatch($this->priceList, shopper()->auth()->id(), $changes);
        }

        unset($this->stored);
    }
}
