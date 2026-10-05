<?php

declare(strict_types=1);

namespace Shopper\PriceList\Observers;

use Shopper\PriceList\Events\PriceListSaved;
use Shopper\PriceList\Jobs\SyncPriceList;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Pricing\ActivePriceLists;

final class PriceListRuleObserver
{
    public function created(PriceListRule $rule): void
    {
        $this->sync($rule, null, $rule->values);
    }

    public function updated(PriceListRule $rule): void
    {
        $this->sync($rule, $rule->getOriginal('values'), $rule->values);
    }

    public function deleted(PriceListRule $rule): void
    {
        $this->sync($rule, $rule->values, null);
    }

    /**
     * @param  array<int, mixed>|null  $before
     * @param  array<int, mixed>|null  $after
     */
    private function sync(PriceListRule $rule, ?array $before, ?array $after): void
    {
        ActivePriceLists::flush();
        SyncPriceList::dispatch($rule->price_list_id)->afterCommit();

        if ($before !== $after) {
            PriceListSaved::dispatch(
                $rule->priceList,
                shopper()->auth()->id(),
                ['rules' => [$rule->rule_key => $before]],
                ['rules' => [$rule->rule_key => $after]],
            );
        }
    }
}
