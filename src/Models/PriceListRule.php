<?php

declare(strict_types=1);

namespace Shopper\PriceList\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shopper\PriceList\Database\Factories\PriceListRuleFactory;

/**
 * @property-read int $id
 * @property-read int $price_list_id
 * @property-read string $rule_key
 * @property-read array<int, mixed> $values
 * @property-read PriceList $priceList
 */
class PriceListRule extends Model
{
    /** @use HasFactory<PriceListRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return shopper_table('price_list_rules');
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    protected static function newFactory(): PriceListRuleFactory
    {
        return PriceListRuleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'values' => 'array',
        ];
    }
}
