<?php

declare(strict_types=1);

namespace Shopper\PriceList\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Laravelcm\LivewireSlideOvers\SlideOverComponent;
use Livewire\Attributes\Locked;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;
use Shopper\Components\Form\NumberStepper;
use Shopper\Components\SlideOverWizard;
use Shopper\Components\Wizard\StepColumn;
use Shopper\Core\Models\Currency;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Contracts\HasRuleForm;
use Shopper\PriceList\Enum\AdjustmentType;
use Shopper\PriceList\Enum\CompareAtMode;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Enum\TierScope;
use Shopper\PriceList\Livewire\Concerns\DescribesPriceList;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\Traits\HandlesAuthorizationExceptions;

/**
 * @property-read Schema $form
 */
class PriceListForm extends SlideOverComponent implements HasActions, HasSchemas
{
    use DescribesPriceList;
    use HandlesAuthorizationExceptions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    private const string NO_ADJUSTMENT = 'none';

    #[Locked]
    public PriceList $priceList;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function panelMaxWidth(): string
    {
        return '3xl';
    }

    public function mount(?PriceList $priceList = null): void
    {
        $this->priceList = $priceList ?? new PriceList;

        $this->authorize($this->priceList->exists ? 'products.edit' : 'products.create');

        $type = $this->priceList->type ?? PriceListType::Sale;

        $this->form->fill([
            'name' => $this->priceList->name,
            'description' => $this->priceList->description,
            'type' => $type->value,
            'status' => ($this->priceList->status ?? PriceListStatus::Draft)->value,
            'starts_at' => $this->priceList->starts_at,
            'ends_at' => $this->priceList->ends_at,
            'priority' => $this->priceList->priority ?? 0,
            'rules' => $this->priceList->rules->pluck('values', 'rule_key')->all(),
            'adjustment_type' => $this->priceList->adjustment_type->value ?? self::NO_ADJUSTMENT,
            'adjustment_value' => $this->priceList->adjustment_value === null ? null : $this->priceList->adjustment_value / 100,
            'rounding_endings' => $this->priceList->rounding_endings ?? [],
            'compare_at_mode' => ($this->priceList->compare_at_mode ?? $type->compareAtMode())->value,
            'tier_scope' => ($this->priceList->tier_scope ?? TierScope::Item)->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                SlideOverWizard::make([
                    StepColumn::make(__('shopper-pricelist::messages.wizard.details'))
                        ->icon(Untitledui::File04)
                        ->schema($this->detailsStep()),
                    StepColumn::make(__('shopper-pricelist::messages.wizard.target'))
                        ->icon(Untitledui::Users03)
                        ->schema($this->targetStep()),
                    StepColumn::make(__('shopper-pricelist::messages.wizard.pricing'))
                        ->icon(Untitledui::CurrencyDollarCircle)
                        ->schema($this->pricingStep()),
                ])
                    ->skippable($this->priceList->exists)
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="submit" wire:loading.attr="disabled">
                            <x-shopper::loader wire:loading wire:target="store" class="text-white" />
                            {{ $label }}
                        </x-filament::button>
                    BLADE, ['label' => $this->priceList->exists ? __('shopper::forms.actions.save') : __('shopper-pricelist::messages.wizard.create')])))
                    ->persistStepInQueryString(),
            ])
            ->statePath('data')
            ->model($this->priceList);
    }

    public function store(): void
    {
        $this->authorize($this->priceList->exists ? 'products.edit' : 'products.create');

        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $type = PriceListType::from($data['type']);
        $adjustment = AdjustmentType::tryFrom($data['adjustment_type']);
        $compareAt = CompareAtMode::from($data['compare_at_mode']);
        $wasCreated = ! $this->priceList->exists;

        DB::transaction(function () use ($data, $type, $adjustment, $compareAt): void {
            $this->priceList->fill([
                'name' => $data['name'],
                'description' => $data['description'],
                'type' => $type,
                'status' => PriceListStatus::from($data['status']),
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'priority' => (int) $data['priority'],
                'adjustment_type' => $adjustment,
                'adjustment_value' => $adjustment === null ? null : (int) round((float) $data['adjustment_value'] * 100),
                'rounding_endings' => $adjustment === null ? null : $this->roundingEndings($data['rounding_endings'] ?? []),
                'compare_at_mode' => $compareAt === $type->compareAtMode() ? null : $compareAt,
                'tier_scope' => TierScope::from($data['tier_scope']),
            ])->save();

            $this->saveRules($data['rules'] ?? []);
        });

        Notification::make()
            ->title(__($wasCreated ? 'shopper-pricelist::messages.created' : 'shopper-pricelist::messages.updated'))
            ->success()
            ->send();

        if ($wasCreated) {
            $this->redirectRoute('shopper.price-lists.show', ['priceList' => $this->priceList], navigate: true);

            return;
        }

        $this->dispatch('pricelist.saved');
        $this->closePanel();
    }

    public function render(): View
    {
        return view('shopper-pricelist::livewire.slide-overs.price-list-form');
    }

    /**
     * @return array<int, Component>
     */
    private function detailsStep(): array
    {
        $timezone = $this->timezone();

        return [
            TextInput::make('name')
                ->label(__('shopper::forms.label.name'))
                ->maxLength(255)
                ->required(),
            Textarea::make('description')
                ->label(__('shopper::forms.label.description'))
                ->rows(2),
            Radio::make('type')
                ->label(__('shopper::forms.label.type'))
                ->options([
                    PriceListType::Sale->value => PriceListType::Sale->getLabel(),
                    PriceListType::Override->value => PriceListType::Override->getLabel(),
                ])
                ->descriptions([
                    PriceListType::Sale->value => __('shopper-pricelist::messages.type_descriptions.sale'),
                    PriceListType::Override->value => __('shopper-pricelist::messages.type_descriptions.override'),
                ])
                ->columns()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get, string $state): void {
                    $set('compare_at_mode', PriceListType::from($state)->compareAtMode()->value);

                    if ($state === PriceListType::Sale->value && $get('adjustment_type') === AdjustmentType::Increase->value) {
                        $set('adjustment_type', self::NO_ADJUSTMENT);
                    }
                }),
            Radio::make('status')
                ->label(__('shopper::forms.label.status'))
                ->options(fn (): array => collect(PriceListStatus::cases())
                    ->filter(fn (PriceListStatus $status): bool => $status !== PriceListStatus::Archived || $this->priceList->status === PriceListStatus::Archived)
                    ->mapWithKeys(fn (PriceListStatus $status): array => [$status->value => $status->getLabel()])
                    ->all())
                ->inline()
                ->required(),
            Grid::make()
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label(__('shopper-pricelist::messages.starts_at'))
                        ->timezone($timezone)
                        ->hint($timezone)
                        ->native(false),
                    DateTimePicker::make('ends_at')
                        ->label(__('shopper-pricelist::messages.ends_at'))
                        ->timezone($timezone)
                        ->hint($timezone)
                        ->native(false)
                        ->after(fn (Get $get): ?string => filled($get('starts_at')) ? 'starts_at' : null),
                ]),
            NumberStepper::make('priority')
                ->label(__('shopper-pricelist::messages.priority'))
                ->helperText(__('shopper-pricelist::messages.priority_help'))
                ->integer()
                ->minValue(0)
                ->required(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function targetStep(): array
    {
        return [
            Text::make(__('shopper-pricelist::messages.target_intro')),
            ...collect(app(ApplicabilityRuleManager::class)->all())
                ->filter(fn (ApplicabilityRule $rule): bool => $rule instanceof HasRuleForm)
                ->map(fn (HasRuleForm $rule): Component => $rule->formField()->live())
                ->values()
                ->all(),
            Callout::make(__('shopper-pricelist::messages.target_summary'))
                ->description(fn (): string => $this->summarizeTarget($this->form, $this->data['rules'] ?? []))
                ->info(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function pricingStep(): array
    {
        return [
            Radio::make('adjustment_type')
                ->label(__('shopper-pricelist::messages.adjustment'))
                ->helperText(__('shopper-pricelist::messages.adjustment_help'))
                ->options(fn (Get $get): array => [
                    self::NO_ADJUSTMENT => __('shopper-pricelist::messages.adjustments.none'),
                    AdjustmentType::Decrease->value => AdjustmentType::Decrease->getLabel(),
                    ...($get('type') === PriceListType::Override->value ? [AdjustmentType::Increase->value => AdjustmentType::Increase->getLabel()] : []),
                ])
                ->inline()
                ->required()
                ->live(),
            TextInput::make('adjustment_value')
                ->label(__('shopper-pricelist::messages.adjustment_value'))
                ->numeric()
                ->suffix('%')
                ->step(0.01)
                ->minValue(0.01)
                ->maxValue(fn (Get $get): float => AdjustmentType::from($get('adjustment_type'))->maximum() / 100)
                ->required()
                ->live(onBlur: true)
                ->visible(fn (Get $get): bool => $get('adjustment_type') !== self::NO_ADJUSTMENT),
            Grid::make(3)
                ->schema(fn (): array => $this->roundingFields())
                ->visible(fn (Get $get): bool => $get('adjustment_type') !== self::NO_ADJUSTMENT),
            Radio::make('compare_at_mode')
                ->label(__('shopper-pricelist::messages.compare_at_mode'))
                ->options(collect(CompareAtMode::cases())->mapWithKeys(fn (CompareAtMode $mode): array => [$mode->value => $mode->getLabel()])->all())
                ->descriptions(collect(CompareAtMode::cases())->mapWithKeys(fn (CompareAtMode $mode): array => [$mode->value => __('shopper-pricelist::messages.compare_at_mode_descriptions.'.$mode->value)])->all())
                ->columns()
                ->gridDirection(GridDirection::Row)
                ->required(),
            Radio::make('tier_scope')
                ->label(__('shopper-pricelist::messages.tier_scope'))
                ->options([
                    TierScope::Item->value => TierScope::Item->getLabel(),
                    TierScope::Product->value => TierScope::Product->getLabel(),
                ])
                ->descriptions([
                    TierScope::Item->value => __('shopper-pricelist::messages.tier_scope_descriptions.item'),
                    TierScope::Product->value => __('shopper-pricelist::messages.tier_scope_descriptions.product'),
                ])
                ->columns()
                ->required(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function roundingFields(): array
    {
        return Currency::query()
            ->whereIn('id', shopper_setting('currencies') ?? [])
            ->orderBy('code')
            ->pluck('code')
            ->map(fn (string $code): Component => TextInput::make("rounding_endings.{$code}")
                ->label(__('shopper-pricelist::messages.rounding_ending', ['currency' => $code]))
                ->integer()
                ->minValue(0)
                ->maxValue(9999)
                ->live(onBlur: true)
                ->helperText(fn (Get $get): ?string => $this->roundingExample($get, $code)))
            ->all();
    }

    private function roundingExample(Get $get, string $code): ?string
    {
        $adjustment = AdjustmentType::tryFrom((string) $get('adjustment_type'));
        $value = (int) round((float) $get('adjustment_value') * 100);
        $ending = $get("rounding_endings.{$code}");

        if ($adjustment === null || $value < 1 || $value > $adjustment->maximum() || ! is_numeric($ending)) {
            return null;
        }

        $list = new PriceList([
            'adjustment_type' => $adjustment,
            'adjustment_value' => $value,
            'rounding_endings' => [$code => (int) $ending],
        ]);

        return __('shopper-pricelist::messages.rounding_example', [
            'before' => shopper_money_format(10000, $code),
            'after' => shopper_money_format($list->adjust(10000, $code), $code),
        ]);
    }

    /**
     * @param  array<string, mixed>  $endings
     * @return array<string, int>|null
     */
    private function roundingEndings(array $endings): ?array
    {
        $endings = collect($endings)
            ->filter(fn (mixed $ending): bool => is_numeric($ending))
            ->map(fn (mixed $ending): int => (int) $ending)
            ->all();

        return $endings === [] ? null : $endings;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function saveRules(array $values): void
    {
        foreach (app(ApplicabilityRuleManager::class)->all() as $key => $rule) {
            if (! $rule instanceof HasRuleForm) {
                continue;
            }

            $ruleValues = array_values(array_filter((array) ($values[$key] ?? [])));

            if ($ruleValues === []) {
                $this->priceList->rules()->firstWhere('rule_key', $key)?->delete();

                continue;
            }

            $this->priceList->rules()->updateOrCreate(['rule_key' => $key], ['values' => $ruleValues]);
        }
    }
}
