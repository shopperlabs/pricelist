<?php

declare(strict_types=1);

namespace Shopper\PriceList\Console;

use Illuminate\Console\Command;
use Shopper\PriceList\Jobs\SyncProductPrices;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Sync\CoveredProducts;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopper:pricelist:rebuild-index')]
final class RebuildIndexCommand extends Command
{
    protected $signature = 'shopper:pricelist:rebuild-index';

    protected $description = 'Rebuild the price list prices used to sort, filter and range product listings';

    public function handle(): int
    {
        if (! app('shopper')->hasAddon('pricelist') && PriceList::query()->exists()) {
            $this->components->error('Register and enable PriceListAddon before rebuilding the index, or it would record catalog prices over your price lists.');

            return self::FAILURE;
        }

        CoveredProducts::all(fn (array $productIds) => SyncProductPrices::dispatchSync($productIds));

        $this->components->info('Listing price index rebuilt.');

        return self::SUCCESS;
    }
}
