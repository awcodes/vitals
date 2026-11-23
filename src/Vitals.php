<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Scheduling\Schedule;

final class Vitals
{
    /**
     * The timezone dates are shown in: the current panel's ->timezone() on
     * the plugin, then vitals.timezone, then Filament's configured timezone,
     * which itself falls back to app.timezone.
     */
    public static function timezone(): string
    {
        $plugin = VitalsPlugin::current()?->getTimezone();

        if (is_string($plugin) && $plugin !== '') {
            return $plugin;
        }

        $configured = config('vitals.timezone');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $filament = FilamentTimezone::get();

        return $filament !== '' ? $filament : (string) config('app.timezone', 'UTC');
    }

    /**
     * Registers the schedule Vitals is designed around. The package never
     * schedules anything itself; call this from routes/console.php, or from
     * withSchedule() in bootstrap/app.php with the Schedule it passes, or
     * copy the four entries and adjust them.
     *
     * The overlap locks expire well before the next run, so a process that
     * dies mid-run doesn't block the following one.
     */
    public static function schedule(?Schedule $schedule = null): void
    {
        $schedule ??= resolve(Schedule::class);

        $schedule->command('monitors:resume-expired-pauses')->everyFiveMinutes();
        $schedule->command('monitor:check-uptime')->everyFifteenMinutes()->withoutOverlapping(14);
        $schedule->command('monitor:check-certificate')->daily()->withoutOverlapping(120);
        $schedule->command('monitors:prune-history')->dailyAt('03:30')->withoutOverlapping(60);
    }
}
