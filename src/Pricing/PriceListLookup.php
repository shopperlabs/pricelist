<?php

declare(strict_types=1);

namespace Shopper\PriceList\Pricing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Shopper\Core\Models\Contracts\Product;
use Shopper\Core\Models\Contracts\ProductVariant;
use Shopper\PriceList\Models\PriceHistory;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListQuantityRule;

final class PriceListLookup
{
    /** @var array<string, BaseCollection<int, BaseCollection<int, PriceListPrice>>> */
    private array $prices = [];

    /** @var array<string, Collection<int, PriceListQuantityRule>> */
    private array $quantityRules = [];

    /** @var array<string, BaseCollection<string, PriceHistory>> */
    private array $history = [];

    private ?string $productMorph = null;

    public function __construct(private readonly ActivePriceLists $active) {}

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<array{0: string, 1: int}>  $keys
     */
    public static function wherePriceables(Builder $query, array $keys): void
    {
        foreach (collect($keys)->groupBy(fn (array $key): string => $key[0]) as $type => $group) {
            $query->orWhere(fn (Builder $query): Builder => $query
                ->where('priceable_type', $type)
                ->whereIntegerInRaw('priceable_id', $group->pluck(1)->all()));
        }
    }

    /**
     * @param  iterable<Model>  $priceables
     */
    public function preload(iterable $priceables, int $currencyId, bool $withHistory = false): void
    {
        $priceables = collect($priceables);
        $items = $priceables->map($this->key(...));
        $keys = $items->concat($priceables->map($this->parentKey(...))->filter())->unique(fn (array $key): string => implode(':', $key));

        $prices = $keys->reject(fn (array $key): bool => isset($this->prices[$this->cacheKey($key, $currencyId)]));
        $this->loadPrices($prices->all(), $currencyId);

        $rules = $keys->reject(fn (array $key): bool => isset($this->quantityRules[$this->cacheKey($key)]));
        $this->loadQuantityRules($rules->all());

        if ($withHistory) {
            $history = $items->unique(fn (array $key): string => implode(':', $key))
                ->reject(fn (array $key): bool => isset($this->history[$this->cacheKey($key, $currencyId)]));
            $this->loadHistory($history->all(), $currencyId);
        }
    }

    /**
     * @param  array{0: string, 1: int}  $key
     * @return BaseCollection<int, BaseCollection<int, PriceListPrice>>
     */
    public function prices(array $key, int $currencyId): BaseCollection
    {
        if (! isset($this->prices[$this->cacheKey($key, $currencyId)])) {
            $this->loadPrices([$key], $currencyId);
        }

        return $this->prices[$this->cacheKey($key, $currencyId)];
    }

    /**
     * @param  array{0: string, 1: int}  $key
     * @return Collection<int, PriceListQuantityRule>
     */
    public function quantityRules(array $key): Collection
    {
        if (! isset($this->quantityRules[$this->cacheKey($key)])) {
            $this->loadQuantityRules([$key]);
        }

        return $this->quantityRules[$this->cacheKey($key)];
    }

    /**
     * @param  array{0: string, 1: int}  $key
     * @return BaseCollection<string, PriceHistory>
     */
    public function history(array $key, int $currencyId): BaseCollection
    {
        if (! isset($this->history[$this->cacheKey($key, $currencyId)])) {
            $this->loadHistory([$key], $currencyId);
        }

        return $this->history[$this->cacheKey($key, $currencyId)];
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function key(Model $priceable): array
    {
        return [$priceable->getMorphClass(), (int) $priceable->getKey()];
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    public function parentKey(Model $priceable): ?array
    {
        if (! $priceable instanceof ProductVariant) {
            return null;
        }

        $this->productMorph ??= resolve(Product::class)->getMorphClass();

        return [$this->productMorph, (int) $priceable->getAttribute('product_id')];
    }

    /**
     * @param  list<array{0: string, 1: int}>  $keys
     */
    private function loadPrices(array $keys, int $currencyId): void
    {
        if ($keys === []) {
            return;
        }

        $rows = PriceListPrice::query()
            ->whereIn('price_list_id', $this->active->all()->modelKeys())
            ->where('currency_id', $currencyId)
            ->where('amount', '>', 0)
            ->where(fn (Builder $query) => self::wherePriceables($query, $keys))
            ->get();

        $groups = $rows->groupBy(fn (PriceListPrice $price): string => $this->cacheKey([$price->priceable_type, $price->priceable_id], $currencyId));

        foreach ($keys as $key) {
            $this->prices[$this->cacheKey($key, $currencyId)] = $groups->get($this->cacheKey($key, $currencyId), new Collection)->toBase()->groupBy('price_list_id');
        }
    }

    /**
     * @param  list<array{0: string, 1: int}>  $keys
     */
    private function loadQuantityRules(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $rows = PriceListQuantityRule::query()
            ->whereIn('price_list_id', $this->active->all()->modelKeys())
            ->where(fn (Builder $query) => self::wherePriceables($query, $keys))
            ->get();

        $groups = $rows->groupBy(fn (PriceListQuantityRule $rule): string => $this->cacheKey([$rule->priceable_type, $rule->priceable_id]));

        foreach ($keys as $key) {
            $this->quantityRules[$this->cacheKey($key)] = $groups->get($this->cacheKey($key), new Collection)->values();
        }
    }

    /**
     * @param  list<array{0: string, 1: int}>  $keys
     */
    private function loadHistory(array $keys, int $currencyId): void
    {
        if ($keys === []) {
            return;
        }

        $constrain = fn (Builder $query): Builder => $query
            ->where('currency_id', $currencyId)
            ->where(fn (Builder $query) => self::wherePriceables($query, $keys));

        $latest = PriceHistory::query()
            ->selectRaw('max(id)')
            ->where($constrain)
            ->groupBy('priceable_type', 'priceable_id', 'zone_id', 'channel_id');

        $rows = PriceHistory::query()->where($constrain)->whereIn('id', $latest)->orderBy('id')->get();

        $groups = $rows->groupBy(fn (PriceHistory $row): string => $this->cacheKey([$row->priceable_type, $row->priceable_id], $currencyId));

        foreach ($keys as $key) {
            $this->history[$this->cacheKey($key, $currencyId)] = $groups->get($this->cacheKey($key, $currencyId), new Collection)
                ->toBase()
                ->keyBy(fn (PriceHistory $row): string => $row->zone_id.':'.$row->channel_id);
        }
    }

    /**
     * @param  array{0: string, 1: int}  $key
     */
    private function cacheKey(array $key, ?int $currencyId = null): string
    {
        return $key[0].':'.$key[1].':'.$currencyId;
    }
}
