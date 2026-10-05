<?php

declare(strict_types=1);

namespace Shopper\PriceList\Pricing;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Shopper\Core\Models\Currency;
use Shopper\Core\Pricing\PricingContext;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Throwable;

final class ApplicableLists
{
    private ?CarbonInterface $now = null;

    private bool $suspended = false;

    /** @var array<string, Collection<int, PriceList>> */
    private array $lists = [];

    /** @var array<string, int|null> */
    private array $currencies = [];

    public function __construct(
        private readonly ActivePriceLists $active,
        private readonly ApplicabilityRuleManager $rules,
    ) {}

    /**
     * @return Collection<int, PriceList>
     */
    public function for(PricingContext $context): Collection
    {
        if ($this->suspended) {
            return new Collection;
        }

        $audience = new PricingContext(
            currencyCode: $context->currency(),
            customerId: $context->customerId,
            channelId: $context->channelId,
            zoneId: $context->zoneId,
        );

        return $this->lists[implode('|', [$audience->currencyCode, $audience->customerId, $audience->channelId, $audience->zoneId])] ??= $this->active->all()
            ->filter(fn (PriceList $list): bool => $list->coversDate($this->now()) && $this->applies($list, $audience))
            ->values();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function withoutLists(Closure $callback): mixed
    {
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = false;
        }
    }

    public function now(): CarbonInterface
    {
        return $this->now ??= now();
    }

    public function currencyId(string $code): ?int
    {
        if (! array_key_exists($code, $this->currencies)) {
            $id = Currency::query()->where('code', $code)->value('id');
            $this->currencies[$code] = $id === null ? null : (int) $id;
        }

        return $this->currencies[$code];
    }

    private function applies(PriceList $list, PricingContext $context): bool
    {
        return $list->rules->every(function (PriceListRule $listRule) use ($context): bool {
            try {
                return $this->rules->for($listRule->rule_key)?->passes($listRule->values, $context) ?? false;
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        });
    }
}
