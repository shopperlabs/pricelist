<div>
    <x-shopper::container class="space-y-8 py-5">
        <div class="space-y-6">
            <div class="space-y-3 lg:flex lg:items-start lg:justify-between lg:gap-6 lg:space-y-0">
                <div class="min-w-0 space-y-2">
                    <div class="gap-3 sm:flex sm:items-center">
                        <h2 class="font-heading text-2xl font-bold text-sh-fg sm:truncate sm:text-3xl">
                            {{ $priceList->name }}
                        </h2>
                        <div class="mt-3 flex items-center gap-2 sm:mt-0">
                            <x-filament::badge
                                size="md"
                                :color="$priceList->state->getColor()"
                                :icon="$priceList->state->getIcon()"
                            >
                                {{ $priceList->state->getLabel() }}
                            </x-filament::badge>
                            <x-filament::badge size="md" color="gray">
                                {{ $priceList->type->getLabel() }}
                            </x-filament::badge>
                        </div>
                    </div>
                    @if ($priceList->description)
                        <p class="max-w-2xl text-sm text-sh-fg-secondary">
                            {{ $priceList->description }}
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    {{ $this->editAction }}

                    <x-filament-actions::group
                        :actions="[$this->archiveAction, $this->deleteAction]"
                        :label="__('shopper::forms.actions.more_actions')"
                        icon="untitledui-dots-horizontal"
                        color="gray"
                        size="md"
                        dropdown-placement="bottom-end"
                        :button="true"
                    />
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-6 border-t border-b border-sh-border py-5 sm:grid-cols-3">
                <x-shopper::description-list.item
                    :heading="__('shopper-pricelist::messages.target')"
                    :content="$targetSummary"
                />
                <x-shopper::description-list.item :heading="__('shopper-pricelist::messages.period')">
                    {{ $period }}
                    <span class="block text-xs">{{ $timezone }}</span>
                </x-shopper::description-list.item>
                <x-shopper::description-list.item
                    :heading="__('shopper-pricelist::messages.priority')"
                    :content="$priceList->priority"
                />
                <x-shopper::description-list.item :heading="__('shopper-pricelist::messages.adjustment')">
                    {{ $adjustment ?? '-' }}
                    @if ($adjustment && filled($priceList->rounding_endings))
                        <span class="block text-xs">
                            {{ collect($priceList->rounding_endings)->map(fn (int $ending, string $code): string => $code.' '.$ending)->implode(', ') }}
                        </span>
                    @endif
                </x-shopper::description-list.item>
                <x-shopper::description-list.item
                    :heading="__('shopper-pricelist::messages.compare_at')"
                    :content="$priceList->compareAtMode()->getLabel()"
                />
                <x-shopper::description-list.item
                    :heading="__('shopper-pricelist::messages.tiers')"
                    :content="$priceList->tier_scope->getLabel()"
                />
            </dl>
        </div>

        <div class="space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-1 flex-wrap items-center gap-4">
                    <x-filament::input.wrapper prefix-icon="untitledui-search-md" class="w-full max-w-xs">
                        <x-filament::input
                            type="search"
                            wire:model.live.debounce.400ms="search"
                            :placeholder="__('shopper-pricelist::messages.search_placeholder')"
                            :aria-label="__('shopper-pricelist::messages.search_placeholder')"
                        />
                    </x-filament::input.wrapper>

                    @if ($this->currencies->count() > 1)
                        <fieldset class="flex flex-wrap items-center gap-3">
                            <legend class="sr-only">{{ __('shopper-pricelist::messages.currencies') }}</legend>
                            @foreach ($this->currencies as $currency)
                                <label
                                    wire:key="currency-{{ $currency->code }}"
                                    class="inline-flex items-center gap-2 text-sm text-sh-fg"
                                >
                                    <x-filament::input.checkbox wire:model.live="currencyCodes" :value="$currency->code" />
                                    {{ $currency->code }}
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    {{ $this->removeSelectedAction }}
                    {{ $this->simulateAction }}
                    {{ $this->addProductsAction }}
                </div>
            </div>

            <div class="overflow-hidden rounded-xl bg-sh-surface ring-1 ring-sh-border">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-sh-border text-sm">
                        <thead class="bg-sh-muted">
                            <tr>
                                <th scope="col" class="sticky left-0 z-10 bg-sh-muted py-3 pr-3 pl-4 text-left font-medium text-sh-fg">
                                    <div class="flex items-center gap-3">
                                        @if ($canEdit && $rowKeys !== [])
                                            <x-filament::input.checkbox
                                                x-data="{ keys: {{ Js::from($rowKeys) }} }"
                                                x-bind:checked="keys.every((key) => $wire.selected.includes(key))"
                                                x-on:change="$wire.set('selected', $event.target.checked ? keys : [])"
                                                :aria-label="__('shopper-pricelist::messages.select_all')"
                                            />
                                        @endif
                                        {{ __('shopper-pricelist::messages.product_variant') }}
                                    </div>
                                </th>
                                @foreach ($visibleCurrencies as $currency)
                                    <th scope="col" class="px-3 py-3 text-left font-medium text-sh-fg">
                                        {{ $currency->code }}
                                    </th>
                                @endforeach
                                <th scope="col" class="px-3 py-3 text-left font-medium text-sh-fg">
                                    {{ __('shopper-pricelist::messages.quantity') }}
                                </th>
                                <th scope="col" class="py-3 pr-4 pl-3">
                                    <span class="sr-only">{{ __('shopper::forms.label.actions') }}</span>
                                </th>
                            </tr>
                        </thead>

                        @forelse ($this->products as $product)
                            @php
                                $rows = [
                                    ['product-'.$product->id, $product, false],
                                    ...$this->variants->get($product->id, collect())->map(fn ($variant): array => ['variant-'.$variant->id, $variant, true])->all(),
                                ];
                                $isExpanded = in_array($product->id, $expanded, true);
                            @endphp

                            <tbody wire:key="product-row-{{ $product->id }}" class="divide-y divide-sh-border">
                                @foreach ($rows as [$key, $priceable, $isVariant])
                                    @php
                                        $rule = $this->quantityRules[$key] ?? null;
                                        $inheritsRule = $isVariant && $rule === null && isset($this->quantityRules['product-'.$product->id]);
                                    @endphp

                                    <tr wire:key="{{ $key }}" @class(['bg-sh-muted' => $isVariant])>
                                        <td @class([
                                            'sticky left-0 z-10 py-3 pr-3',
                                            'bg-sh-surface pl-4' => ! $isVariant,
                                            'bg-sh-muted pl-10' => $isVariant,
                                        ])>
                                            <div class="flex items-start gap-3">
                                                @if ($canEdit)
                                                    <x-filament::input.checkbox
                                                        wire:model.live="selected"
                                                        :value="$key"
                                                        class="mt-0.5"
                                                        :aria-label="__('shopper-pricelist::messages.select_row', ['name' => $priceable->name])"
                                                    />
                                                @endif
                                                @if (! $isVariant)
                                                    @if ($product->variants_count > 0)
                                                        <button
                                                            type="button"
                                                            wire:click="toggle({{ $product->id }})"
                                                            class="rounded text-sh-fg-muted hover:text-sh-fg"
                                                            aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                                                            aria-label="{{ __('shopper-pricelist::messages.toggle_variants', ['name' => $product->name]) }}"
                                                        >
                                                            @svg($isExpanded ? 'untitledui-chevron-down' : 'untitledui-chevron-right', 'size-5', ['aria-hidden' => true])
                                                        </button>
                                                    @else
                                                        <span class="size-5" aria-hidden="true"></span>
                                                    @endif
                                                @endif
                                                <div class="min-w-0">
                                                    <p class="truncate font-medium text-sh-fg">{{ $priceable->name }}</p>
                                                    <p class="text-xs text-sh-fg-muted">
                                                        @if ($isVariant)
                                                            {{ $priceable->sku }}
                                                        @elseif ($product->variants_count > 0)
                                                            {{ trans_choice('shopper-pricelist::messages.variants_count', $product->variants_count, ['priced' => $product->priced_variants_count]) }}
                                                        @else
                                                            {{ __('shopper-pricelist::messages.no_variant') }}
                                                        @endif
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        @foreach ($visibleCurrencies as $currency)
                                            @php
                                                $field = 'cells.'.$key.'.'.$currency->code;
                                                $hint = $hints[$key][$currency->code];
                                            @endphp

                                            <td wire:key="{{ $key }}-{{ $currency->code }}" class="px-3 py-3">
                                                <div class="w-40 space-y-1">
                                                    <x-filament::input.wrapper :valid="! $errors->has($field)" :disabled="! $canEdit">
                                                        <x-filament::input
                                                            type="text"
                                                            inputmode="decimal"
                                                            wire:model.blur="{{ $field }}"
                                                            :placeholder="$hint['placeholder']"
                                                            :disabled="! $canEdit"
                                                            :aria-label="$priceable->name.' '.$currency->code"
                                                            :aria-invalid="$errors->has($field) ? 'true' : null"
                                                            class="tabular-nums"
                                                        />
                                                    </x-filament::input.wrapper>
                                                    @error($field)
                                                        <p class="text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                                    @enderror
                                                    @if ($hint['catalog'])
                                                        <p class="text-xs text-sh-fg-muted tabular-nums">
                                                            {{ __('shopper-pricelist::messages.catalog_price', ['amount' => $hint['catalog']]) }}
                                                        </p>
                                                    @endif
                                                    @if ($canEdit)
                                                        @if ($hint['tiers'] > 0)
                                                            <x-filament::badge
                                                                tag="button"
                                                                size="sm"
                                                                color="gray"
                                                                wire:click="mountAction('tiers', {{ Js::from(['key' => $key, 'code' => $currency->code]) }})"
                                                            >
                                                                {{ trans_choice('shopper-pricelist::messages.tiers_count', $hint['tiers']) }}
                                                            </x-filament::badge>
                                                        @else
                                                            <button
                                                                type="button"
                                                                wire:click="mountAction('tiers', {{ Js::from(['key' => $key, 'code' => $currency->code]) }})"
                                                                class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                                                            >
                                                                {{ __('shopper-pricelist::messages.actions.add_tier_short') }}
                                                            </button>
                                                        @endif
                                                    @elseif ($hint['tiers'] > 0)
                                                        <x-filament::badge size="sm" color="gray">
                                                            {{ trans_choice('shopper-pricelist::messages.tiers_count', $hint['tiers']) }}
                                                        </x-filament::badge>
                                                    @endif
                                                </div>
                                            </td>
                                        @endforeach

                                        <td class="px-3 py-3 whitespace-nowrap">
                                            @php
                                                $summary = $rule === null ? null : collect([
                                                    __('shopper-pricelist::messages.quantity_min', ['count' => $rule->minimum]),
                                                    $rule->maximum === null ? null : __('shopper-pricelist::messages.quantity_max', ['count' => $rule->maximum]),
                                                    $rule->increment > 1 ? 'x'.$rule->increment : null,
                                                ])->filter()->implode(', ');
                                                $label = $summary ?? ($inheritsRule ? __('shopper-pricelist::messages.inherited') : __('shopper-pricelist::messages.actions.add_rule'));
                                            @endphp

                                            @if ($canEdit)
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('quantityRule', {{ Js::from(['key' => $key]) }})"
                                                    @class([
                                                        'text-sm',
                                                        'text-sh-fg' => $summary !== null,
                                                        'text-sh-fg-muted' => $inheritsRule,
                                                        'font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400' => $summary === null && ! $inheritsRule,
                                                    ])
                                                >
                                                    {{ $label }}
                                                </button>
                                            @elseif ($summary !== null || $inheritsRule)
                                                <span class="text-sm text-sh-fg-muted">{{ $label }}</span>
                                            @endif
                                        </td>

                                        <td class="py-3 pr-4 pl-3 text-right">
                                            @if ($canEdit)
                                                <x-filament-actions::group
                                                    :actions="[($this->removeAction)(['key' => $key])]"
                                                    icon="untitledui-dots-horizontal"
                                                    color="gray"
                                                    :label="__('shopper::forms.actions.more_actions')"
                                                    dropdown-placement="bottom-end"
                                                />
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @empty
                            <tbody>
                                <tr>
                                    <td colspan="{{ $visibleCurrencies->count() + 3 }}" class="px-4 py-12 text-center text-sm text-sh-fg-muted">
                                        {{ filled($search) ? __('shopper-pricelist::messages.prices_no_match') : __('shopper-pricelist::messages.prices_empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        @endforelse
                    </table>
                </div>

                @if ($this->products->hasPages())
                    <div
                        @class([
                            'border-t border-sh-border px-4 py-3',
                            'pointer-events-none opacity-50' => $changes > 0,
                        ])
                        @if ($changes > 0) inert @endif
                    >
                        <x-filament::pagination :paginator="$this->products" />
                    </div>
                @endif
            </div>
        </div>
    </x-shopper::container>

    @if ($changes > 0)
        <div class="sticky bottom-0 z-20 border-t border-sh-border bg-sh-surface">
            <x-shopper::container class="flex items-center justify-between gap-4 py-3">
                <p class="text-sm font-medium text-sh-fg" role="status">
                    {{ trans_choice('shopper-pricelist::messages.unsaved_changes', $changes) }}
                </p>
                <div class="flex items-center gap-3">
                    <x-filament::button color="gray" wire:click="resetDraft">
                        {{ __('shopper::forms.actions.cancel') }}
                    </x-filament::button>
                    <x-filament::button wire:click="save" wire:target="save">
                        {{ __('shopper::forms.actions.save') }}
                    </x-filament::button>
                </div>
            </x-shopper::container>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
