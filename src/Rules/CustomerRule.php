<?php

declare(strict_types=1);

namespace Shopper\PriceList\Rules;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Contracts\HasRuleForm;

final class CustomerRule implements ApplicabilityRule, HasRuleForm
{
    public const string KEY = 'customer';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('shopper-pricelist::messages.rules.customer');
    }

    public function formField(): Component
    {
        return Select::make('rules.'.self::KEY)
            ->label($this->label())
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing($this->search(...))
            ->getOptionLabelsUsing($this->optionLabels(...));
    }

    /**
     * @return array<int|string, string>
     */
    public function search(string $search): array
    {
        return $this->labels(
            $this->customers()
                ->where(fn (Builder $query): Builder => $query
                    ->whereLike('first_name', "%{$search}%")
                    ->orWhereLike('last_name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%"))
                ->limit(50)
                ->get(),
        );
    }

    /**
     * @param  array<int, int|string>  $values
     * @return array<int|string, string>
     */
    public function optionLabels(array $values): array
    {
        return $this->labels($this->customers()->whereKey($values)->get());
    }

    /**
     * @return Builder<Model>
     */
    private function customers(): Builder
    {
        return config('auth.providers.users.model')::query()->scopes('customers');
    }

    /**
     * @param  iterable<int, Model>  $customers
     * @return array<int|string, string>
     */
    private function labels(iterable $customers): array
    {
        return collect($customers)
            ->mapWithKeys(fn (Model $customer): array => [$customer->getKey() => "{$customer->getAttribute('full_name')} ({$customer->getAttribute('email')})"])
            ->all();
    }

    public function passes(array $values, PricingContext $context): bool
    {
        return in_array($context->customerId, array_map(intval(...), $values), true);
    }
}
