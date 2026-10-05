<?php

declare(strict_types=1);

namespace Shopper\PriceList\Pricing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Contracts\PreloadsPrices;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Pricing\PricingContext;
use Shopper\Core\Pricing\ResolvedPrice;
use Shopper\PriceList\Enum\CompareAtMode;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceList;

final readonly class PriceListPriceResolver implements PreloadsPrices, PriceResolver
{
    public function __construct(
        private PriceResolver $catalog,
    ) {}

    public function resolve(Priceable&Model $priceable, PricingContext $context): ?ResolvedPrice
    {
        $base = $this->catalog->resolve($priceable, $context);
        $applicable = resolve(ApplicableLists::class);
        $lists = $applicable->for($context);
        $currencyId = $lists->isEmpty() ? null : $applicable->currencyId($context->currency());

        if ($currencyId === null) {
            return $base;
        }

        $lookup = resolve(PriceListLookup::class);

        $offers = $lists
            ->map(fn (PriceList $list): ?PriceListOffer => PriceListOffer::of($lookup, $list, $priceable, $base, $context, $currencyId))
            ->filter()
            ->sort(fn (PriceListOffer $a, PriceListOffer $b): int => [$b->list->priority, $b->list->rules->count(), $a->amount, $a->list->id]
                <=> [$a->list->priority, $a->list->rules->count(), $b->amount, $b->list->id]);

        $regular = $offers->first(fn (PriceListOffer $offer): bool => $offer->list->type === PriceListType::Override)->amount ?? $base?->amount;

        /** @var PriceListOffer|null $offer */
        $offer = $offers->first(fn (PriceListOffer $offer): bool => $offer->list->type === PriceListType::Override
            || ($regular !== null && $offer->amount < $regular));

        if ($offer === null) {
            return $base;
        }

        return new ResolvedPrice(
            amount: $offer->amount,
            currencyCode: $context->currency(),
            compareAmount: $this->compareAmount($lookup, $offer, $priceable, $base, $regular, $context, $currencyId),
            originalAmount: $base?->amount,
            meta: $offer->meta(),
        );
    }

    public function preload(iterable $priceables, PricingContext $context): void
    {
        if ($this->catalog instanceof PreloadsPrices) {
            $this->catalog->preload($priceables, $context);
        }

        $applicable = resolve(ApplicableLists::class);
        $lists = $applicable->for($context);
        $currencyId = $lists->isEmpty() ? null : $applicable->currencyId($context->currency());

        if ($currencyId === null) {
            return;
        }

        resolve(PriceListLookup::class)->preload(
            $priceables,
            $currencyId,
            withHistory: $lists->contains(fn (PriceList $list): bool => $list->compareAtMode() === CompareAtMode::Lowest30Days),
        );
    }

    private function compareAmount(
        PriceListLookup $lookup,
        PriceListOffer $offer,
        Model $priceable,
        ?ResolvedPrice $base,
        ?int $regular,
        PricingContext $context,
        int $currencyId,
    ): ?int {
        $compare = match ($offer->list->compareAtMode()) {
            CompareAtMode::Keep => $offer->compareAmount ?? $base?->compareAmount,
            CompareAtMode::Base => $offer->compareAmount ?? $base?->amount,
            CompareAtMode::Lowest30Days => $this->lowestPriorPrice($lookup, $offer, $priceable, $regular, $context, $currencyId),
            CompareAtMode::None => $offer->compareAmount,
        };

        return $compare !== null && $compare > $offer->amount ? $compare : null;
    }

    private function lowestPriorPrice(
        PriceListLookup $lookup,
        PriceListOffer $offer,
        Model $priceable,
        ?int $regular,
        PricingContext $context,
        int $currencyId,
    ): ?int {
        $rows = $lookup->history($lookup->key($priceable), $currencyId)
            ->only([':', $context->zoneId.':', ':'.$context->channelId, $context->zoneId.':'.$context->channelId]);
        $announcements = $rows->filter(fn (PriceHistory $row): bool => $row->amount === $offer->amount && $row->price_list_id === $offer->list->id);

        if ($announcements->isEmpty() || $announcements->contains(fn (PriceHistory $row): bool => $row->reference_amount === null)) {
            return null;
        }

        $firstId = $announcements->min('id');
        /** @var CarbonInterface $start */
        $start = $announcements->min('recorded_at');
        $others = $rows->reject(fn (PriceHistory $row): bool => $announcements->contains(
            fn (PriceHistory $announcement): bool => in_array($row->zone_id, [null, $announcement->zone_id], true)
                && in_array($row->channel_id, [null, $announcement->channel_id], true),
        ));

        if ($others->contains(fn (PriceHistory $row): bool => $row->id > $firstId)) {
            return null;
        }

        $windowStart = $start->copy()->subDays(PriceHistory::WINDOW_DAYS);

        if ($others->contains(fn (PriceHistory $row): bool => $row->reference_amount === null && $row->recorded_at->gt($windowStart))) {
            return null;
        }

        $lowest = $others
            ->map(fn (PriceHistory $row): int => $row->recorded_at->lte($windowStart)
                ? $row->amount
                : min($row->amount, $row->reference_amount))
            ->concat($announcements->pluck('reference_amount'))
            ->min();

        return min(array_filter(
            [$lowest, $offer->compareAmount, $offer->list->type === PriceListType::Sale ? $regular : null],
            fn (?int $amount): bool => $amount !== null,
        ));
    }
}
