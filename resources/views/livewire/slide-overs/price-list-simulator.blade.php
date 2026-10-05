<x-shopper::slideover-card>
    <form wire:submit="simulate" class="flex h-full flex-col">
        <header class="p-4">
            <div class="flex items-start justify-between">
                <h2 class="text-lg font-medium text-sh-fg">
                    {{ __('shopper-pricelist::messages.simulator.heading') }}
                </h2>
                <x-livewire-slide-over::close-icon />
            </div>
            <p class="mt-1 text-sm text-sh-fg-muted">
                {{ __('shopper-pricelist::messages.simulator.description') }}
            </p>
        </header>

        <div class="h-0 flex-1 space-y-6 overflow-y-auto px-4 pt-2 pb-6">
            {{ $this->form }}

            <div aria-live="polite">
                @if ($result !== null)
                    @if ($result['amount'] === null)
                        <p class="rounded-xl bg-sh-muted p-4 text-sm text-sh-fg-muted">
                            {{ __('shopper-pricelist::messages.simulator.no_price') }}
                        </p>
                    @else
                        <x-shopper::description-list class="rounded-xl px-4 ring-1 ring-sh-border">
                            <x-shopper::description-list.item
                                class="py-4"
                                :heading="__('shopper-pricelist::messages.simulator.amount')"
                            >
                                <span class="text-base font-semibold text-sh-fg tabular-nums">{{ $result['amount'] }}</span>
                            </x-shopper::description-list.item>

                            @if ($result['compare'])
                                <x-shopper::description-list.item
                                    class="py-4"
                                    :heading="__('shopper-pricelist::messages.simulator.compare_amount')"
                                >
                                    <span class="tabular-nums line-through">{{ $result['compare'] }}</span>
                                </x-shopper::description-list.item>
                            @endif

                            <x-shopper::description-list.item
                                class="py-4"
                                :heading="__('shopper-pricelist::messages.simulator.winner')"
                                :content="$result['list'] ?? __('shopper-pricelist::messages.simulator.catalog')"
                            />

                            @if ($result['source'])
                                <x-shopper::description-list.item
                                    class="py-4"
                                    :heading="__('shopper-pricelist::messages.simulator.source')"
                                    :content="$result['source']"
                                />
                            @endif
                        </x-shopper::description-list>
                    @endif
                @endif
            </div>
        </div>

        <div class="flex shrink-0 justify-end gap-3 border-t border-sh-border p-4">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="simulate">
                {{ __('shopper-pricelist::messages.actions.simulate') }}
            </x-filament::button>
        </div>
    </form>
</x-shopper::slideover-card>
