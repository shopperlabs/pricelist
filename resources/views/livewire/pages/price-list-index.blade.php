<div>
    <x-shopper::container class="space-y-8 py-5">
        <x-shopper::heading>
            <x-slot name="title">
                <div class="space-y-1">
                    <h2 class="font-heading text-2xl font-bold text-sh-fg sm:truncate sm:text-3xl">
                        {{ __('shopper-pricelist::messages.title') }}
                    </h2>
                    <p class="max-w-2xl text-sm text-sh-fg-secondary">
                        {{ __('shopper-pricelist::messages.description') }}
                    </p>
                </div>
            </x-slot>

            <x-slot name="action">
                {{ $this->createPriceListAction }}
            </x-slot>
        </x-shopper::heading>

        {{ $this->table }}
    </x-shopper::container>

    <x-filament-actions::modals />
</div>
