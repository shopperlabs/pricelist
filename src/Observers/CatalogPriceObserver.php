<?php

declare(strict_types=1);

namespace Shopper\PriceList\Observers;

use Shopper\Core\Models\Price;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Sync\CoveredProducts;

final class CatalogPriceObserver
{
    private const array PRICING = [
        'priceable_type',
        'priceable_id',
        'currency_id',
        'amount',
    ];

    public function saved(Price $price): void
    {
        if (! $price->wasRecentlyCreated && ! $price->wasChanged(self::PRICING)) {
            return;
        }

        $this->sync($price->priceable_type, $price->priceable_id);

        if ($price->wasChanged(['priceable_type', 'priceable_id'])) {
            $this->sync((string) $price->getOriginal('priceable_type'), (int) $price->getOriginal('priceable_id'));
        }
    }

    public function deleted(Price $price): void
    {
        $this->sync($price->priceable_type, $price->priceable_id);
    }

    private function sync(string $type, int $id): void
    {
        $productId = CoveredProducts::ofPriceable($type, $id);

        if ($productId !== null) {
            SyncProductPrices::dispatch([$productId])->afterCommit();
        }
    }
}
