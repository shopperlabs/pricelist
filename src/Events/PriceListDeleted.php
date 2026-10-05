<?php

declare(strict_types=1);

namespace Shopper\PriceList\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Shopper\PriceList\Models\PriceList;

final class PriceListDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public PriceList $priceList,
        public int|string|null $actorId,
    ) {}
}
