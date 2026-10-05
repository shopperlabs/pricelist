<?php

declare(strict_types=1);

namespace Shopper\PriceList\Pricing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Models\PriceList;

final class ActivePriceLists
{
    /** @var Collection<int, PriceList>|null */
    private ?Collection $lists = null;

    public static function flush(): void
    {
        DB::afterCommit(self::forgetInstances(...));
        DB::afterRollBack(self::forgetInstances(...));
    }

    public static function forgetInstances(): void
    {
        app()->forgetInstance(self::class);
        app()->forgetInstance(ApplicableLists::class);
        app()->forgetInstance(PriceListLookup::class);
    }

    /**
     * @return Collection<int, PriceList>
     */
    public function all(): Collection
    {
        return $this->lists ??= PriceList::query()
            ->with('rules')
            ->where('status', PriceListStatus::Active)
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get();
    }
}
