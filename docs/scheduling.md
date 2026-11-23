---
title: Scheduling
description: Schedule the uptime checks, certificate checks, pause expiry and history pruning Vitals depends on.
---

# Scheduling

Vitals never adds anything to your scheduler on its own. Your application decides when checks run, either through the `Vitals::schedule()` helper or by adding the entries itself.

## The helper

```php
use Awcodes\Vitals\Vitals;

// routes/console.php
Vitals::schedule();
```

Called with no argument, it registers on the application's schedule. In `bootstrap/app.php`, pass the `Schedule` that `withSchedule()` provides:

```php
use Awcodes\Vitals\Vitals;
use Illuminate\Console\Scheduling\Schedule;

// bootstrap/app.php
->withSchedule(function (Schedule $schedule): void {
    Vitals::schedule($schedule);
})
```

It registers exactly these four entries:

```php
$schedule->command('monitors:resume-expired-pauses')->everyFiveMinutes();
$schedule->command('monitor:check-uptime')->everyFifteenMinutes()->withoutOverlapping(14);
$schedule->command('monitor:check-certificate')->daily()->withoutOverlapping(120);
$schedule->command('monitors:prune-history')->dailyAt('03:30')->withoutOverlapping(60);
```

| Command | From | What it does |
| --- | --- | --- |
| `monitors:resume-expired-pauses` | Vitals | Resumes monitors whose pause has reached its resume time. |
| `monitor:check-uptime` | Spatie | Checks every monitor that is due. Vitals records each result. |
| `monitor:check-certificate` | Spatie | Inspects certificates. Paused monitors are skipped. |
| `monitors:prune-history` | Vitals | Summarises completed days, then prunes old history. See [Retention](retention.md). |

Each overlap lock expires before the command's next run, so a process that dies mid-run does not block the next one.

## Adding the entries yourself

Copy the four lines into your own schedule to change any of them, and leave out `Vitals::schedule()`. Keep these in mind:

- `vitals.stale_after_minutes` (45 by default) should stay comfortably above the uptime-check interval, or monitors show as Stale between runs.
- Spatie's `uptime_check.run_interval_in_minutes` decides how often each monitor is due. If the scheduler runs `monitor:check-uptime` less often than that, the scheduler interval is the real one.
- Without `monitors:resume-expired-pauses`, a pause's resume time is never acted on.
- Without `monitors:prune-history`, raw checks are kept indefinitely.
