<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Awcodes\Vitals\Commands\PruneMonitoringHistoryCommand;
use Awcodes\Vitals\Commands\ResumeExpiredMonitorPausesCommand;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\UptimeCheckTimings;
use BladeUI\Icons\Factory as IconFactory;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VitalsServiceProvider extends PackageServiceProvider
{
    /**
     * Published into the app in this order, so the monitors columns exist
     * before anything references them. Spatie's own create_monitors_table
     * migration must already be published.
     *
     * @var list<string>
     */
    public const array MIGRATIONS = [
        'add_vitals_columns_to_monitors_table',
        'create_vitals_monitor_checks_table',
        'create_vitals_monitor_incidents_table',
        'create_vitals_monitor_daily_stats_table',
    ];

    public static string $name = 'vitals';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations(self::MIGRATIONS)
            ->hasCommands([
                PruneMonitoringHistoryCommand::class,
                ResumeExpiredMonitorPausesCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // Transfer stats travel from the uptime request to the check recorder
        // within one check run, so they must not outlive it.
        $this->app->scoped(UptimeCheckTimings::class);

        $this->callAfterResolving(IconFactory::class, function (IconFactory $icons): void {
            $icons->add('vitals', [
                'path' => __DIR__ . '/../resources/svg',
                'prefix' => 'vitals',
            ]);
        });
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            Css::make('vitals', __DIR__ . '/../resources/css/vitals.css'),
        ], 'awcodes/vitals');

        $this->registerPolicy();
    }

    /**
     * Leaves a policy the app registered for the Monitor model alone. An app
     * provider that registers one later replaces this registration anyway.
     */
    protected function registerPolicy(): void
    {
        if (array_key_exists(Monitor::class, Gate::policies())) {
            return;
        }

        $policy = config('vitals.policy');

        if (is_string($policy) && class_exists($policy)) {
            Gate::policy(Monitor::class, $policy);
        }
    }
}
