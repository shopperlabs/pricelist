<?php

declare(strict_types=1);

namespace Shopper\PriceList\Sync;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Contracts\PreloadsPrices;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\ResolvedPrice;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Pricing\ActivePriceLists;
use Shopper\PriceList\Pricing\ApplicableLists;
use Shopper\PriceList\Pricing\PriceListLookup;
use Shopper\PriceList\Pricing\PriceListOffer;

final readonly class ProductPriceIndexer
{
    public function __construct(
        private PriceResolver $prices,
    ) {}

    /**
     * @param  list<int>  $productIds
     */
    public function index(array $productIds, ProductBatch $batch): void
    {
        $lists = resolve(ActivePriceLists::class)->all();
        $rows = $lists->isEmpty() ? [] : $this->rows($batch, $lists);

        DB::transaction(function () use ($productIds, $rows): void {
            $index = DB::table(shopper_table('price_list_product_prices'));

            foreach ($index->clone()->whereIn('product_id', $productIds)->pluck('id')->chunk(1000) as $ids) {
                $index->clone()->whereIn('id', $ids)->delete();
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $index->clone()->insert($chunk);
            }
        }, 3);
    }

    /**
     * @param  Collection<int, PriceList>  $lists
     * @return list<array<string, int|null>>
     */
    private function rows(ProductBatch $batch, Collection $lists): array
    {
        $applicable = resolve(ApplicableLists::class);
        $lookup = resolve(PriceListLookup::class);
        $items = $batch->allItems();
        $rows = [];

        foreach ($batch->currencies() as $currencyId => $code) {
            $context = new PricingContext(currencyCode: $code);
            $lookup->preload($items, $currencyId);
            $bases = $applicable->withoutLists(fn (): array => $this->bases($items, $context));

            foreach ($batch->products as $product) {
                $productItems = $batch->items($product);
                $priced = $productItems
                    ->flatMap(fn (Model $item): array => array_filter([$lookup->key($item), $lookup->parentKey($item)]))
                    ->flatMap(fn (array $key): array => $lookup->prices($key, $currencyId)->keys()->all())
                    ->map(intval(...))
                    ->all();

                $offers = [];

                foreach ($lists as $list) {
                    if (! $list->adjustsPrices() && ! in_array($list->id, $priced, true)) {
                        continue;
                    }

                    foreach ($productItems as $item) {
                        $offer = PriceListOffer::of($lookup, $list, $item, $bases[$item->getMorphClass().':'.$item->getKey()], $context, $currencyId);

                        if ($offer !== null) {
                            $offers[] = $this->row($list->id, $product, $item, $currencyId, $offer->amount);
                        }
                    }
                }

                if ($offers === []) {
                    continue;
                }

                array_push($rows, ...$offers);

                foreach ($productItems as $item) {
                    $rows[] = $this->row(null, $product, $item, $currencyId, $bases[$item->getMorphClass().':'.$item->getKey()]?->amount);
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, int|null>
     */
    private function row(?int $listId, Model $product, Model $item, int $currencyId, ?int $amount): array
    {
        return [
            'price_list_id' => $listId,
            'product_id' => (int) $product->getKey(),
            'item_id' => (int) $item->getKey(),
            'currency_id' => $currencyId,
            'amount' => $amount,
        ];
    }

    /**
     * @param  BaseCollection<int, Model&Priceable>  $items
     * @return array<string, ResolvedPrice|null>
     */
    private function bases(BaseCollection $items, PricingContext $context): array
    {
        if ($this->prices instanceof PreloadsPrices) {
            $this->prices->preload($items, $context);
        }

        return $items
            ->mapWithKeys(fn (Model&Priceable $item): array => [$item->getMorphClass().':'.$item->getKey() => $this->prices->resolve($item, $context)])
            ->all();
    }
}
