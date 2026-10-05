<?php

declare(strict_types=1);

namespace Shopper\PriceList\Tests\Stubs;

use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Contracts\ApplicabilityRule;

final class UnavailableRule implements ApplicabilityRule
{
    public const string KEY = 'unavailable';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Unavailable';
    }

    public function passes(array $values, PricingContext $context): bool
    {
        return true;
    }
}
