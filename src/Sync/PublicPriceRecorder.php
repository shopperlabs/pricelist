<?php

declare(strict_types=1);

namespace Shopper\PriceList\Sync;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Shopper\Core\Contracts\PreloadsPrices;
use Shopper\Core\Contracts\Priceable;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Models\Contracts\Channel;
use Shopper\Core\Models\Zone;
use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Pricing\ActivePriceLists;
use Shopper\PriceList\Pricing\PriceListLookup;
use Shopper\PriceList\Rules\ChannelRule;
use Shopper\PriceList\Rules\ZoneRule;

final readonly class PublicPriceRecorder
{
    public function __construct(
        private PriceResolver $prices,
    ) {}

    public function record(ProductBatch $batch): void
    {
        $items = $batch->allItems();

        if ($items->isEmpty()) {
            return;
        }

        $now = now()->startOfSecond();
        $scopes = $this->scopes($items);
        $lists = resolve(ActivePriceLists::class)->all()->pluck('id', 'public_id');
        $rows = [];

        $cutoff = $now->copy()->subDays(PriceHistory::WINDOW_DAYS);
        $ended = $this->ended($now, $cutoff);

        foreach ($batch->currencies() as $currencyId => $code) {
            $history = $this->history($items, $currencyId, $cutoff);
            $unrecorded = $this->unrecorded($items, $ended, $currencyId, $history);
            $byScope = $history->map(fn (Collection $rows): Collection => $rows->groupBy(fn (PriceHistory $row): string => $row->zone_id.':'.$row->channel_id));
            $global = [];

            foreach ($scopes as [$zoneId, $channelId, $visitors]) {
                $context = new PricingContext(currencyCode: $code, channelId: $channelId, zoneId: $zoneId);

                if ($this->prices instanceof PreloadsPrices) {
                    $this->prices->preload($visitors, $context);
                }

                foreach ($visitors as $item) {
                    $key = $item->getMorphClass().':'.$item->getKey();
                    $scoped = $byScope->get($key, new Collection);
                    $tracked = $scoped->get($zoneId.':'.$channelId, new Collection);
                    $price = $this->prices->resolve($item, $context);
                    $current = [$price?->amount, $lists->get($price?->meta['price_list']['id'] ?? '')];

                    if ($zoneId === null && $channelId === null) {
                        $global[$key] = $current;
                    } elseif ($tracked->isEmpty() && $current === ($global[$key] ?? null)) {
                        continue;
                    }

                    [$amount, $listId] = $current;
                    $latest = $tracked->sortByDesc('id')->first();

                    if ($amount === null || [$latest?->amount, $latest?->price_list_id] === $current) {
                        continue;
                    }

                    $firstOwn = $tracked->min('id');
                    $ownBeforeWindow = $tracked->contains(fn (PriceHistory $row): bool => $row->recorded_at->lt($cutoff));
                    $prior = $scoped->only([':', $zoneId.':', ':'.$channelId, $zoneId.':'.$channelId])
                        ->flatten(1)
                        ->filter(fn (PriceHistory $row): bool => ($row->zone_id === $zoneId && $row->channel_id === $channelId)
                            || (! $ownBeforeWindow && ($firstOwn === null || $row->id < $firstOwn)));
                    $created = $item->getAttribute('created_at');
                    $proven = ! isset($unrecorded[$key])
                        && (($created instanceof CarbonInterface && $created->gte($cutoff))
                            || $prior->contains(fn (PriceHistory $row): bool => $row->recorded_at->lt($cutoff)));

                    $rows[] = [
                        'priceable_type' => $item->getMorphClass(),
                        'priceable_id' => $item->getKey(),
                        'currency_id' => $currencyId,
                        'zone_id' => $zoneId,
                        'channel_id' => $channelId,
                        'price_list_id' => $listId,
                        'amount' => $amount,
                        'reference_amount' => $proven ? ($prior->min('amount') ?? $amount) : null,
                        'recorded_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PriceHistory::query()->insert($chunk);
        }

        app()->forgetInstance(PriceListLookup::class);
    }

    public function resolutions(ProductBatch $batch): int
    {
        $items = $batch->allItems();

        return $items->isEmpty() ? 0 : count($batch->currencies()) * array_sum(array_map(fn (array $scope): int => $scope[2]->count(), $this->scopes($items)));
    }

    /**
     * @param  Collection<int, Model&Priceable>  $items
     * @return Collection<string, Collection<int, PriceHistory>>
     */
    private function history(Collection $items, int $currencyId, CarbonInterface $cutoff): Collection
    {
        $keys = $this->keys($items);
        $constrain = fn (Builder $query): Builder => $query
            ->where('currency_id', $currencyId)
            ->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, $keys));

        $inEffect = PriceHistory::query()
            ->selectRaw('max(id)')
            ->where($constrain)
            ->where('recorded_at', '<', $cutoff)
            ->groupBy('priceable_type', 'priceable_id', 'zone_id', 'channel_id');

        return PriceHistory::query()
            ->where($constrain)
            ->where(fn (Builder $query): Builder => $query->where('recorded_at', '>=', $cutoff)->orWhereIn('id', $inEffect))
            ->get()
            ->toBase()
            ->groupBy(fn (PriceHistory $row): string => $row->priceable_type.':'.$row->priceable_id);
    }

    /**
     * @return EloquentCollection<int, PriceList>
     */
    private function ended(CarbonInterface $now, CarbonInterface $cutoff): EloquentCollection
    {
        return PriceList::query()
            ->with('rules')
            ->whereBetween('ends_at', [$cutoff, $now])
            ->orWhere(fn (Builder $query): Builder => $query->where('status', PriceListStatus::Archived)->where('updated_at', '>=', $cutoff))
            ->get()
            ->filter(function (PriceList $list) use ($cutoff): bool {
                $end = ($list->status === PriceListStatus::Archived ? $list->ends_at?->min($list->updated_at) : $list->ends_at) ?? $list->updated_at;

                return $end->gte($cutoff)
                    && ($list->starts_at === null || $list->starts_at->lt($end))
                    && $list->rules->every(fn (PriceListRule $rule): bool => in_array($rule->rule_key, [ZoneRule::KEY, ChannelRule::KEY], true));
            });
    }

    /**
     * @param  Collection<int, Model&Priceable>  $items
     * @param  EloquentCollection<int, PriceList>  $lists
     * @param  Collection<string, Collection<int, PriceHistory>>  $history
     * @return array<string, int>
     */
    private function unrecorded(Collection $items, EloquentCollection $lists, int $currencyId, Collection $history): array
    {
        if ($lists->isEmpty()) {
            return [];
        }

        $lookup = resolve(PriceListLookup::class);
        $adjusting = $lists->filter(fn (PriceList $list): bool => $list->adjustsPrices())->modelKeys();

        $priced = PriceListPrice::query()
            ->whereIn('price_list_id', $lists->modelKeys())
            ->where('currency_id', $currencyId)
            ->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, $items->map($lookup->key(...))
                ->concat($items->map($lookup->parentKey(...))->filter()->unique(fn (array $key): string => implode(':', $key)))
                ->all()))
            ->get()
            ->toBase()
            ->groupBy(fn (PriceListPrice $price): string => $price->priceable_type.':'.$price->priceable_id);

        return $items
            ->map(function (Model $item) use ($lookup, $priced, $history, $adjusting): ?string {
                $key = implode(':', $lookup->key($item));
                $parent = $lookup->parentKey($item);
                $listIds = $priced->get($key, new Collection)
                    ->concat($parent === null ? [] : $priced->get(implode(':', $parent), new Collection))
                    ->pluck('price_list_id')
                    ->concat($adjusting)
                    ->diff($history->get($key, new Collection)->pluck('price_list_id'));

                return $listIds->isEmpty() ? null : $key;
            })
            ->filter()
            ->flip()
            ->all();
    }

    /**
     * @param  Collection<int, Model&Priceable>  $items
     * @return array<string, array{0: int|null, 1: int|null, 2: Collection<int, Model&Priceable>}>
     */
    private function scopes(Collection $items): array
    {
        $zones = [null, ...Zone::query()->pluck('id')->map(intval(...))->all()];
        $channels = [null, ...resolve(Channel::class)::query()->pluck('id')->map(intval(...))->all()];
        $exists = fn (?int $zoneId, ?int $channelId): bool => in_array($zoneId, $zones, true) && in_array($channelId, $channels, true);

        $public = collect($this->publicScopes())
            ->filter(fn (array $scope): bool => $exists(...$scope))
            ->mapWithKeys(fn (array $scope): array => [$scope[0].':'.$scope[1] => [$scope[0], $scope[1], $items]])
            ->all();

        $byKey = $items->keyBy(fn (Model $item): string => $item->getMorphClass().':'.$item->getKey());

        $tracked = PriceHistory::query()
            ->distinct()
            ->where(fn (Builder $query) => PriceListLookup::wherePriceables($query, $this->keys($items)))
            ->get(['priceable_type', 'priceable_id', 'zone_id', 'channel_id'])
            ->toBase()
            ->filter(fn (PriceHistory $row): bool => $exists($row->zone_id, $row->channel_id) && ! isset($public[$row->zone_id.':'.$row->channel_id]))
            ->groupBy(fn (PriceHistory $row): string => $row->zone_id.':'.$row->channel_id)
            ->map(fn (Collection $rows): array => [
                $rows[0]->zone_id,
                $rows[0]->channel_id,
                $byKey->only($rows->map(fn (PriceHistory $row): string => $row->priceable_type.':'.$row->priceable_id)->all())->values(),
            ]);

        return $public + $tracked->all();
    }

    /**
     * @return list<array{0: int|null, 1: int|null}>
     */
    private function publicScopes(): array
    {
        $zones = [null];
        $channels = [null];

        foreach (resolve(ActivePriceLists::class)->all() as $list) {
            $zones = [...$zones, ...$this->values($list, ZoneRule::KEY)];
            $channels = [...$channels, ...$this->values($list, ChannelRule::KEY)];
        }

        return Arr::crossJoin(array_unique($zones, SORT_REGULAR), array_unique($channels, SORT_REGULAR));
    }

    /**
     * @param  Collection<int, Model&Priceable>  $items
     * @return list<array{0: string, 1: int}>
     */
    private function keys(Collection $items): array
    {
        return $items->map(fn (Model $item): array => [$item->getMorphClass(), (int) $item->getKey()])->values()->all();
    }

    /**
     * @return list<int|null>
     */
    private function values(PriceList $list, string $key): array
    {
        /** @var PriceListRule|null $rule */
        $rule = $list->rules->firstWhere('rule_key', $key);

        return $rule === null ? [null] : array_map(intval(...), $rule->values);
    }
}
