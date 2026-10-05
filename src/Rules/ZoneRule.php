<?php

declare(strict_types=1);

namespace Shopper\PriceList\Rules;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Shopper\Core\Models\Zone;
use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Contracts\HasRuleForm;

final class ZoneRule implements ApplicabilityRule, HasRuleForm
{
    public const string KEY = 'zone';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('shopper-pricelist::messages.rules.zone');
    }

    public function formField(): Component
    {
        return Select::make('rules.'.self::KEY)
            ->label($this->label())
            ->multiple()
            ->options(fn (?array $state): array => Zone::query()->enabled()->orWhereIn('id', $state ?? [])->pluck('name', 'id')->all());
    }

    public function passes(array $values, PricingContext $context): bool
    {
        return in_array($context->zoneId, array_map(intval(...), $values), true);
    }
}
