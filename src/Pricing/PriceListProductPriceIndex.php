<?php

declare(strict_types=1);

namespace Shopper\PriceList\Pricing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Contracts\ProductPriceIndex;
use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Models\PriceList;

final readonly class PriceListProductPriceIndex implements ProductPriceIndex
{
    public function __construct(
        private ProductPriceIndex $catalog,
    ) {}

    public function minPriceExpression(Builder $query, int $currencyId, PricingContext $context): string
    {
        $catalog = $this->catalog->minPriceExpression($query, $currencyId, $context);
        $lists = resolve(ApplicableLists::class)->for($context);

        if ($lists->isEmpty()) {
            return $catalog;
        }

        $index = shopper_table('price_list_product_prices');
        $products = $query->getModel()->getTable();

        return "COALESCE((SELECT MIN({$this->amount($lists)}) FROM {$index} plp_base"
            ." WHERE plp_base.product_id = {$products}.id AND plp_base.currency_id = {$currencyId} AND plp_base.price_list_id IS NULL"
            ." AND EXISTS (SELECT 1 FROM {$index} plp_offer WHERE plp_offer.product_id = {$products}.id"
            ." AND plp_offer.currency_id = {$currencyId} AND plp_offer.price_list_id IN ({$this->ids($lists)}))), ({$catalog}))";
    }

    public function ranges(Collection $products, int $currencyId, PricingContext $context): Collection
    {
        $lists = resolve(ApplicableLists::class)->for($context);

        if ($lists->isEmpty() || $products->isEmpty()) {
            return $this->catalog->ranges($products, $currencyId, $context);
        }

        $index = shopper_table('price_list_product_prices');
        $amounts = DB::table("{$index} as plp_base")
            ->whereIn('plp_base.product_id', $products->map(fn (Model $product): mixed => $product->getKey())->all())
            ->where('plp_base.currency_id', $currencyId)
            ->whereNull('plp_base.price_list_id')
            ->whereExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->from("{$index} as plp_offer")
                ->whereColumn('plp_offer.product_id', 'plp_base.product_id')
                ->where('plp_offer.currency_id', $currencyId)
                ->whereIn('plp_offer.price_list_id', $lists->modelKeys()))
            ->selectRaw("plp_base.product_id, {$this->amount($lists)} as amount");

        $rows = DB::query()
            ->fromSub($amounts, 'plp_amounts')
            ->whereNotNull('amount')
            ->groupBy('product_id')
            ->selectRaw('product_id, MIN(amount) as min_amount, MAX(amount) as max_amount')
            ->get()
            ->keyBy('product_id');

        $unlisted = $products->reject(fn (Model $product): bool => $rows->has($product->getKey()))->values();

        return $rows->union($this->catalog->ranges($unlisted, $currencyId, $context));
    }

    /**
     * @param  EloquentCollection<int, PriceList>  $lists
     */
    private function amount(EloquentCollection $lists): string
    {
        $index = shopper_table('price_list_product_prices');
        $tiers = $lists->mapWithKeys(fn (PriceList $list): array => [$list->id => [$list->priority, $list->rules->count()]]);
        $ranked = $tiers->unique()->sortDesc()->values();
        $ranks = $tiers->map(fn (array $tier): int => (int) $ranked->search($tier) + 1);
        $rank = fn (string $alias): string => "CASE {$alias}.price_list_id".$ranks->map(fn (int $rank, int $id): string => " WHEN {$id} THEN {$rank}")->implode('').' END';
        [$overrides, $sales] = $lists->partition(fn (PriceList $list): bool => $list->type === PriceListType::Override);

        $regular = $overrides->isEmpty() ? 'NULL' : "(SELECT plp_regular.amount FROM {$index} plp_regular"
            ." WHERE {$this->sameItem('plp_regular', 'plp_base')} AND plp_regular.price_list_id IN ({$this->ids($overrides)})"
            ." ORDER BY {$rank('plp_regular')}, plp_regular.amount LIMIT 1)";

        $sale = $sales->isEmpty() ? 'NULL' : "(SELECT plp_sale.amount FROM {$index} plp_sale"
            ." WHERE {$this->sameItem('plp_sale', 'plp_base')} AND plp_sale.price_list_id IN ({$this->ids($sales)})"
            ." AND plp_sale.amount < COALESCE({$regular}, plp_base.amount)"
            .($overrides->isEmpty() ? '' : " AND NOT EXISTS (SELECT 1 FROM {$index} plp_higher"
                ." WHERE {$this->sameItem('plp_higher', 'plp_sale')} AND plp_higher.price_list_id IN ({$this->ids($overrides)})"
                ." AND {$rank('plp_higher')} < {$rank('plp_sale')})")
            ." ORDER BY {$rank('plp_sale')}, plp_sale.amount LIMIT 1)";

        return "COALESCE({$sale}, {$regular}, plp_base.amount)";
    }

    private function sameItem(string $alias, string $of): string
    {
        return "{$alias}.product_id = {$of}.product_id AND {$alias}.item_id = {$of}.item_id AND {$alias}.currency_id = {$of}.currency_id";
    }

    /**
     * @param  EloquentCollection<int, PriceList>  $lists
     */
    private function ids(EloquentCollection $lists): string
    {
        return implode(', ', array_map(intval(...), $lists->modelKeys()));
    }
}
