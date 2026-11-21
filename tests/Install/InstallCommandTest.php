<?php

declare(strict_types=1);

use Awcodes\Vitals\Tests\InstallTestCase;
use Illuminate\Support\Facades\File;

function install(): void
{
    test()->artisan('vitals:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->assertSuccessful();
}

/** @return list<string> */
function installedNames(): array
{
    return collect(InstallTestCase::installedFiles())
        ->map(fn (string $path): string => str_contains($path, '/config/') ? 'config/' . basename($path) : preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)))
        ->sort()
        ->values()
        ->all();
}

it('publishes the uptime-monitor and Vitals config and migrations', function (): void {
    $this->artisan('vitals:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->expectsOutputToContain("'monitor_model' => \\Awcodes\\Vitals\\Models\\Monitor::class")
        ->expectsOutputToContain('vitals:doctor')
        ->assertSuccessful();

    expect(installedNames())->toBe([
        'add_vitals_columns_to_monitors_table.php',
        'config/uptime-monitor.php',
        'config/vitals.php',
        'create_monitors_table.php',
        'create_vitals_monitor_checks_table.php',
        'create_vitals_monitor_daily_stats_table.php',
        'create_vitals_monitor_incidents_table.php',
    ]);
});

it('orders create_monitors_table before the Vitals migrations', function (): void {
    install();

    $migrations = collect(File::glob(database_path('migrations/*.php')))
        ->map(fn (string $path): string => basename($path))
        ->filter(fn (string $name): bool => str_contains($name, 'monitor'))
        ->sort()
        ->values();

    expect($migrations->first())->toEndWith('_create_monitors_table.php')
        ->and($migrations->get(1))->toEndWith('_add_vitals_columns_to_monitors_table.php');
});

it('does not duplicate anything when run twice', function (): void {
    install();
    $first = InstallTestCase::installedFiles();

    install();

    expect(InstallTestCase::installedFiles())->toEqualCanonicalizing($first);
});

it('leaves an existing uptime-monitor migration and config alone', function (): void {
    File::put(database_path('migrations/2020_01_01_000000_create_monitors_table.php'), '<?php // existing');
    File::put(config_path('uptime-monitor.php'), '<?php return [];');

    install();

    expect(File::glob(database_path('migrations/*_create_monitors_table.php')))->toHaveCount(1)
        ->and(File::get(config_path('uptime-monitor.php')))->toBe('<?php return [];');
});
