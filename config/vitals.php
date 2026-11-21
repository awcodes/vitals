<?php

declare(strict_types=1);

use Awcodes\Vitals\Policies\MonitorPolicy;

/*
 * Vitals settings, layered on top of spatie/laravel-uptime-monitor. The check
 * engine itself is configured in config/uptime-monitor.php.
 */
return [
    /*
     * The timezone dates are shown in. Null falls back to Filament's
     * configured timezone, then to app.timezone. The plugin's ->timezone()
     * takes precedence over this value.
     */
    'timezone' => null,

    /*
     * A monitor whose last uptime check is older than this is shown as
     * Unknown rather than Healthy. Keep it comfortably above the interval at
     * which monitor:check-uptime runs, so a late or skipped run doesn't flag
     * every site.
     */
    'stale_after_minutes' => 45,

    /*
     * The latest response time above which an otherwise passing monitor is
     * shown as Degraded.
     */
    'slow_response_ms' => 3000,

    /*
     * Number of days shown in the uptime history strip and used for the
     * uptime percentage.
     */
    'history_days' => 30,

    /*
     * The most monitors listed in the alerts widget.
     */
    'attention_limit' => 5,

    /*
     * The policy registered for the Monitor model, unless the application
     * has already registered its own.
     */
    'policy' => MonitorPolicy::class,

    /*
     * Data retention, enforced by monitors:prune-history. Completed days of
     * checks are summarised into monitor_daily_stats before any raw check is
     * deleted. checks_days must be at least history_days, or raw checks are
     * not pruned at all.
     */
    'retention' => [
        'enabled' => (bool) env('VITALS_PRUNING_ENABLED', true),
        'checks_days' => (int) env('VITALS_CHECK_RETENTION_DAYS', 30),
        'daily_stats_days' => (int) env('VITALS_DAILY_STATS_RETENTION_DAYS', 365),
        'resolved_incidents_days' => (int) env('VITALS_INCIDENT_RETENTION_DAYS', 365),
    ],
];
