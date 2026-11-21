---
title: Upgrading
description: Move an application with its own spatie/laravel-uptime-monitor page or history tables onto Vitals.
---

# Upgrading

## From a custom monitoring page

Applications that already run `spatie/laravel-uptime-monitor` usually have a `monitors` table, a `Monitor` model of their own and a page or widget that lists monitors. Vitals is designed to take over from those without losing data.

1. **Install the package** as described in [Installation](installation.md). `vitals:install` leaves your existing `config/uptime-monitor.php` and `create_monitors_table` migration alone.
2. **Run the migrations.** They only add what is missing: any of the five monitor columns (`name`, `server_url`, `paused_at`, `paused_until`, `pause_reason`) and any of the three history tables. Existing monitors keep their current state.
3. **Point Spatie at Vitals** by setting `monitor_model` and `actions.send_uptime_check_request` in `config/uptime-monitor.php`, replacing your own classes. The Vitals model is final, so behaviour your own model added has to live elsewhere; relations can be attached with Eloquent's `Monitor::resolveRelationUsing()`.
4. **Replace your schedule entries** for `monitor:check-uptime` and `monitor:check-certificate` with `Vitals::schedule()`, or keep your own entries and add the two Vitals commands. See [Scheduling](scheduling.md). Make sure each check is scheduled only once.
5. **Register the plugin and add the theme `@source` line**, then remove your old page, widget and their tests.
6. **Run `php artisan vitals:doctor`** to confirm the setup.

Monitors without a `name` show their host in the alerts widget and the needs-attention list, and an empty name above the host in the table, until you edit them. If you have many, backfill `name` from the host in a migration of your own.

History starts with the first check after the switch: Spatie never stored past checks, so the history strips fill in over the following 30 days.

## Existing history tables

If your application already has `monitor_checks`, `monitor_incidents` or `monitor_daily_stats` tables, the Vitals migrations skip them. This is only safe when their columns match the Vitals schema. Compare them with the stubs in the package's `database/migrations` directory before migrating.
