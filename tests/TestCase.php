<?php

declare(strict_types=1);

namespace Shopper\PriceList\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Laravelcm\LivewireSlideOvers\LivewireSlideOverServiceProvider;
use Livewire\LivewireServiceProvider;
use Mckenziearts\BladeUntitledUIIcons\BladeUntitledUIIconsServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PDO;
use Shopper\Cart\CartServiceProvider;
use Shopper\Contracts\ShopperAddon;
use Shopper\Core\CoreServiceProvider;
use Shopper\Core\Database\Seeders\CurrenciesTableSeeder;
use Shopper\CustomerGroups\CustomerGroupsAddon;
use Shopper\CustomerGroups\CustomerGroupsServiceProvider;
use Shopper\Payment\PaymentServiceProvider;
use Shopper\PriceList\PriceListAddon;
use Shopper\PriceList\PriceListServiceProvider;
use Shopper\PriceList\Tests\Stubs\User;
use Shopper\ShopperServiceProvider;
use Shopper\Sidebar\SidebarServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use TailwindMerge\Laravel\TailwindMergeServiceProvider;

use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = CurrenciesTableSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->freezeSecond();
        Sleep::fake(syncWithCarbon: true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeUntitledUIIconsServiceProvider::class,
            LivewireServiceProvider::class,
            LivewireSlideOverServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            PermissionServiceProvider::class,
            TailwindMergeServiceProvider::class,
            MediaLibraryServiceProvider::class,
            CoreServiceProvider::class,
            ShopperServiceProvider::class,
            SidebarServiceProvider::class,
            CartServiceProvider::class,
            PaymentServiceProvider::class,
            CustomerGroupsServiceProvider::class,
            PriceListServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('auth.providers.users.model', User::class);
        $config->set('cache.default', 'array');
        $config->set('database.connections.testing.foreign_key_constraints', true);

        $connection = env('DB_CONNECTION', 'testing');

        if ($connection === 'pgsql') {
            $token = (string) env('TEST_TOKEN', '');

            $config->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('PGSQL_PORT', '5432'),
                'database' => env('DB_DATABASE', 'pricelist_testing').($token !== '' ? "_{$token}" : ''),
                'username' => env('PGSQL_USERNAME', 'postgres'),
                'password' => env('PGSQL_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]);

            $this->ensureDatabaseExists($config->get('database.connections.pgsql'));
        }

        $config->set('database.default', $connection);

        $app->afterResolving('migrator', function ($migrator): void {
            $migrator->path(default_migration_path());
            $migrator->path(__DIR__.'/database/migrations');
        });

        $app->booting(function (Application $app): void {
            $app->make('shopper')->addons($this->addons());
        });
    }

    /**
     * @return list<ShopperAddon>
     */
    protected function addons(): array
    {
        return [new CustomerGroupsAddon, new PriceListAddon];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensureDatabaseExists(array $config): void
    {
        $pdo = new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname=postgres",
            $config['username'],
            $config['password'],
        );

        if ($pdo->query("select 1 from pg_database where datname = '{$config['database']}'")->fetchColumn() === false) {
            $pdo->exec("create database \"{$config['database']}\"");
        }
    }
}
