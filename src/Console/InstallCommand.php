<?php

declare(strict_types=1);

namespace Shopper\PriceList\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

#[AsCommand(name: 'shopper:pricelist:install')]
final class InstallCommand extends Command
{
    protected $signature = 'shopper:pricelist:install';

    protected $description = 'Install Shopper Price Lists: run migrations and register the addon';

    public function handle(): int
    {
        intro('Installing Shopper Price Lists');

        $this->task('Running database migrations', function (): void {
            $this->callSilently('migrate', ['--force' => true]);
        });

        $this->task('Indexing and recording the current public prices', function (): void {
            $this->callSilently('shopper:pricelist:rebuild-index');
        });

        $this->registerAddon();

        outro('Shopper Price Lists is installed. Manage price lists in Products → Price lists.');

        return self::SUCCESS;
    }

    private function task(string $title, Closure $callback): void
    {
        spin(callback: $callback, message: $title);

        $width = 52;
        $dots = str_repeat('.', max(1, $width - mb_strlen($title)));

        $this->output->writeln("  <fg=#94A3B8>{$title}</> <fg=#334155>{$dots}</> <fg=#22C55E>✓</>");
    }

    private function registerAddon(): void
    {
        $path = app_path('Providers/AppServiceProvider.php');

        if (! File::exists($path)) {
            $this->manualRegistrationNotice();

            return;
        }

        $contents = File::get($path);

        if (str_contains($contents, 'PriceListAddon')) {
            note('PriceListAddon is already registered in AppServiceProvider.');

            return;
        }

        $updated = $this->addImports($contents);
        $updated = $updated === null ? null : $this->addAddonRegistration($updated);

        if ($updated === null) {
            $this->manualRegistrationNotice();

            return;
        }

        $this->task('Registering PriceListAddon in AppServiceProvider', function () use ($path, $updated): void {
            File::put($path, $updated);
        });
    }

    private function addImports(string $contents): ?string
    {
        $missing = array_filter(
            ['use Shopper\Facades\Shopper;', 'use Shopper\PriceList\PriceListAddon;'],
            fn (string $import): bool => ! str_contains($contents, $import),
        );

        if ($missing === []) {
            return $contents;
        }

        if (preg_match('/^(?:use (?!function |const )[^;]+;\R)+/m', $contents, $block) !== 1) {
            $updated = preg_replace('/^(namespace [^;]+;\R\R?)/m', '$1'.implode("\n", $missing)."\n", $contents, 1, $count);

            return $count === 1 ? $updated : null;
        }

        $imports = collect(preg_split('/\R/', $block[0], -1, PREG_SPLIT_NO_EMPTY))
            ->merge($missing)
            ->sort(fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)))
            ->implode("\n");

        return Str::replaceFirst($block[0], $imports."\n", $contents);
    }

    private function addAddonRegistration(string $contents): ?string
    {
        if (str_contains($contents, 'Shopper::addons([')) {
            return Str::replaceFirst(
                'Shopper::addons([',
                "Shopper::addons([\n            new PriceListAddon,",
                $contents,
            );
        }

        $patched = preg_replace(
            '/(public function register\(\): void\s*\{)/',
            "$1\n        Shopper::addons([\n            new PriceListAddon,\n        ]);\n",
            $contents,
            1,
            $count,
        );

        return $count === 1 ? $patched : null;
    }

    private function manualRegistrationNotice(): void
    {
        warning('Could not update AppServiceProvider automatically. Register the addon manually:');
        note('Shopper::addons([new \Shopper\PriceList\PriceListAddon]);');
    }
}
