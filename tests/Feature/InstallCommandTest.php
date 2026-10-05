<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Shopper\Core\Models\Product;
use Shopper\PriceList\Models\PriceHistory;

const PROVIDER_STUB = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
PHP;

function providerPath(): string
{
    return app_path('Providers/AppServiceProvider.php');
}

beforeEach(function (): void {
    $this->originalProvider = File::exists(providerPath()) ? File::get(providerPath()) : null;
    File::ensureDirectoryExists(dirname(providerPath()));
});

afterEach(function (): void {
    if ($this->originalProvider !== null) {
        File::put(providerPath(), $this->originalProvider);
    }
});

it('registers the addon inside a provider without an addons block', function (): void {
    File::put(providerPath(), PROVIDER_STUB);

    $this->artisan('shopper:pricelist:install')->assertSuccessful();

    $contents = File::get(providerPath());

    expect($contents)->toContain("use Illuminate\\Support\\ServiceProvider;\nuse Shopper\\Facades\\Shopper;\nuse Shopper\\PriceList\\PriceListAddon;\n\nclass")
        ->and($contents)->toContain("Shopper::addons([\n            new PriceListAddon,\n        ]);");
});

it('records the current price of every product before any worker runs', function (): void {
    config(['queue.default' => 'database']);
    File::put(providerPath(), PROVIDER_STUB);
    withCatalogPrice(Product::factory()->create(), 10000);
    PriceHistory::query()->delete();

    $this->artisan('shopper:pricelist:install')->assertSuccessful();

    expect(PriceHistory::query()->pluck('amount')->all())->toBe([10000]);
});

it('registers the addon inside a provider with Windows line endings', function (): void {
    File::put(providerPath(), str_replace("\n", "\r\n", PROVIDER_STUB));

    $this->artisan('shopper:pricelist:install')->assertSuccessful();

    expect(File::get(providerPath()))->toContain('use Shopper\PriceList\PriceListAddon;')
        ->and(File::get(providerPath()))->toContain('new PriceListAddon,');
});

it('leaves a provider it cannot add the imports to untouched', function (): void {
    File::put(providerPath(), $provider = str_replace(["namespace App\Providers;\n\n", "use Illuminate\Support\ServiceProvider;\n\n"], '', PROVIDER_STUB));

    $this->artisan('shopper:pricelist:install')->assertSuccessful();

    expect(File::get(providerPath()))->toBe($provider);
});

it('appends the addon to an existing addons block and stays idempotent', function (): void {
    File::put(providerPath(), str_replace(
        "public function register(): void\n    {\n    }",
        "public function register(): void\n    {\n        Shopper::addons([\n            new PosAddon,\n        ]);\n    }",
        PROVIDER_STUB,
    ));

    $this->artisan('shopper:pricelist:install')->assertSuccessful();
    $after = File::get(providerPath());

    $this->artisan('shopper:pricelist:install')->assertSuccessful();

    expect($after)->toContain('new PriceListAddon,')
        ->and($after)->toContain('new PosAddon,')
        ->and(File::get(providerPath()))->toBe($after);
});

it('registers the addon in the first addons block only', function (): void {
    File::put(providerPath(), str_replace(
        "public function register(): void\n    {\n    }",
        "public function register(): void\n    {\n        Shopper::addons([\n            new PosAddon,\n        ]);\n\n        if (app()->isLocal()) {\n            Shopper::addons([\n                new DebugAddon,\n            ]);\n        }\n    }",
        PROVIDER_STUB,
    ));

    $this->artisan('shopper:pricelist:install')->assertSuccessful();

    expect(mb_substr_count(File::get(providerPath()), 'new PriceListAddon,'))->toBe(1);
});
