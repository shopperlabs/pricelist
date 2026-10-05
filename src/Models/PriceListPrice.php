<?php

declare(strict_types=1);

namespace Shopper\PriceList\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Shopper\Core\Models\Currency;
use Shopper\PriceList\Database\Factories\PriceListPriceFactory;

/**
 * @property-read int $id
 * @property-read int $price_list_id
 * @property-read string $priceable_type
 * @property-read int $priceable_id
 * @property-read int $currency_id
 * @property-read int $amount
 * @property-read ?int $compare_amount
 * @property-read int $min_quantity
 * @property-read ?int $max_quantity
 * @property-read PriceList $priceList
 * @property-read Currency $currency
 */
class PriceListPrice extends Model
{
    /** @use HasFactory<PriceListPriceFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return shopper_table('price_list_prices');
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withoutGlobalScopes();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function coversQuantity(int $quantity): bool
    {
        return $this->min_quantity <= $quantity
            && ($this->max_quantity === null || $this->max_quantity >= $quantity);
    }

    protected static function newFactory(): PriceListPriceFactory
    {
        return PriceListPriceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'compare_amount' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
        ];
    }
}
