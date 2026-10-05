<?php

declare(strict_types=1);

namespace Shopper\PriceList;

use Shopper\Core\Traits\HasRegisterConfigAndMigrationFiles;
use Shopper\PriceList\Console\InstallCommand;
use Shopper\PriceList\Console\RebuildIndexCommand;
use Shopper\PriceList\Console\RecordPricesCommand;
use Shopper\PriceList\Pricing\ActivePriceLists;
use Shopper\PriceList\Pricing\ApplicableLists;
use Shopper\PriceList\Pricing\PriceListLookup;
use Shopper\PriceList\Rules\ApplicabilityRuleManager;
use Shopper\PriceList\Rules\ChannelRule;
use Shopper\PriceList\Rules\CustomerGroupRule;
use Shopper\PriceList\Rules\CustomerRule;
use Shopper\PriceList\Rules\ZoneRule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class PriceListServiceProvider extends PackageServiceProvider
{
    use HasRegisterConfigAndMigrationFiles;

    protected string $root = __DIR__.'/..';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('shopper-pricelist')
            ->hasTranslations()
            ->hasViews()
            ->hasCommands([
                InstallCommand::class,
                RebuildIndexCommand::class,
                RecordPricesCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->registerDatabase();

        $this->app->singleton(ApplicabilityRuleManager::class, function (): ApplicabilityRuleManager {
            $rules = new ApplicabilityRuleManager;
            $rules->register(new CustomerRule);
            $rules->register(new ChannelRule);
            $rules->register(new ZoneRule);

            return $rules;
        });

        $this->app->scoped(ActivePriceLists::class);
        $this->app->scoped(ApplicableLists::class);
        $this->app->scoped(PriceListLookup::class);
        $this->app->scoped(CustomerGroupRule::class);
    }
}
