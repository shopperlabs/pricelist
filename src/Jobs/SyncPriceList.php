<?php

declare(strict_types=1);

namespace Shopper\PriceList\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Sync\CoveredProducts;

final class SyncPriceList implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public int $priceListId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->priceListId;
    }

    public function handle(): void
    {
        $list = PriceList::query()->find($this->priceListId);
        $adjusts = $list?->adjustsPrices() ?? false;
        $indexed = $adjusts ? [] : DB::table(shopper_table('price_list_product_prices'))
            ->where('price_list_id', $this->priceListId)
            ->distinct()
            ->pluck('product_id')
            ->map(intval(...))
            ->all();

        if ($adjusts) {
            CoveredProducts::all(fn (array $productIds) => SyncProductPrices::dispatch($productIds));

            return;
        }

        $productIds = array_unique([...$indexed, ...($list === null ? [] : CoveredProducts::priced($list))]);
        sort($productIds);

        foreach (array_chunk($productIds, CoveredProducts::BATCH) as $chunk) {
            SyncProductPrices::dispatch($chunk);
        }
    }
}
