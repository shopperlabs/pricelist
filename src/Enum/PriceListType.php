<?php

declare(strict_types=1);

namespace Shopper\PriceList\Enum;

use Shopper\Core\Contracts\HasLabel;

enum PriceListType: string implements HasLabel
{
    case Sale = 'sale';

    case Override = 'override';

    public function getLabel(): string
    {
        return __('shopper-pricelist::messages.types.'.$this->value);
    }

    public function compareAtMode(): CompareAtMode
    {
        return match ($this) {
            self::Sale => CompareAtMode::Lowest30Days,
            self::Override => CompareAtMode::None,
        };
    }
}
