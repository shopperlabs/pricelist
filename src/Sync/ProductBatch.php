<?php

declare(strict_types=1);

namespace Shopper\PriceList\Sync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Enum\ProductType;
use Shopper\Core\Models\Contracts\Product;
use Shopper\Core\Models\Currency;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Pricing\PriceListLookup;

final readonly class ProductBatch
{
    /**
     * @param  Collection<int, covariant Model>  $products
     */
    private function __construct(
        public Collection $products,
    ) {}

    /**
     * @param  list<int>  $productIds
     */
    public static function load(array $productIds): self
    {
        return new self(resolve(Product::class)::query()
            ->with(['prices.currency', 'variants.prices.currency'])
            ->whereKey($productIds)
            ->get());
    }

    /**
     * @return BaseCollection<int, Model&Priceable>
     */
    public function items(Model $product): BaseCollection
    {
        return $product->getAttribute('type') === ProductType::Variant
            ? $product->getRelation('variants')->toBase()
            : collect([$product]);
    }

    /**
     * @return BaseCollection<int, Model&Priceable>
     */
    public function allItems(): BaseCollection
    {
        return $this->products->toBase()->flatMap($this->items(...))->values();
    }

    /**
     * @return array<int, string>
     */
    public function currencies(): array
    {
        $catalog = $this->allItems()
            ->flatMap(fn (Model $item): BaseCollection => $item->getRelation('prices')->pluck('currency_id'));

        $keys = $this->products->toBase()->concat($this->allItems())
            ->map(fn (Model $model): array => [$model->getMorphClass(), (int) $model->getKey()])
            ->all();

        $listed = PriceListPrice::query()
            ->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, $keys))
            ->distinct()
            ->pluck('currency_id');

        return Currency::query()
            ->whereKey($catalog->concat($listed)->unique()->all())
            ->pluck('code', 'id')
            ->all();
    }
}
