<?php

declare(strict_types=1);

namespace Shopper\PriceList\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Shopper\PriceList\Pricing\ActivePriceLists;
use Shopper\PriceList\Sync\ProductBatch;
use Shopper\PriceList\Sync\ProductPriceIndexer;
use Shopper\PriceList\Sync\PublicPriceRecorder;

final class SyncProductPrices implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const int MAX_RESOLUTIONS = 20_000;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  list<int>  $productIds
     */
    public function __construct(
        public array $productIds,
    ) {}

    public function uniqueId(): string
    {
        return md5(implode(',', $this->productIds));
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        if ($this->job instanceof SyncJob) {
            return [];
        }

        return array_map($this->overlapping(...), $this->productIds);
    }

    public function handle(ProductPriceIndexer $indexer, PublicPriceRecorder $recorder): void
    {
        $locks = [];

        try {
            if ($this->job instanceof SyncJob) {
                foreach (collect($this->productIds)->sort() as $productId) {
                    $overlapping = $this->overlapping($productId);
                    $lock = Cache::lock($overlapping->getLockKey($this), $overlapping->expiresAfter);
                    $lock->block($overlapping->expiresAfter);
                    $locks[] = $lock;
                }

                ActivePriceLists::forgetInstances();
            }

            $batch = ProductBatch::load($this->productIds);
            $chunks = (int) ceil($recorder->resolutions($batch) / self::MAX_RESOLUTIONS);

            if ($chunks < 2 || count($this->productIds) < 2) {
                $indexer->index($this->productIds, $batch);
                $recorder->record($batch);

                return;
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        foreach (array_chunk($this->productIds, (int) ceil(count($this->productIds) / $chunks)) as $productIds) {
            $this->job instanceof SyncJob ? self::dispatchSync($productIds) : self::dispatch($productIds);
        }
    }

    private function overlapping(int $productId): WithoutOverlapping
    {
        return (new WithoutOverlapping((string) $productId))->releaseAfter(10)->expireAfter(300);
    }
}
