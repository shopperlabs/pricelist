<?php

declare(strict_types=1);

namespace Shopper\PriceList\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shopper\Core\Models\Traits\HasPublicId;
use Shopper\PriceList\Database\Factories\PriceListFactory;
use Shopper\PriceList\Enum\AdjustmentType;
use Shopper\PriceList\Enum\CompareAtMode;
use Shopper\PriceList\Enum\PriceListState;
use Shopper\PriceList\Enum\PriceListStatus;
use Shopper\PriceList\Enum\PriceListType;
use Shopper\PriceList\Enum\TierScope;

/**
 * @property-read int $id
 * @property-read ?string $public_id
 * @property-read string $name
 * @property-read ?string $description
 * @property-read PriceListType $type
 * @property-read PriceListStatus $status
 * @property-read ?CarbonInterface $starts_at
 * @property-read ?CarbonInterface $ends_at
 * @property-read int $priority
 * @property-read ?AdjustmentType $adjustment_type
 * @property-read ?int $adjustment_value
 * @property-read ?CompareAtMode $compare_at_mode
 * @property-read TierScope $tier_scope
 * @property-read ?array<string, int> $rounding_endings
 * @property-read ?array<string, mixed> $metadata
 * @property-read PriceListState $state
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Collection<int, PriceListPrice> $prices
 * @property-read Collection<int, PriceListRule> $rules
 * @property-read Collection<int, PriceListQuantityRule> $quantityRules
 */
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    use HasPublicId;

    protected $guarded = [];

    public function getTable(): string
    {
        return shopper_table('price_lists');
    }

    /**
     * @return HasMany<PriceListPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PriceListPrice::class);
    }

    /**
     * @return HasMany<PriceListRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(PriceListRule::class);
    }

    /**
     * @return HasMany<PriceListQuantityRule, $this>
     */
    public function quantityRules(): HasMany
    {
        return $this->hasMany(PriceListQuantityRule::class);
    }

    public function coversDate(CarbonInterface $at): bool
    {
        return ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    public function compareAtMode(): CompareAtMode
    {
        return $this->compare_at_mode ?? $this->type->compareAtMode();
    }

    public function adjustsPrices(): bool
    {
        return $this->adjustment_type !== null
            && $this->adjustment_value > 0
            && $this->adjustment_value <= $this->adjustment_type->maximum();
    }

    public function adjust(int $amount, string $currencyCode): int
    {
        if ($amount < 1) {
            return $amount;
        }

        $increase = $this->adjustment_type === AdjustmentType::Increase;
        $factor = $increase ? 10000 + $this->adjustment_value : 10000 - $this->adjustment_value;
        $adjusted = max(1, intdiv($amount * $factor + 5000, 10000));
        $ending = $this->rounding_endings[$currencyCode] ?? null;

        if (! is_int($ending) || $ending < 0) {
            return $adjusted;
        }

        $unit = 10 ** mb_strlen((string) $ending);
        $rounded = intdiv($adjusted, $unit) * $unit + $ending;

        if ($rounded > $adjusted) {
            $rounded -= $unit;
        }

        return $rounded < 1 || $adjusted - $rounded > abs($amount - $adjusted) ? $adjusted : $rounded;
    }

    /**
     * @param  Builder<PriceList>  $query
     * @return Builder<PriceList>
     */
    #[Scope]
    protected function inState(Builder $query, PriceListState $state): Builder
    {
        $now = now();

        return match ($state) {
            PriceListState::Draft => $query->where('status', PriceListStatus::Draft),
            PriceListState::Archived => $query->where('status', PriceListStatus::Archived),
            PriceListState::Scheduled => $query->where('status', PriceListStatus::Active)->where('starts_at', '>', $now),
            PriceListState::Expired => $query->where('status', PriceListStatus::Active)
                ->where('ends_at', '<=', $now)
                ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now)),
            PriceListState::Active => $query->where('status', PriceListStatus::Active)
                ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
        };
    }

    protected function state(): Attribute
    {
        return Attribute::get(fn (): PriceListState => match (true) {
            $this->status === PriceListStatus::Draft => PriceListState::Draft,
            $this->status === PriceListStatus::Archived => PriceListState::Archived,
            $this->starts_at?->isFuture() === true => PriceListState::Scheduled,
            $this->ends_at !== null && ! $this->ends_at->isFuture() => PriceListState::Expired,
            default => PriceListState::Active,
        });
    }

    protected static function newFactory(): PriceListFactory
    {
        return PriceListFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => PriceListType::class,
            'status' => PriceListStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'adjustment_type' => AdjustmentType::class,
            'adjustment_value' => 'integer',
            'compare_at_mode' => CompareAtMode::class,
            'tier_scope' => TierScope::class,
            'rounding_endings' => 'array',
            'metadata' => 'array',
        ];
    }
}
