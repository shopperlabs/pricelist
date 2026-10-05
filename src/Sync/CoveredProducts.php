<?php

declare(strict_types=1);

namespace Shopper\PriceList\Sync;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Shopper\Core\Models\Contracts\Product;
use Shopper\Core\Models\Contracts\ProductVariant;
use Shopper\PriceList\Models\PriceList;

final class CoveredProducts
{
    public const int BATCH = 100;

    /**
     * @param  Closure(list<int>): mixed  $callback
     */
    public static function all(Closure $callback): void
    {
        resolve(Product::class)::query()
            ->select('id')
            ->chunkById(self::BATCH, fn (Collection $products) => $callback(array_map(intval(...), $products->modelKeys())));
    }

    /**
     * @param  Closure(list<int>): mixed  $callback
     */
    public static function each(PriceList $list, Closure $callback): void
    {
        if ($list->adjustsPrices()) {
            self::all($callback);

            return;
        }

        foreach (array_chunk(self::priced($list), self::BATCH) as $productIds) {
            $callback($productIds);
        }
    }

    /**
     * @return list<int>
     */
    public static function priced(PriceList $list): array
    {
        $prices = $list->prices()->toBase();
        $product = resolve(Product::class);
        $variant = resolve(ProductVariant::class);

        return $prices->clone()->where('priceable_type', $product->getMorphClass())->distinct()->pluck('priceable_id')
            ->concat($variant::query()
                ->whereIn($variant->getKeyName(), $prices->clone()->where('priceable_type', $variant->getMorphClass())->select('priceable_id'))
                ->distinct()
                ->pluck('product_id'))
            ->map(intval(...))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public static function ofPriceable(string $type, int $id): ?int
    {
        $variant = resolve(ProductVariant::class);

        if ($type === resolve(Product::class)->getMorphClass()) {
            return $id;
        }

        if ($type !== $variant->getMorphClass()) {
            return null;
        }

        $productId = $variant::query()->whereKey($id)->value('product_id');

        return $productId === null ? null : (int) $productId;
    }
}
