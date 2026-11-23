---
title: Configuration
description: Configure Vitals through config/vitals.php, the plugin's fluent options and the uptime-monitor settings it relies on.
---

# Configuration

Vitals reads settings from three places: `config/vitals.php`, the plugin's options on each panel, and Spatie's `config/uptime-monitor.php`, which still controls the checks themselves.

## `config/vitals.php`

`php artisan vitals:install` publishes this file. Every key has a default, so publishing it is optional.

| Key | Default | Meaning |
| --- | --- | --- |
| `timezone` | `null` | The timezone dates are shown in. `null` falls back to Filament's configured timezone, then to `app.timezone`. |
| `stale_after_minutes` | `45` | A monitor whose last uptime check is older than this shows as Unknown ("Stale") rather than Healthy. Keep it comfortably above the interval at which `monitor:check-uptime` runs. |
| `slow_response_ms` | `3000` | The latest response time above which an otherwise passing monitor shows as Degraded. |
| `history_days` | `30` | The number of days in the history strip and the uptime percentage. |
| `attention_limit` | `5` | The most monitors listed under **Needs attention** before "View all" appears. |
| `allow_private_addresses` | `false` | Whether monitors may point at private network addresses. See [Security](security.md#monitoring-internal-services). |
| `policy` | `MonitorPolicy::class` | The policy registered for the `Monitor` model. See [Authorization](authorization.md). |
| `retention.enabled` | `true` | Whether `monitors:prune-history` prunes anything. |
| `retention.checks_days` | `30` | Completed days of raw checks to keep. |
| `retention.daily_stats_days` | `365` | Days of daily summaries to keep. |
| `retention.resolved_incidents_days` | `365` | Days to keep an incident after it is resolved. |

`allow_private_addresses` can be set with `VITALS_ALLOW_PRIVATE_ADDRESSES`. The retention keys can also be set from the environment with `VITALS_PRUNING_ENABLED`, `VITALS_CHECK_RETENTION_DAYS`, `VITALS_DAILY_STATS_RETENTION_DAYS` and `VITALS_INCIDENT_RETENTION_DAYS`. [Retention](retention.md) explains how they work together.

## Plugin options

Each option defaults to the value shown, so `VitalsPlugin::make()` on its own gives you a page called Vitals at `/{panel}/vitals`.

```php
use Awcodes\Vitals\VitalsPlugin;

$panel->plugin(
    VitalsPlugin::make()
        ->navigationLabel('Vitals')
        ->navigationIcon('vitals-heartbeat')
        ->navigationGroup(null)
        ->navigationSort(100)
        ->slug('vitals')
        ->timezone(null)
        ->alertsWidget(true)
);
```

- `navigationLabel()` sets both the navigation item and the page title.
- `navigationIcon()` accepts any Blade icon name or an icon enum such as `Heroicon::OutlinedSignal`. The default, `vitals-heartbeat`, ships with the package. Pass `null` for no icon, for a panel whose navigation carries none.
- `navigationGroup()` accepts a string or an enum.
- `slug()` sets the page's URL segment.
- `timezone()` overrides `vitals.timezone` for this panel.
- `alertsWidget(false)` leaves the alerts widget off the panel's dashboard.

The options are per panel, so two panels can register the plugin with different labels or timezones.

## Timezone

Dates on the page, in the slide-over and in the pause form use the first of these that is set:

1. The plugin's `->timezone()` on the current panel.
2. `vitals.timezone`.
3. Filament's configured timezone.
4. `app.timezone`.

Stored timestamps are not affected. Daily history and summaries are built from UTC days.

## Settings that stay in `config/uptime-monitor.php`

Vitals reads two of Spatie's settings rather than duplicating them:

- `uptime_check.fire_monitor_failed_event_after_consecutive_failures` (Spatie's default is `2`) is the number of failed checks in a row that confirms an outage. Below it, a failing monitor shows as Degraded; at it, the monitor shows as Down and an incident opens.
- `certificate_check.fire_expiring_soon_event_if_certificate_expires_within_days` (default `10`) decides when a certificate shows as Expiring soon.

Notification channels, check concurrency and timeouts are configured in Spatie's file as usual. [Installation](installation.md#point-spatie-at-vitals) lists the keys Vitals needs set there.
