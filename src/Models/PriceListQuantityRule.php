<?php

declare(strict_types=1);

namespace Shopper\PriceList\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Shopper\Core\Pricing\QuantityRule;
use Shopper\PriceList\Database\Factories\PriceListQuantityRuleFactory;

/**
 * @property-read int $id
 * @property-read int $price_list_id
 * @property-read string $priceable_type
 * @property-read int $priceable_id
 * @property-read int $minimum
 * @property-read ?int $maximum
 * @property-read int $increment
 * @property-read PriceList $priceList
 */
class PriceListQuantityRule extends Model
{
    /** @use HasFactory<PriceListQuantityRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return shopper_table('price_list_quantity_rules');
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function toRule(): QuantityRule
    {
        return new QuantityRule($this->minimum, $this->maximum, $this->increment);
    }

    protected static function newFactory(): PriceListQuantityRuleFactory
    {
        return PriceListQuantityRuleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'minimum' => 'integer',
            'maximum' => 'integer',
            'increment' => 'integer',
        ];
    }
}
