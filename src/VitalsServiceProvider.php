<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Awcodes\Vitals\Commands\DoctorCommand;
use Awcodes\Vitals\Commands\PruneMonitoringHistoryCommand;
use Awcodes\Vitals\Commands\ResumeExpiredMonitorPausesCommand;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\UptimeCheckTimings;
use BladeUI\Icons\Factory as IconFactory;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\UptimeMonitor\UptimeMonitorServiceProvider;
use Throwable;

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
                DoctorCommand::class,
                PruneMonitoringHistoryCommand::class,
                ResumeExpiredMonitorPausesCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->startWith(fn (InstallCommand $command) => $this->publishUptimeMonitorResources($command))
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->endWith(fn (InstallCommand $command) => $this->printChecklist($command));
            });
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
     * Vitals builds on spatie/laravel-uptime-monitor's config and monitors
     * table, so publish them first when the app hasn't yet. Existing files
     * and tables are left alone.
     */
    protected function publishUptimeMonitorResources(InstallCommand $command): void
    {
        if (! File::exists(config_path('uptime-monitor.php'))) {
            $command->comment('Publishing the uptime-monitor config file...');
            $command->callSilently('vendor:publish', ['--provider' => UptimeMonitorServiceProvider::class, '--tag' => 'config']);
        }

        if ($this->hasMonitorsMigration()) {
            return;
        }

        // Copied rather than published through Spatie's tag: Spatie stamps the
        // file when its provider boots, which can land on the same second as
        // the Vitals migrations and sort after them. A minute earlier keeps
        // create_monitors_table first.
        $stub = dirname((string) (new ReflectionClass(UptimeMonitorServiceProvider::class))->getFileName()) . '/../database/migrations/create_monitors_table.php.stub';
        $target = database_path('migrations/' . Date::now()->subMinute()->format('Y_m_d_His') . '_create_monitors_table.php');

        File::ensureDirectoryExists(dirname($target));
        File::copy($stub, $target);

        $command->comment('Published the uptime-monitor migration.');
    }

    protected function hasMonitorsMigration(): bool
    {
        if (File::glob(database_path('migrations/*_create_monitors_table.php')) !== []) {
            return true;
        }

        try {
            return Schema::hasTable('monitors');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The package never edits config/uptime-monitor.php, so the app sets
     * these itself. vitals:doctor reports any that are missing.
     */
    protected function printChecklist(InstallCommand $command): void
    {
        $command->newLine();
        $command->line('Set these in config/uptime-monitor.php:');
        $command->line("  'monitor_model' => \\Awcodes\\Vitals\\Models\\Monitor::class,");
        $command->line("  'actions' => ['send_uptime_check_request' => \\Awcodes\\Vitals\\Support\\TimedUptimeCheckRequest::class],");
        $command->line("  'notifications' => ['resend_uptime_check_failed_notification_every_minutes' => 0, ...],  // recommended");
        $command->newLine();
        $command->line('Then run php artisan vitals:doctor to check the installation.');
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
