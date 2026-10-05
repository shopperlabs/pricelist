<?php

declare(strict_types=1);

namespace Shopper\PriceList\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Shopper\PriceList\Models\PriceList;

final class PriceListSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public PriceList $priceList,
        public int|string|null $actorId,
        public array $before,
        public array $after,
    ) {}
}
