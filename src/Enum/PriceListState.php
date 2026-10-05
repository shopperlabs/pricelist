<?php

declare(strict_types=1);

namespace Shopper\PriceList\Enum;

use Shopper\Core\Contracts\HasColor;
use Shopper\Core\Contracts\HasIcon;
use Shopper\Core\Contracts\HasLabel;

enum PriceListState: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';

    case Scheduled = 'scheduled';

    case Active = 'active';

    case Expired = 'expired';

    case Archived = 'archived';

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Archived => 'gray',
            self::Scheduled => 'info',
            self::Active => 'success',
            self::Expired => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'untitledui-file-04',
            self::Scheduled => 'untitledui-clock',
            self::Active => 'untitledui-check-circle',
            self::Expired => 'untitledui-hourglass-03',
            self::Archived => 'untitledui-archive',
        };
    }

    public function getLabel(): string
    {
        return __('shopper-pricelist::messages.states.'.$this->value);
    }
}
