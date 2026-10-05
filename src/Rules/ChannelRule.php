<?php

declare(strict_types=1);

namespace Shopper\PriceList\Rules;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Shopper\Core\Models\Contracts\Channel;
use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Contracts\HasRuleForm;

final class ChannelRule implements ApplicabilityRule, HasRuleForm
{
    public const string KEY = 'channel';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('shopper-pricelist::messages.rules.channel');
    }

    public function formField(): Component
    {
        return Select::make('rules.'.self::KEY)
            ->label($this->label())
            ->helperText(__('shopper-pricelist::messages.rules.channel_help'))
            ->multiple()
            ->options(fn (?array $state): array => resolve(Channel::class)::query()->enabled()->orWhereIn('id', $state ?? [])->pluck('name', 'id')->all());
    }

    public function passes(array $values, PricingContext $context): bool
    {
        return in_array($context->channelId, array_map(intval(...), $values), true);
    }
}
