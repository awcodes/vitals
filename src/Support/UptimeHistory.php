<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Support;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Daily uptime buckets for every monitor over the history window, built from
 * one grouped query over monitor_checks and one over monitor_incidents.
 * Create one per request and share it across table rows.
 *
 * A day is "down" when a confirmed incident overlaps it, "degraded" when it
 * had failed checks without an incident, "slow" when every check passed but
 * the average response was above vitals.slow_response_ms, "up" when
 * every check passed at normal speed and "none" when there are no checks.
 */
final class UptimeHistory
{
    /** @var array<int, array<string, array{total: int, failed: int, avg_ms: ?float}>>|null */
    private ?array $buckets = null;

    /** @var Collection<array-key, EloquentCollection<int, MonitorIncident>>|null */
    private ?Collection $incidents = null;

    public function __construct(private readonly int $days = 30) {}

    public static function make(): self
    {
        return new self((int) config('vitals.history_days'));
    }

    /** @return list<array{date: CarbonImmutable, state: string, total: int, failed: int, label: string}> */
    public function days(Monitor $monitor): array
    {
        $buckets = $this->buckets()[$monitor->id] ?? [];
        $incidents = $this->incidents()->get($monitor->id, collect());

        $days = [];

        foreach ($this->dates() as $date) {
            $bucket = $buckets[$date->toDateString()] ?? ['total' => 0, 'failed' => 0, 'avg_ms' => null];

            $hadIncident = $incidents->contains(fn (MonitorIncident $incident): bool => $incident->started_at->lte($date->endOfDay())
                && ($incident->resolved_at === null || $incident->resolved_at->gte($date->startOfDay())));

            $state = match (true) {
                $hadIncident => 'down',
                $bucket['total'] === 0 => 'none',
                $bucket['failed'] > 0 => 'degraded',
                $bucket['avg_ms'] !== null && $bucket['avg_ms'] > (int) config('vitals.slow_response_ms') => 'slow',
                default => 'up',
            };

            $days[] = [
                'date' => $date,
                'state' => $state,
                'total' => $bucket['total'],
                'failed' => $bucket['failed'],
                'label' => $this->label($date, $state, $bucket['total'], $bucket['failed'], $bucket['avg_ms']),
            ];
        }

        return $days;
    }

    /**
     * Mean response time of the successful checks in the window, weighted by
     * how many succeeded each day. Failed checks have no response time and
     * are left out rather than counted as zero.
     */
    public function averageResponseMs(Monitor $monitor): ?int
    {
        $successful = 0;
        $totalMs = 0.0;

        foreach ($this->buckets()[$monitor->id] ?? [] as $bucket) {
            if ($bucket['avg_ms'] === null) {
                continue;
            }

            $count = $bucket['total'] - $bucket['failed'];
            $successful += $count;
            $totalMs += $bucket['avg_ms'] * $count;
        }

        return $successful === 0 ? null : (int) round($totalMs / $successful);
    }

    public function checkCount(Monitor $monitor): int
    {
        return (int) collect($this->buckets()[$monitor->id] ?? [])->sum('total');
    }

    /**
     * The first day inside the window with any checks, or null without any.
     */
    public function firstRecordedDay(Monitor $monitor): ?CarbonImmutable
    {
        $days = array_keys($this->buckets()[$monitor->id] ?? []);

        if ($days === []) {
            return null;
        }

        sort($days);

        return Date::parse($days[0])->toImmutable();
    }

    public function uptimePercentage(Monitor $monitor): ?float
    {
        $buckets = collect($this->buckets()[$monitor->id] ?? []);
        $total = $buckets->sum('total');

        if ($total === 0) {
            return null;
        }

        return round(($total - $buckets->sum('failed')) / $total * 100, 2);
    }

    /** @return list<array{date: CarbonImmutable, avg_ms: ?int}> */
    public function responseTrend(Monitor $monitor): array
    {
        $buckets = $this->buckets()[$monitor->id] ?? [];

        return array_map(fn (CarbonImmutable $date): array => [
            'date' => $date,
            'avg_ms' => isset($buckets[$date->toDateString()]['avg_ms']) ? (int) round($buckets[$date->toDateString()]['avg_ms']) : null,
        ], $this->dates());
    }

    /** @return list<CarbonImmutable> */
    private function dates(): array
    {
        $today = Date::today()->toImmutable();

        return array_map(fn (int $offset): CarbonImmutable => $today->subDays($offset), range($this->days - 1, 0));
    }

    private function windowStart(): CarbonImmutable
    {
        return Date::today()->toImmutable()->subDays($this->days - 1);
    }

    /** @return array<int, array<string, array{total: int, failed: int, avg_ms: ?float}>> */
    private function buckets(): array
    {
        if ($this->buckets !== null) {
            return $this->buckets;
        }

        $this->buckets = [];

        MonitorCheck::query()
            ->toBase()
            ->selectRaw('monitor_id, date(checked_at) as day, count(*) as total, sum(case when succeeded then 0 else 1 end) as failed, avg(response_time_ms) as avg_ms')
            ->where('checked_at', '>=', $this->windowStart())
            ->groupBy('monitor_id', 'day')
            ->get()
            ->each(function (object $row): void {
                $this->buckets[(int) $row->monitor_id][(string) $row->day] = [
                    'total' => (int) $row->total,
                    'failed' => (int) $row->failed,
                    'avg_ms' => $row->avg_ms === null ? null : (float) $row->avg_ms,
                ];
            });

        return $this->buckets;
    }

    /** @return Collection<array-key, EloquentCollection<int, MonitorIncident>> */
    private function incidents(): Collection
    {
        return $this->incidents ??= MonitorIncident::query()
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $this->windowStart()))
            ->get()
            ->groupBy('monitor_id');
    }

    private function label(CarbonImmutable $date, string $state, int $total, int $failed, ?float $averageMs): string
    {
        $day = $date->format('M j');

        return match ($state) {
            'none' => "{$day}: no data",
            'up' => "{$day}: {$total} checks, all passed",
            'slow' => "{$day}: {$total} checks passed, slow (average " . number_format((int) round((float) $averageMs)) . ' ms)',
            'degraded' => "{$day}: {$failed} of {$total} checks failed",
            default => "{$day}: outage, {$failed} of {$total} checks failed",
        };
    }
}
