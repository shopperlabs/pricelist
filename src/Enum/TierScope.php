<?php

declare(strict_types=1);

namespace Shopper\PriceList\Enum;

use Shopper\Core\Contracts\HasLabel;

enum TierScope: string implements HasLabel
{
    case Item = 'item';

    case Product = 'product';

    public function getLabel(): string
    {
        return __('shopper-pricelist::messages.tier_scopes.'.$this->value);
    }
}
