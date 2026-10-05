<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Price;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\ResolvedPrice;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function currency(string $code = 'USD'): Currency
{
    return Currency::query()->withoutGlobalScopes()->where('code', $code)->firstOrFail();
}

/**
 * @template TModel of Model
 *
 * @param  TModel  $priceable
 * @return TModel
 */
function withCatalogPrice(Model $priceable, int $amount, string $currency = 'USD', ?int $compareAmount = null): Model
{
    Price::factory()->for($priceable, 'priceable')->create([
        'amount' => $amount,
        'compare_amount' => $compareAmount,
        'cost_amount' => null,
        'currency_id' => currency($currency)->id,
    ]);

    return $priceable->unsetRelation('prices');
}

/**
 * @template TModel of Model
 *
 * @param  TModel  $purchasable
 * @return TModel
 */
function stocked(Model $purchasable, int $quantity = 100): Model
{
    $purchasable->mutateStock(Inventory::factory()->create()->id, $quantity);

    return $purchasable;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function listPrice(PriceList $list, Model $priceable, int $amount, array $attributes = [], string $currency = 'USD'): PriceListPrice
{
    return PriceListPrice::factory()->for($list)->for($priceable, 'priceable')->create([
        'amount' => $amount,
        'currency_id' => currency($currency)->id,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $context
 */
function priceOf(Model $priceable, array $context = []): ?ResolvedPrice
{
    return $priceable->resolvePrice(new PricingContext(...['currencyCode' => 'USD', ...$context]));
}
