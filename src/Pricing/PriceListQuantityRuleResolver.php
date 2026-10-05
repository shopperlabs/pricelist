<?php

declare(strict_types=1);

namespace Shopper\PriceList\Pricing;

use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Contracts\QuantityRuleResolver;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\QuantityRule;
use Shopper\PriceList\Models\PriceListQuantityRule;

final readonly class PriceListQuantityRuleResolver implements QuantityRuleResolver
{
    public function __construct(
        private QuantityRuleResolver $fallback,
    ) {}

    public function resolve(Priceable&Model $purchasable, PricingContext $context): ?QuantityRule
    {
        $winner = resolve(PriceResolver::class)->resolve($purchasable, $context)?->meta['price_list']['id'] ?? null;
        $list = $winner === null ? null : resolve(ApplicableLists::class)->for($context)->firstWhere('public_id', $winner);

        if ($list === null) {
            return $this->fallback->resolve($purchasable, $context);
        }

        $lookup = resolve(PriceListLookup::class);
        $parent = $lookup->parentKey($purchasable);

        /** @var PriceListQuantityRule|null $rule */
        $rule = $lookup->quantityRules($lookup->key($purchasable))->firstWhere('price_list_id', $list->id)
            ?? ($parent === null ? null : $lookup->quantityRules($parent)->firstWhere('price_list_id', $list->id));

        return $rule === null
            ? $this->fallback->resolve($purchasable, $context)
            : rescue(fn (): QuantityRule => $rule->toRule(), fn (): ?QuantityRule => $this->fallback->resolve($purchasable, $context));
    }
}
