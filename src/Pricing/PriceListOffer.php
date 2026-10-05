<?php

declare(strict_types=1);

namespace Shopper\PriceList\Pricing;

use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\ResolvedPrice;
use Shopper\PriceList\Enum\PriceSource;
use Shopper\PriceList\Enum\TierScope;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;

final readonly class PriceListOffer
{
    /**
     * @param  array{0: int, 1: int|null}|null  $tier
     */
    public function __construct(
        public PriceList $list,
        public int $amount,
        public PriceSource $source,
        public ?int $compareAmount = null,
        public ?string $level = null,
        public ?array $tier = null,
    ) {}

    public static function of(
        PriceListLookup $lookup,
        PriceList $list,
        Model $priceable,
        ?ResolvedPrice $base,
        PricingContext $context,
        int $currencyId,
    ): ?self {
        $prices = $lookup->prices($lookup->key($priceable), $currencyId)->get($list->id, collect());
        $parent = $lookup->parentKey($priceable);

        if ($prices->isEmpty() && $parent !== null) {
            $prices = $lookup->prices($parent, $currencyId)->get($list->id, collect());
        }

        $quantity = $list->tier_scope === TierScope::Product ? $context->productQuantity() : $context->quantity;

        /** @var PriceListPrice|null $price */
        $price = $prices
            ->filter(fn (PriceListPrice $price): bool => $price->coversQuantity($quantity))
            ->sortByDesc('min_quantity')
            ->first();

        return match (true) {
            $price !== null => self::fixed($list, $price),
            $base !== null && $list->adjustsPrices() => self::adjusted($list, $list->adjust($base->amount, $context->currency())),
            default => null,
        };
    }

    public static function fixed(PriceList $list, PriceListPrice $price): self
    {
        return new self(
            list: $list,
            amount: $price->amount,
            source: PriceSource::Fixed,
            compareAmount: $price->compare_amount,
            level: $price->priceable_type,
            tier: [$price->min_quantity, $price->max_quantity],
        );
    }

    public static function adjusted(PriceList $list, int $amount): self
    {
        return new self(list: $list, amount: $amount, source: PriceSource::Adjustment);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'public' => [
                'price_list' => ['id' => $this->list->public_id, 'type' => $this->list->type->value],
            ],
            'price_list' => [
                'id' => $this->list->public_id,
                'name' => $this->list->name,
                'type' => $this->list->type->value,
            ],
            'source' => $this->source->value,
            'level' => $this->level,
            'tier' => $this->tier,
        ];
    }
}
