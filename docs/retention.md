---
title: Retention
description: How Vitals summarises and prunes check history, and how to configure and operate it.
---

# Retention

Vitals records a row for every uptime check. At a check every 15 minutes that is 96 rows per site per day, so old checks are pruned. Before any check is deleted, its day is summarised, so long-term availability and performance survive.

## What is kept

| Data | Kept | After that |
| --- | --- | --- |
| Individual checks | Today plus `retention.checks_days` completed UTC days (30 by default) | Deleted. The day's summary remains. |
| Daily summaries | `retention.daily_stats_days` (365) | Deleted. |
| Resolved incidents | `retention.resolved_incidents_days` (365) after resolution | Deleted. |
| Open incidents | Until resolved | Then as resolved incidents. |
| Certificate state | Current only, on `monitors` | Nothing older is stored. |
| Monitors | Indefinitely | — |

A daily summary in `monitor_daily_stats` holds one monitor's checks for one UTC day: the total and failed checks, and the average, minimum and maximum response time of the successful checks. Failed checks have no response time and are never counted as zero. A day without checks, for example while a monitor is paused, gets no row.

## How pruning works

`monitors:prune-history` runs these steps in order:

1. **Guard.** If `retention.checks_days` is shorter than `history_days`, raw checks are not pruned at all, so the history strip and uptime percentage always come from raw checks.
2. **Aggregate.** Every completed UTC day that still has raw checks is summarised and upserted. Today is never summarised.
3. **Raw checks.** Checks older than the cutoff are deleted one whole UTC day per monitor at a time, and only when that day's summary exists and counts exactly the raw rows present. Any other day is reported as *blocked* and left alone.
4. **Resolved incidents** older than their retention are deleted. Open incidents are never deleted, however old.
5. **Daily summaries** older than their retention are deleted.

Every step is safe to repeat. Aggregation upserts, and deletes only remove what was verified, so an interrupted run needs no repair: run the command again.

## Configuration

| Key | Env | Default |
| --- | --- | --- |
| `retention.enabled` | `VITALS_PRUNING_ENABLED` | `true` |
| `retention.checks_days` | `VITALS_CHECK_RETENTION_DAYS` | `30` |
| `retention.daily_stats_days` | `VITALS_DAILY_STATS_RETENTION_DAYS` | `365` |
| `retention.resolved_incidents_days` | `VITALS_INCIDENT_RETENTION_DAYS` | `365` |

## Operating it

Preview a run without writing anything, aggregation included:

```bash
php artisan monitors:prune-history --dry-run
```

It prints the cutoffs, how many rows would be deleted, and how many monitor-days are not yet summarised. A real run summarises those first, so they are normally pruned too.

Run it for real:

```bash
php artisan monitors:prune-history
```

- **Blocked days.** A monitor-day whose summary does not match its raw checks is kept and logged as a warning. The next run summarises it again first, so a block that persists means checks are being written with old timestamps; investigate before changing anything.
- **Turning pruning off.** With `VITALS_PRUNING_ENABLED=false` the command only reports. Raw checks then grow until pruning is turned back on.
- **Recovery.** Deleted rows cannot be recovered without a database backup.

> [!NOTE]
> Nothing on the Vitals page reads the daily summaries yet. They exist so history beyond the 30-day window is not lost.
