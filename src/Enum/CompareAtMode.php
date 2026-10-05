<?php

declare(strict_types=1);

namespace Shopper\PriceList\Enum;

use Shopper\Core\Contracts\HasLabel;

enum CompareAtMode: string implements HasLabel
{
    case Keep = 'keep';

    case Base = 'base';

    case Lowest30Days = 'lowest_30_days';

    case None = 'none';

    public function getLabel(): string
    {
        return __('shopper-pricelist::messages.compare_at_modes.'.$this->value);
    }
}
