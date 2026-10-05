<?php

declare(strict_types=1);

namespace Shopper\PriceList\Contracts;

use Shopper\Core\Pricing\PricingContext;

interface ApplicabilityRule
{
    public function key(): string;

    public function label(): string;

    /**
     * @param  array<int, mixed>  $values
     */
    public function passes(array $values, PricingContext $context): bool;
}
