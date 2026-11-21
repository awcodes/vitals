<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Commands;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\TimedUptimeCheckRequest;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reports what a Vitals installation is missing. Read-only: it changes no
 * configuration, file or table.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'vitals:doctor';

    /** @var string */
    protected $description = 'Check the uptime-monitor settings, database tables and panel themes Vitals depends on.';

    private int $failures = 0;

    public function handle(): int
    {
        $this->checkUptimeMonitorConfig();
        $this->checkSchema();
        $this->checkThemes();

        if ($this->failures > 0) {
            $this->newLine();
            $this->components->error("{$this->failures} required " . str('setting')->plural($this->failures) . ' missing. See the installation docs.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Vitals is set up.');

        return self::SUCCESS;
    }

    private function checkUptimeMonitorConfig(): void
    {
        $this->components->twoColumnDetail('<fg=gray>config/uptime-monitor.php</>');

        $model = config('uptime-monitor.monitor_model');
        $this->report(
            is_string($model) && is_a($model, Monitor::class, true),
            'monitor_model is ' . Monitor::class,
            "Set 'monitor_model' => " . Monitor::class . '::class',
        );

        $request = config('uptime-monitor.actions.send_uptime_check_request');
        $this->report(
            is_string($request) && is_a($request, TimedUptimeCheckRequest::class, true),
            'actions.send_uptime_check_request is ' . TimedUptimeCheckRequest::class,
            "Set 'actions.send_uptime_check_request' => " . TimedUptimeCheckRequest::class . '::class',
        );

        $resend = config('uptime-monitor.notifications.resend_uptime_check_failed_notification_every_minutes');
        $this->report(
            (int) $resend === 0,
            'Failure notifications are sent once per outage',
            "Recommended: set 'notifications.resend_uptime_check_failed_notification_every_minutes' => 0",
            required: false,
        );
    }

    private function checkSchema(): void
    {
        $this->components->twoColumnDetail('<fg=gray>Database</>');

        try {
            $hasMonitors = Schema::hasTable('monitors');
        } catch (Throwable $throwable) {
            $this->report(false, '', 'Could not connect to the database: ' . $throwable->getMessage());

            return;
        }

        $this->report($hasMonitors, 'monitors table exists', "Publish and run spatie/laravel-uptime-monitor's create_monitors_table migration");

        if ($hasMonitors) {
            $missing = array_values(array_filter(
                ['name', 'server_url', 'paused_at', 'paused_until', 'pause_reason'],
                fn (string $column): bool => ! Schema::hasColumn('monitors', $column),
            ));

            $this->report($missing === [], 'monitors has the Vitals columns', 'monitors is missing ' . implode(', ', $missing) . '; run php artisan vitals:install and migrate');
        }

        foreach (['monitor_checks', 'monitor_incidents', 'monitor_daily_stats'] as $table) {
            $this->report(Schema::hasTable($table), "{$table} table exists", "{$table} is missing; run php artisan vitals:install and migrate");
        }
    }

    /**
     * Best effort: the Vitals views use Tailwind classes, so each panel that
     * uses the plugin needs a custom theme that scans the package's views.
     */
    private function checkThemes(): void
    {
        $panels = array_filter(Filament::getPanels(), fn (Panel $panel): bool => $panel->hasPlugin('vitals'));

        if ($panels === []) {
            return;
        }

        $this->components->twoColumnDetail('<fg=gray>Panel themes</>');

        foreach ($panels as $panel) {
            $theme = $panel->getViteTheme();
            $files = array_values(array_filter((array) $theme, fn (string $file): bool => str_ends_with($file, '.css')));

            if ($files === []) {
                if ($panel->getTheme()->getId() !== $panel->getDefaultTheme()->getId()) {
                    $this->components->twoColumnDetail("Panel [{$panel->getId()}] uses a theme not built with Vite; check that it scans vendor/awcodes/vitals", '<fg=gray;options=bold>SKIPPED</>');

                    continue;
                }

                $this->report(false, '', "Panel [{$panel->getId()}] has no custom theme, so the Vitals views are unstyled. Create one and add the package's @source line", required: false);

                continue;
            }

            $scans = collect($files)->contains(function (string $file): bool {
                $path = base_path($file);

                return is_file($path) && str_contains((string) file_get_contents($path), 'vendor/awcodes/vitals');
            });

            $this->report($scans, "Panel [{$panel->getId()}] theme scans the Vitals views", "Panel [{$panel->getId()}] theme has no @source line for vendor/awcodes/vitals", required: false);
        }
    }

    private function report(bool $passes, string $success, string $problem, bool $required = true): void
    {
        if ($passes) {
            $this->components->twoColumnDetail($success, '<fg=green;options=bold>OK</>');

            return;
        }

        if ($required) {
            $this->failures++;
        }

        $this->components->twoColumnDetail($problem, $required ? '<fg=red;options=bold>MISSING</>' : '<fg=yellow;options=bold>WARN</>');
    }
}
