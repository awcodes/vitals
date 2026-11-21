<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Actions;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Awcodes\Vitals\Support\UptimeCheckTimings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

final readonly class RecordUptimeCheckAction
{
    public function __construct(private UptimeCheckTimings $timings) {}

    /**
     * Stores the check Spatie just applied to the monitor and keeps the
     * monitor's incident in step with it: one open incident per confirmed
     * outage, closed by the first passing check.
     *
     * Runs after Spatie has saved the monitor, so the monitor's uptime
     * columns already describe this check.
     */
    public function handle(Monitor $monitor): MonitorCheck
    {
        $timing = $this->timings->pull($monitor->id);
        $succeeded = $monitor->uptime_status === UptimeStatus::UP;
        $checkedAt = $monitor->uptime_last_check_date ?? Date::now();

        $check = MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => $checkedAt,
            'succeeded' => $succeeded,
            'http_status' => $timing['http_status'],
            'response_time_ms' => $succeeded ? $timing['response_time_ms'] : null,
            'failure_reason' => $succeeded ? null : $this->truncate($monitor->uptime_check_failure_reason),
        ]);

        $synced = Cache::lock("monitor-incident:{$monitor->id}", 10)->get(fn (): bool => $this->syncIncident($monitor, $check));

        if ($synced !== true) {
            Log::warning('Skipped incident update because another check of this monitor held the lock; the next check will catch up.', [
                'monitor_id' => $monitor->id,
            ]);
        }

        return $check;
    }

    /**
     * Runs inside a non-blocking lock. A check that can't get the lock skips
     * this step rather than throwing, which would abort Spatie's whole
     * check run; incident state is derived from the monitor, so the next
     * check repairs it.
     */
    private function syncIncident(Monitor $monitor, MonitorCheck $check): bool
    {
        $openIncident = MonitorIncident::query()
            ->whereBelongsTo($monitor, 'monitor')
            ->open()
            ->latest('started_at')
            ->first();

        if ($check->succeeded) {
            $openIncident?->update(['resolved_at' => $check->checked_at]);

            return true;
        }

        if ($openIncident instanceof MonitorIncident || ! $monitor->hasConfirmedOutage()) {
            return true;
        }

        MonitorIncident::query()->create([
            'monitor_id' => $monitor->id,
            'started_at' => $monitor->uptime_status_last_change_date ?? $check->checked_at,
            'failure_reason' => $check->failure_reason,
        ]);

        return true;
    }

    private function truncate(?string $reason): ?string
    {
        return blank($reason) ? null : mb_strimwidth($reason, 0, 255, '…');
    }
}
