<?php

declare(strict_types=1);

namespace Shopper\PriceList\Observers;

use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Pricing\PriceListLookup;
use Shopper\PriceList\Sync\CoveredProducts;

final class PriceListPriceObserver
{
    public function saved(PriceListPrice $price): void
    {
        if (! $price->wasRecentlyCreated && ! $price->wasChanged()) {
            return;
        }

        $this->sync($price->priceable_type, $price->priceable_id);

        if ($price->wasChanged(['priceable_type', 'priceable_id'])) {
            $this->sync((string) $price->getOriginal('priceable_type'), (int) $price->getOriginal('priceable_id'));
        }
    }

    public function deleted(PriceListPrice $price): void
    {
        $this->sync($price->priceable_type, $price->priceable_id);
    }

    private function sync(string $type, int $id): void
    {
        app()->forgetInstance(PriceListLookup::class);

        $productId = CoveredProducts::ofPriceable($type, $id);

        if ($productId !== null) {
            SyncProductPrices::dispatch([$productId])->afterCommit();
        }
    }
}
