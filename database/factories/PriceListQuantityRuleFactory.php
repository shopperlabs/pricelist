<?php

declare(strict_types=1);

namespace Shopper\PriceList\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListQuantityRule;

/**
 * @extends Factory<PriceListQuantityRule>
 */
class PriceListQuantityRuleFactory extends Factory
{
    protected $model = PriceListQuantityRule::class;

    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'minimum' => 1,
            'increment' => 1,
        ];
    }
}
