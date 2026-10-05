<?php

declare(strict_types=1);

namespace Shopper\PriceList\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shopper\PriceList\Models\PriceHistory;

/**
 * @extends Factory<PriceHistory>
 */
class PriceHistoryFactory extends Factory
{
    protected $model = PriceHistory::class;

    public function definition(): array
    {
        return [
            'amount' => 1000,
            'recorded_at' => now(),
        ];
    }
}
