<?php

declare(strict_types=1);

namespace Shopper\PriceList\Observers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Contracts\ProductVariant;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListQuantityRule;
use Shopper\PriceList\Pricing\PriceListLookup;

final class CatalogItemObserver
{
    public function deleting(Model $item): void
    {
        if (method_exists($item, 'isForceDeleting') && ! $item->isForceDeleting()) {
            return;
        }

        $keys = [[$item->getMorphClass(), (int) $item->getKey()]];

        if (! $item instanceof ProductVariant) {
            $variant = resolve(ProductVariant::class);

            foreach ($variant::query()->where('product_id', $item->getKey())->pluck('id') as $variantId) {
                $keys[] = [$variant->getMorphClass(), (int) $variantId];
            }

            DB::table(shopper_table('price_list_product_prices'))->where('product_id', $item->getKey())->delete();
        }

        $constrain = fn (Builder $query) => PriceListLookup::wherePriceables($query, $keys);

        PriceListPrice::query()->where($constrain)->delete();
        PriceListQuantityRule::query()->where($constrain)->delete();
        PriceHistory::query()->where($constrain)->delete();
    }

    public function deleted(Model $item): void
    {
        if ($item instanceof ProductVariant) {
            SyncProductPrices::dispatch([(int) $item->getAttribute('product_id')])->afterCommit();
        }
    }
}
