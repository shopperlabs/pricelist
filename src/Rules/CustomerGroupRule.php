<?php

declare(strict_types=1);

namespace Shopper\PriceList\Rules;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Shopper\Core\Pricing\PricingContext;
use Shopper\CustomerGroups\Models\CustomerGroup;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Contracts\HasRuleForm;

final class CustomerGroupRule implements ApplicabilityRule, HasRuleForm
{
    public const string KEY = 'customer_group';

    /** @var array<int, list<int>> */
    private array $groups = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('shopper-pricelist::messages.rules.customer_group');
    }

    public function formField(): Component
    {
        return Select::make('rules.'.self::KEY)
            ->label($this->label())
            ->helperText(__('shopper-pricelist::messages.rules.customer_group_help'))
            ->multiple()
            ->options(fn (?array $state): array => CustomerGroup::query()->active()->orWhereIn('id', $state ?? [])->pluck('name', 'id')->all());
    }

    public function passes(array $values, PricingContext $context): bool
    {
        if ($context->customerId === null) {
            return false;
        }

        return array_intersect($this->groupsOf($context->customerId), array_map(intval(...), $values)) !== [];
    }

    /**
     * @return list<int>
     */
    private function groupsOf(int $customerId): array
    {
        return $this->groups[$customerId] ??= CustomerGroup::query()
            ->active()
            ->whereHas('customers', fn (Builder $query): Builder => $query->whereKey($customerId))
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }
}
