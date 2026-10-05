<?php

declare(strict_types=1);

namespace Shopper\PriceList\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Shopper\PriceList\Database\Factories\PriceHistoryFactory;

/**
 * @property-read int $id
 * @property-read string $priceable_type
 * @property-read int $priceable_id
 * @property-read int $currency_id
 * @property-read ?int $zone_id
 * @property-read ?int $channel_id
 * @property-read ?int $price_list_id
 * @property-read int $amount
 * @property-read ?int $reference_amount
 * @property-read CarbonInterface $recorded_at
 */
class PriceHistory extends Model
{
    /** @use HasFactory<PriceHistoryFactory> */
    use HasFactory;

    use MassPrunable;

    public const int WINDOW_DAYS = 31;

    public int $prunableChunkSize = 10000;

    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return shopper_table('price_list_price_history');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::WINDOW_DAYS);

        return static::query()
            ->where('recorded_at', '<', $cutoff)
            ->whereExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->from($this->getTable().' as later')
                ->whereColumn('later.priceable_type', $this->qualifyColumn('priceable_type'))
                ->whereColumn('later.priceable_id', $this->qualifyColumn('priceable_id'))
                ->whereColumn('later.currency_id', $this->qualifyColumn('currency_id'))
                ->where(fn (QueryBuilder $query): QueryBuilder => $this->sameScope($query, 'zone_id'))
                ->where(fn (QueryBuilder $query): QueryBuilder => $this->sameScope($query, 'channel_id'))
                ->whereColumn('later.id', '>', $this->qualifyColumn('id'))
                ->where('later.recorded_at', '<', $cutoff));
    }

    public function pruneAll(int $chunkSize = 1000): int
    {
        $total = 0;
        $lastId = 0;

        while (($ids = $this->prunable()->where('id', '>', $lastId)->orderBy('id')->limit($chunkSize)->pluck('id'))->isNotEmpty()) {
            $total += static::query()->whereKey($ids)->delete();
            $lastId = $ids->last();

            event(new ModelsPruned(static::class, $total));
        }

        return $total;
    }

    protected static function newFactory(): PriceHistoryFactory
    {
        return PriceHistoryFactory::new();
    }

    protected function sameScope(QueryBuilder $query, string $column): QueryBuilder
    {
        return $query
            ->whereColumn("later.{$column}", $this->qualifyColumn($column))
            ->orWhere(fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNull("later.{$column}")
                ->whereNull($this->qualifyColumn($column)));
    }

    protected function casts(): array
    {
        return [
            'zone_id' => 'integer',
            'channel_id' => 'integer',
            'price_list_id' => 'integer',
            'amount' => 'integer',
            'reference_amount' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }
}
