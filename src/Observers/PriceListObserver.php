<?php

declare(strict_types=1);

namespace Shopper\PriceList\Observers;

use Illuminate\Support\Arr;
use Shopper\PriceList\Events\PriceListDeleted;
use Shopper\PriceList\Events\PriceListSaved;
use Shopper\PriceList\Jobs\SyncPriceList;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Pricing\ActivePriceLists;

final class PriceListObserver
{
    private const array PRICING = [
        'type',
        'status',
        'starts_at',
        'ends_at',
        'priority',
        'adjustment_type',
        'adjustment_value',
        'rounding_endings',
    ];

    public function created(PriceList $priceList): void
    {
        ActivePriceLists::flush();
        SyncPriceList::dispatch($priceList->id)->afterCommit();
        PriceListSaved::dispatch($priceList, shopper()->auth()->id(), [], $priceList->getAttributes());
    }

    public function updated(PriceList $priceList): void
    {
        ActivePriceLists::flush();

        if ($priceList->wasChanged(self::PRICING)) {
            SyncPriceList::dispatch($priceList->id)->afterCommit();
        }

        $after = $priceList->getChanges();

        PriceListSaved::dispatch($priceList, shopper()->auth()->id(), Arr::only($priceList->getRawOriginal(), array_keys($after)), $after);
    }

    public function deleted(PriceList $priceList): void
    {
        ActivePriceLists::flush();
        SyncPriceList::dispatch($priceList->id)->afterCommit();
        PriceListDeleted::dispatch($priceList, shopper()->auth()->id());
    }
}
