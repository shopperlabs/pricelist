<?php

declare(strict_types=1);

namespace Shopper\PriceList\Rules;

use Shopper\PriceList\Contracts\ApplicabilityRule;

final class ApplicabilityRuleManager
{
    /** @var array<string, ApplicabilityRule|class-string<ApplicabilityRule>> */
    private array $rules = [];

    /**
     * @param  ApplicabilityRule|class-string<ApplicabilityRule>  $rule
     */
    public function register(ApplicabilityRule|string $rule): void
    {
        $this->rules[$this->instance($rule)->key()] = $rule;
    }

    public function for(string $key): ?ApplicabilityRule
    {
        return isset($this->rules[$key]) ? $this->instance($this->rules[$key]) : null;
    }

    /**
     * @return array<string, ApplicabilityRule>
     */
    public function all(): array
    {
        return array_map($this->instance(...), $this->rules);
    }

    /**
     * @param  ApplicabilityRule|class-string<ApplicabilityRule>  $rule
     */
    private function instance(ApplicabilityRule|string $rule): ApplicabilityRule
    {
        return is_string($rule) ? resolve($rule) : $rule;
    }
}
