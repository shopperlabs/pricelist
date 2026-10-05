<?php

declare(strict_types=1);

namespace Shopper\PriceList\Enum;

enum PriceSource: string
{
    case Fixed = 'fixed';

    case Adjustment = 'adjustment';
}
