<?php

declare(strict_types=1);

namespace Shopper\PriceList\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListRule;

/**
 * @extends Factory<PriceListRule>
 */
class PriceListRuleFactory extends Factory
{
    protected $model = PriceListRule::class;

    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'rule_key' => 'customer',
            'values' => [],
        ];
    }
}
