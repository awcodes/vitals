<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Tests;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\TimedUptimeCheckRequest;
use Awcodes\Vitals\VitalsServiceProvider;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\UptimeMonitor\Notifications\Notifications\CertificateCheckFailed;
use Spatie\UptimeMonitor\Notifications\Notifications\CertificateCheckSucceeded;
use Spatie\UptimeMonitor\Notifications\Notifications\CertificateExpiresSoon;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckFailed;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckRecovered;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckSucceeded;
use Spatie\UptimeMonitor\UptimeMonitorServiceProvider;
use Workbench\App\Providers\Filament\AdminPanelProvider;

abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        // Strict models catch lazy loading and missing attributes. Dates stay
        // mutable (Laravel's default), so the suite also proves the package
        // does not depend on an app calling Date::use(CarbonImmutable::class).
        Model::shouldBeStrict();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            UptimeMonitorServiceProvider::class,
            AdminPanelProvider::class,
            VitalsServiceProvider::class,
        ];
    }

    /**
     * The three uptime-monitor settings the installation docs ask apps to set.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('uptime-monitor.monitor_model', Monitor::class);
        $app['config']->set('uptime-monitor.actions.send_uptime_check_request', TimedUptimeCheckRequest::class);
        $app['config']->set('uptime-monitor.notifications.resend_uptime_check_failed_notification_every_minutes', 0);
        $app['config']->set('uptime-monitor.notifications.notifications', [
            UptimeCheckFailed::class => ['mail'],
            UptimeCheckRecovered::class => ['mail'],
            UptimeCheckSucceeded::class => [],
            CertificateCheckFailed::class => ['mail'],
            CertificateExpiresSoon::class => ['mail'],
            CertificateCheckSucceeded::class => [],
        ]);
        $app['config']->set('mail.default', 'array');
    }

    /**
     * The suite migrates its own copies of the stubs from tests/database. The
     * Workbench publishes the same schema into the Testbench skeleton's
     * database/migrations, and pointing the database path here keeps the two
     * out of the same migration run.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->app?->useDatabasePath(__DIR__ . '/database');
    }
}
