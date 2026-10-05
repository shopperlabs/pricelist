<?php

declare(strict_types=1);

namespace Shopper\PriceList\Enum;

use Shopper\Core\Contracts\HasLabel;

enum AdjustmentType: string implements HasLabel
{
    case Decrease = 'decrease';

    case Increase = 'increase';

    public function maximum(): int
    {
        return match ($this) {
            self::Decrease => 9999,
            self::Increase => 10000,
        };
    }

    public function getLabel(): string
    {
        return __('shopper-pricelist::messages.adjustments.'.$this->value);
    }
}
