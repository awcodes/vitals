---
title: Installation
description: Install Vitals, connect it to spatie/laravel-uptime-monitor and register the plugin on a Filament panel.
---

# Installation

## Compatibility

| Filament version | Package version |
|------------------|-----------------|
| 4.x & 5.x        | 0.x             |

Vitals requires PHP 8.3 or later, Laravel 12 or 13, the `intl` extension and `filament/filament`.

## Install the package

Require the package with Composer. This also installs `spatie/laravel-uptime-monitor` if your application does not use it yet.

```bash
composer require awcodes/vitals
```

## Publish the config and migrations

Run the install command:

```bash
php artisan vitals:install
```

It publishes, in order:

1. `config/uptime-monitor.php` and Spatie's `create_monitors_table` migration, but only when your application does not have them yet. Existing files and an existing `monitors` table are left alone.
2. `config/vitals.php`.
3. The Vitals migrations. They add five columns to `monitors` (`name`, `server_url`, `paused_at`, `paused_until`, `pause_reason`) and create the `monitor_checks`, `monitor_incidents` and `monitor_daily_stats` tables.

The command then offers to run the migrations. You can also run them yourself:

```bash
php artisan migrate
```

Every Vitals migration skips a table or column that already exists, so running the install command again, or installing into an application that already has some of these tables, is safe. See [Upgrading](upgrading.md) if you are moving from a monitoring page of your own.

## Point Spatie at Vitals

Vitals never edits `config/uptime-monitor.php`, so set these keys yourself. The install command prints them as a reminder.

```php
// config/uptime-monitor.php

return [
    'notifications' => [
        // ...

        // Recommended: one notification when an outage is confirmed and one
        // when it recovers, nothing in between.
        'resend_uptime_check_failed_notification_every_minutes' => 0,
    ],

    // ...

    'monitor_model' => \Awcodes\Vitals\Models\Monitor::class,

    'actions' => [
        'send_uptime_check_request' => \Awcodes\Vitals\Support\TimedUptimeCheckRequest::class,
    ],
];
```

- `monitor_model` is required. Vitals records checks, opens and closes incidents, and blocks checks for paused monitors from its `Monitor` model, which extends Spatie's.
- `send_uptime_check_request` is required. It records each check's HTTP status and response time, and stops redirects to private network addresses.

Both can be subclasses, if your application already customises Spatie's model or request action. See [Upgrading](upgrading.md#from-a-custom-monitoring-page).
- `resend_uptime_check_failed_notification_every_minutes` is recommended. Spatie's default re-sends a failure notification every 60 minutes during an outage.

## Register the plugin

Add the plugin to your panel provider:

```php
use Awcodes\Vitals\VitalsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(VitalsPlugin::make());
}
```

This adds the Vitals page to the panel's navigation and the alerts widget to its dashboard. See [Configuration](configuration.md) for the plugin's options.

## Add the views to your theme

The Vitals views use Tailwind classes, so your panel needs a [custom theme](https://filamentphp.com/docs/5.x/styling/overview#creating-a-custom-theme) that scans them. Add this line to the theme's CSS file, after its `@import` lines:

```css
@source '../../../../vendor/awcodes/vitals/resources/**/*.blade.php';
```

Then rebuild your assets:

```bash
npm run build
```

> [!NOTE]
> The path is relative to the theme file. The line above suits a theme at `resources/css/filament/admin/theme.css`; adjust the number of `../` segments if yours lives elsewhere.

A small stylesheet for table and slide-over spacing is registered as a Filament asset. Publish it with the rest of Filament's assets:

```bash
php artisan filament:assets
```

It is published to `public/css/awcodes/vitals/vitals.css`. If your application git-ignores Filament's published assets directory by directory, add `/public/css/awcodes` to `.gitignore` as well.

## Schedule the checks

Vitals schedules nothing by itself. Register its schedule in `routes/console.php`:

```php
use Awcodes\Vitals\Vitals;

Vitals::schedule();
```

Or, if your application schedules in `bootstrap/app.php`, pass the `Schedule` that `withSchedule()` gives you:

```php
use Awcodes\Vitals\Vitals;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withSchedule(function (Schedule $schedule): void {
        Vitals::schedule($schedule);
    })
    ->create();
```

[Scheduling](scheduling.md) lists what this registers, if you would rather add the entries yourself.

## Check the installation

```bash
php artisan vitals:doctor
```

The command reports the uptime-monitor settings, tables and columns that are missing, and warns when a panel using the plugin has no theme that scans the Vitals views. It changes nothing, and exits with a failure code when a required setting is missing, so it also works in CI.

Open the panel and choose **Vitals** in the navigation. Add a monitor with **Add monitor**, or load demo data as described in [Usage](usage.md#demo-data).
