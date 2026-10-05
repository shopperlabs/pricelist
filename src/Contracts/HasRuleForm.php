<?php

declare(strict_types=1);

namespace Shopper\PriceList\Contracts;

use Filament\Schemas\Components\Component;

interface HasRuleForm
{
    public function formField(): Component;
}
