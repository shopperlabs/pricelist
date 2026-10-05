<?php

declare(strict_types=1);

namespace Shopper\PriceList\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Sync\CoveredProducts;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopper:pricelist:record-prices')]
final class RecordPricesCommand extends Command
{
    public const string MARKER = 'shopper.pricelist.recorded_until';

    protected $signature = 'shopper:pricelist:record-prices';

    protected $description = 'Record the public prices changed by price lists starting or ending since the last run';

    public function handle(): int
    {
        $now = now();
        $marker = Cache::get(self::MARKER);
        $since = is_int($marker) ? $now->copy()->setTimestamp($marker) : $now->copy()->subDay();

        $lists = PriceList::query()
            ->where('status', PriceListStatus::Active)
            ->where(fn (Builder $query): Builder => $query
                ->whereBetween('starts_at', [$since, $now])
                ->orWhereBetween('ends_at', [$since, $now]))
            ->get();

        foreach ($lists as $list) {
            CoveredProducts::each($list, fn (array $productIds) => SyncProductPrices::dispatch($productIds));
        }

        Cache::forever(self::MARKER, $now->getTimestamp());

        return self::SUCCESS;
    }
}
