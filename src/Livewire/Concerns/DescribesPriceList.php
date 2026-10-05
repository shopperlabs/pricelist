<?php

declare(strict_types=1);

namespace Shopper\PriceList\Livewire\Concerns;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Contracts\HasRuleForm;
use Shopper\PriceList\Enum\AdjustmentType;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;

trait DescribesPriceList
{
    /**
     * @param  array<string, mixed>  $values
     */
    private function summarizeTarget(Schema $schema, array $values): string
    {
        $parts = collect(app(ApplicabilityRuleManager::class)->all())
            ->filter(fn (ApplicabilityRule $rule): bool => $rule instanceof HasRuleForm && filled($values[$rule->key()] ?? null))
            ->map(function (ApplicabilityRule $rule) use ($schema, $values): string {
                $field = $schema->getComponentByStatePath('rules.'.$rule->key());
                $labels = $field instanceof Select ? $field->getOptionLabels() : (array) $values[$rule->key()];

                return __('shopper-pricelist::messages.summary_rule', [
                    'rule' => $rule->label(),
                    'values' => implode(__('shopper-pricelist::messages.summary_or'), $labels),
                ]);
            });

        return $parts->isEmpty()
            ? __('shopper-pricelist::messages.everyone')
            : $parts->implode(__('shopper-pricelist::messages.summary_and'));
    }

    private function period(PriceList $priceList): string
    {
        $timezone = $this->timezone();
        $starts = $priceList->starts_at?->timezone($timezone)->translatedFormat('j M Y');
        $ends = $priceList->ends_at?->timezone($timezone)->translatedFormat('j M Y');

        return match (true) {
            $starts !== null && $ends !== null => "{$starts} - {$ends}",
            $starts !== null => __('shopper-pricelist::messages.period_from', ['date' => $starts]),
            $ends !== null => __('shopper-pricelist::messages.period_until', ['date' => $ends]),
            default => __('shopper-pricelist::messages.period_permanent'),
        };
    }

    private function adjustment(PriceList $priceList): ?string
    {
        if (! $priceList->adjustsPrices()) {
            return null;
        }

        return ($priceList->adjustment_type === AdjustmentType::Increase ? '+' : '-')
            .Number::percentage($priceList->adjustment_value / 100, maxPrecision: 2, locale: app()->getLocale());
    }

    private function timezone(): string
    {
        return shopper()->auth()->user()->timezone ?? config('app.timezone');
    }
}
