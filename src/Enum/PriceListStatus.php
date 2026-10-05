<?php

declare(strict_types=1);

namespace Shopper\PriceList\Enum;

use Shopper\Core\Contracts\HasLabel;

enum PriceListStatus: string implements HasLabel
{
    case Draft = 'draft';

    case Active = 'active';

    case Archived = 'archived';

    public function getLabel(): string
    {
        return __('shopper-pricelist::messages.statuses.'.$this->value);
    }
}
