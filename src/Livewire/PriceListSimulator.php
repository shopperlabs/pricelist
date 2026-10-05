<?php

declare(strict_types=1);

namespace Shopper\PriceList\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravelcm\LivewireSlideOvers\SlideOverComponent;
use Livewire\Attributes\Locked;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Models\Contracts\Channel;
use Shopper\Core\Models\Contracts\Product;
use Shopper\Core\Models\Contracts\ProductVariant;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Zone;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\ResolvedPrice;
use Shopper\PriceList\Enum\PriceSource;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Rules\CustomerRule;
use Shopper\Traits\HandlesAuthorizationExceptions;

/**
 * @property-read Schema $form
 */
class PriceListSimulator extends SlideOverComponent implements HasActions, HasSchemas
{
    use HandlesAuthorizationExceptions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public PriceList $priceList;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{amount: string|null, compare: string|null, list: string|null, source: string|null}|null */
    #[Locked]
    public ?array $result = null;

    public static function panelMaxWidth(): string
    {
        return '2xl';
    }

    public function mount(PriceList $priceList): void
    {
        $this->authorize('products.browse');

        $this->priceList = $priceList;

        $this->form->fill([
            'currency' => shopper_currency(),
            'quantity' => 1,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $customers = new CustomerRule;

        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        Select::make('customer')
                            ->label(__('shopper-pricelist::messages.simulator.customer'))
                            ->placeholder(__('shopper-pricelist::messages.simulator.anonymous'))
                            ->searchable()
                            ->getSearchResultsUsing($customers->search(...))
                            ->getOptionLabelUsing(fn (int|string $value): ?string => $customers->optionLabels([$value])[$value] ?? null)
                            ->columnSpanFull(),
                        Select::make('zone')
                            ->label(__('shopper-pricelist::messages.simulator.zone'))
                            ->options(fn (): array => Zone::query()->enabled()->pluck('name', 'id')->all()),
                        Select::make('channel')
                            ->label(__('shopper-pricelist::messages.simulator.channel'))
                            ->options(fn (): array => resolve(Channel::class)::query()->enabled()->pluck('name', 'id')->all()),
                        Select::make('currency')
                            ->label(__('shopper::forms.label.currency'))
                            ->options(fn (): array => Currency::query()
                                ->whereIn('id', shopper_setting('currencies') ?? [])
                                ->orderBy('code')
                                ->pluck('code', 'code')
                                ->all())
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('quantity')
                            ->label(__('shopper::forms.label.quantity'))
                            ->integer()
                            ->minValue(1)
                            ->required(),
                        Select::make('product')
                            ->label(__('shopper-pricelist::messages.product_variant'))
                            ->searchable()
                            ->getSearchResultsUsing($this->searchPriceables(...))
                            ->getOptionLabelUsing(fn (string $value): string => $this->priceableLabel($value))
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function simulate(): void
    {
        $data = $this->form->getState();

        $price = resolve(PriceResolver::class)->resolve($this->priceable($data['product']), new PricingContext(
            currencyCode: $data['currency'],
            customerId: filled($data['customer']) ? (int) $data['customer'] : null,
            quantity: (int) $data['quantity'],
            channelId: filled($data['channel']) ? (int) $data['channel'] : null,
            zoneId: filled($data['zone']) ? (int) $data['zone'] : null,
        ));

        $this->result = $this->describe($price);
    }

    public function render(): View
    {
        return view('shopper-pricelist::livewire.slide-overs.price-list-simulator');
    }

    /**
     * @return array{amount: string|null, compare: string|null, list: string|null, source: string|null}
     */
    private function describe(?ResolvedPrice $price): array
    {
        /** @var array{id: string, name: string}|null $list */
        $list = $price?->meta['price_list'] ?? null;

        return [
            'amount' => $price !== null ? shopper_money_format($price->amount, $price->currencyCode) : null,
            'compare' => $price?->compareAmount !== null ? shopper_money_format($price->compareAmount, $price->currencyCode) : null,
            'list' => match (true) {
                $list === null => null,
                $list['id'] === $this->priceList->public_id => __('shopper-pricelist::messages.simulator.this_list', ['name' => $list['name']]),
                default => $list['name'],
            },
            'source' => $price !== null && $list !== null ? $this->source($price->meta) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function source(array $meta): string
    {
        if ($meta['source'] === PriceSource::Adjustment->value) {
            return __('shopper-pricelist::messages.simulator.source_adjustment');
        }

        /** @var array{0: int, 1: int|null} $tier */
        $tier = $meta['tier'];

        return collect([
            __('shopper-pricelist::messages.simulator.source_fixed'),
            $meta['level'] === resolve(Product::class)->getMorphClass()
                ? __('shopper-pricelist::messages.simulator.level_product')
                : __('shopper-pricelist::messages.simulator.level_variant'),
            match (true) {
                $tier[1] !== null => __('shopper-pricelist::messages.simulator.tier_range', ['min' => $tier[0], 'max' => $tier[1]]),
                $tier[0] > 1 => __('shopper-pricelist::messages.simulator.tier_from', ['min' => $tier[0]]),
                default => null,
            },
        ])->filter()->implode(', ');
    }

    /**
     * @return array<string, string>
     */
    private function searchPriceables(string $search): array
    {
        $products = resolve(Product::class)::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('sku', "%{$search}%"))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Model $product): array => ['product-'.$product->getKey() => (string) $product->getAttribute('name')]);

        $variants = resolve(ProductVariant::class)::query()
            ->with('product:id,name')
            ->where(fn (Builder $query): Builder => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('sku', "%{$search}%")
                ->orWhereHas('product', fn (Builder $query): Builder => $query->whereLike('name', "%{$search}%")))
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Model $variant): array => ['variant-'.$variant->getKey() => $this->variantLabel($variant)]);

        return $products->merge($variants)->all();
    }

    private function priceableLabel(string $key): string
    {
        $priceable = $this->priceable($key);

        return $priceable instanceof ProductVariant ? $this->variantLabel($priceable) : (string) $priceable->getAttribute('name');
    }

    private function variantLabel(Model $variant): string
    {
        return $variant->getRelationValue('product')->getAttribute('name').' / '.$variant->getAttribute('name');
    }

    /**
     * @return Model&Priceable
     */
    private function priceable(string $key): Model
    {
        [$type, $id] = explode('-', $key, 2) + [1 => ''];

        return match ($type) {
            'product' => resolve(Product::class)::query()->findOrFail($id),
            'variant' => resolve(ProductVariant::class)::query()->with('product:id,name')->findOrFail($id),
            default => abort(404),
        };
    }
}
