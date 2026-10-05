<?php

declare(strict_types=1);

namespace Shopper\PriceList\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Shopper\PriceList\Enum\AdjustmentType;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Enum\TierScope;
use Shopper\PriceList\Models\PriceList;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    protected $model = PriceList::class;

    public function definition(): array
    {
        return [
            'name' => Str::title($this->faker->unique()->words(2, true)),
            'type' => PriceListType::Sale,
            'status' => PriceListStatus::Active,
            'priority' => 0,
            'tier_scope' => TierScope::Item,
        ];
    }

    public function override(): static
    {
        return $this->state(['type' => PriceListType::Override]);
    }

    public function draft(): static
    {
        return $this->state(['status' => PriceListStatus::Draft]);
    }

    public function archived(): static
    {
        return $this->state(['status' => PriceListStatus::Archived]);
    }

    public function decrease(int $basisPoints): static
    {
        return $this->state([
            'adjustment_type' => AdjustmentType::Decrease,
            'adjustment_value' => $basisPoints,
        ]);
    }

    public function increase(int $basisPoints): static
    {
        return $this->state([
            'adjustment_type' => AdjustmentType::Increase,
            'adjustment_value' => $basisPoints,
        ]);
    }
}
