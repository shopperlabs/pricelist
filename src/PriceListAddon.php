<?php

declare(strict_types=1);

namespace Shopper\PriceList;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Shopper\Addon\BaseAddon;
use Shopper\Core\Contracts\PriceResolver;
use Shopper\Core\Contracts\ProductPriceIndex;
use Shopper\Core\Contracts\QuantityRuleResolver;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\PriceList\Contracts\ApplicabilityRule;
use Shopper\PriceList\Livewire\PriceListForm;
use Shopper\PriceList\Livewire\PriceListIndex;
use Shopper\PriceList\Livewire\PriceListPrices;
use Shopper\PriceList\Livewire\PriceListSimulator;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListPrice;
use Shopper\PriceList\Models\PriceListQuantityRule;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Observers\CatalogItemObserver;
use Shopper\PriceList\Observers\CatalogPriceObserver;
use Shopper\PriceList\Observers\PriceListObserver;
use Shopper\PriceList\Observers\PriceListPriceObserver;
use Shopper\PriceList\Observers\PriceListRuleObserver;
use Shopper\PriceList\Pricing\PriceListLookup;
use Shopper\PriceList\Pricing\PriceListPriceResolver;
use Shopper\PriceList\Pricing\PriceListProductPriceIndex;
use Shopper\PriceList\Pricing\PriceListQuantityRuleResolver;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\PriceList\Rules\CustomerGroupRule;
use Shopper\PriceList\Sidebar\PriceListSidebar;
use Shopper\ShopperPanel;

final class PriceListAddon extends BaseAddon
{
    /** @var array<string, class-string> */
    private array $componentOverrides = [];

    /** @var list<ApplicabilityRule|class-string<ApplicabilityRule>> */
    private array $rules = [];

    public function getId(): string
    {
        return 'pricelist';
    }

    /**
     * @param  array<string, class-string>  $components
     */
    public function usingLivewireComponents(array $components): static
    {
        $this->componentOverrides = array_merge($this->componentOverrides, $components);

        return $this;
    }

    /**
     * @param  list<ApplicabilityRule|class-string<ApplicabilityRule>>  $rules
     */
    public function rules(array $rules): static
    {
        $this->rules = array_merge($this->rules, $rules);

        return $this;
    }

    public function register(ShopperPanel $panel): void
    {
        app()->extend(PriceResolver::class, fn (PriceResolver $resolver): PriceResolver => new PriceListPriceResolver($resolver));
        app()->extend(QuantityRuleResolver::class, fn (QuantityRuleResolver $resolver): QuantityRuleResolver => new PriceListQuantityRuleResolver($resolver));
        app()->extend(ProductPriceIndex::class, fn (ProductPriceIndex $index): ProductPriceIndex => new PriceListProductPriceIndex($index));

        $components = $this->livewireComponents();

        $panel
            ->addonLivewireComponents($components)
            ->addonSidebar(PriceListSidebar::class)
            ->addonRoutes(function () use ($components): void {
                Route::prefix('products')
                    ->group(function () use ($components): void {
                        Route::get('/price-lists', $components['pricelist.index'])->name('price-lists');
                        Route::get('/price-lists/{priceList}', $components['pricelist.prices'])->name('price-lists.show');
                    });
            });
    }

    public function boot(ShopperPanel $panel): void
    {
        PriceList::observe(PriceListObserver::class);
        PriceListRule::observe(PriceListRuleObserver::class);
        PriceListPrice::observe(PriceListPriceObserver::class);
        PriceListQuantityRule::saved(fn () => app()->forgetInstance(PriceListLookup::class));
        PriceListQuantityRule::deleted(fn () => app()->forgetInstance(PriceListLookup::class));
        Price::observe(CatalogPriceObserver::class);
        Product::observeUsingConfiguredClass(CatalogItemObserver::class);
        ProductVariant::observeUsingConfiguredClass(CatalogItemObserver::class);

        $this->schedule();

        $rules = app(ApplicabilityRuleManager::class);

        if ($panel->hasAddon('customer-groups')) {
            $rules->register(CustomerGroupRule::class);
        }

        foreach ($this->rules as $rule) {
            $rules->register($rule);
        }
    }

    private function schedule(): void
    {
        $schedule = fn (Schedule $schedule) => $schedule->command('shopper:pricelist:record-prices')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onOneServer();

        app()->resolved(Schedule::class)
            ? $schedule(app(Schedule::class))
            : app()->afterResolving(Schedule::class, $schedule);
    }

    /**
     * @return array<string, class-string>
     */
    private function livewireComponents(): array
    {
        $components = [
            'pricelist.index' => PriceListIndex::class,
            'pricelist.form' => PriceListForm::class,
            'pricelist.prices' => PriceListPrices::class,
            'pricelist.simulator' => PriceListSimulator::class,
        ];

        return array_merge($components, array_intersect_key($this->componentOverrides, $components));
    }
}
