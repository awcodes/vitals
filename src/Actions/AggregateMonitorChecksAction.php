<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Actions;

use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorDailyStat;
use Illuminate\Support\Facades\Date;

final class AggregateMonitorChecksAction
{
    /**
     * Recomputes the daily summary for every completed UTC day that still
     * has raw checks, and upserts it. Safe to run any number of times; a
     * day's summary always reflects the raw checks present at the time.
     * Today is never aggregated because it is still in progress.
     *
     * Dates are written as start-of-day timestamps to match the model's
     * date cast, because upsert bypasses casts and SQLite compares dates
     * as text.
     *
     * @return int Number of monitor-days written
     */
    public function handle(): int
    {
        $now = Date::now();

        $rows = MonitorCheck::query()
            ->toBase()
            ->selectRaw('monitor_id, date(checked_at) as day, count(*) as total_checks')
            ->selectRaw('sum(case when succeeded then 0 else 1 end) as failed_checks')
            ->selectRaw('avg(case when succeeded then response_time_ms end) as avg_response_ms')
            ->selectRaw('min(case when succeeded then response_time_ms end) as min_response_ms')
            ->selectRaw('max(case when succeeded then response_time_ms end) as max_response_ms')
            ->where('checked_at', '<', Date::today())
            ->groupBy('monitor_id', 'day')
            ->get()
            ->map(fn (object $row): array => [
                'monitor_id' => (int) $row->monitor_id,
                'date' => Date::parse((string) $row->day)->startOfDay(),
                'total_checks' => (int) $row->total_checks,
                'failed_checks' => (int) $row->failed_checks,
                'avg_response_ms' => $row->avg_response_ms === null ? null : (int) round((float) $row->avg_response_ms),
                'min_response_ms' => $row->min_response_ms === null ? null : (int) $row->min_response_ms,
                'max_response_ms' => $row->max_response_ms === null ? null : (int) $row->max_response_ms,
                'aggregated_at' => $now,
            ]);

        foreach ($rows->chunk(500) as $chunk) {
            MonitorDailyStat::query()->upsert(
                $chunk->values()->all(),
                uniqueBy: ['monitor_id', 'date'],
                update: ['total_checks', 'failed_checks', 'avg_response_ms', 'min_response_ms', 'max_response_ms', 'aggregated_at'],
            );
        }

        return $rows->count();
    }
}
