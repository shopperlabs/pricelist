<?php

declare(strict_types=1);

namespace Shopper\PriceList\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Shopper\PriceList\Models\PriceList;

final class PriceListPricesSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{priceable_type: string, priceable_id: int, currency: string, before: list<array{min_quantity: int, max_quantity: ?int, amount: int, compare_amount: ?int}>, after: list<array{min_quantity: int, max_quantity: ?int, amount: int, compare_amount: ?int}>}>  $changes
     */
    public function __construct(
        public PriceList $priceList,
        public int|string|null $actorId,
        public array $changes,
    ) {}
}
