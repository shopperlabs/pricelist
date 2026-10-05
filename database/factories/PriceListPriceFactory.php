<?php

declare(strict_types=1);

namespace Shopper\PriceList\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;

/**
 * @extends Factory<PriceListPrice>
 */
class PriceListPriceFactory extends Factory
{
    protected $model = PriceListPrice::class;

    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'amount' => 1000,
            'min_quantity' => 1,
        ];
    }
}
