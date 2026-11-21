<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Actions;

use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorDailyStat;
use Awcodes\Vitals\Models\MonitorIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

final readonly class PruneMonitoringHistoryAction
{
    public function __construct(private AggregateMonitorChecksAction $aggregate) {}

    /**
     * Aggregates completed days, then deletes raw checks older than the
     * retention period (whole UTC days, and only where that day's summary
     * matches the raw rows), resolved incidents past their retention, and
     * expired daily summaries. Open incidents and monitors are never
     * touched. With $dryRun nothing is written, aggregation included.
     *
     * @return array{
     *     dry_run: bool,
     *     enabled: bool,
     *     cutoffs: array{checks: CarbonImmutable, incidents: CarbonImmutable, daily_stats: CarbonImmutable},
     *     aggregated_days: int,
     *     checks: int,
     *     unaggregated_days: int,
     *     blocked_days: int,
     *     checks_skipped: ?string,
     *     incidents: int,
     *     open_incidents_kept: int,
     *     daily_stats: int,
     * }
     */
    public function handle(bool $dryRun = false): array
    {
        $checksDays = (int) config('vitals.retention.checks_days');
        $historyDays = (int) config('vitals.history_days');

        $report = [
            'dry_run' => $dryRun,
            'enabled' => (bool) config('vitals.retention.enabled'),
            'cutoffs' => [
                'checks' => Date::today()->subDays($checksDays)->toImmutable(),
                'incidents' => Date::now()->subDays((int) config('vitals.retention.resolved_incidents_days'))->toImmutable(),
                'daily_stats' => Date::today()->subDays((int) config('vitals.retention.daily_stats_days'))->toImmutable(),
            ],
            'aggregated_days' => 0,
            'checks' => 0,
            'unaggregated_days' => 0,
            'blocked_days' => 0,
            'checks_skipped' => $checksDays < $historyDays
                ? "Raw check retention ({$checksDays} days) is shorter than the page history window ({$historyDays} days)."
                : null,
            'incidents' => 0,
            'open_incidents_kept' => MonitorIncident::query()->open()->where('started_at', '<', Date::now()->subDays((int) config('vitals.retention.resolved_incidents_days')))->count(),
            'daily_stats' => 0,
        ];

        if (! $report['enabled'] && ! $dryRun) {
            return $report;
        }

        if (! $dryRun) {
            $report['aggregated_days'] = $this->aggregate->handle();
        }

        if ($report['checks_skipped'] === null) {
            [$report['checks'], $report['unaggregated_days']] = $this->pruneChecks($report['cutoffs']['checks'], $dryRun);
            $report['blocked_days'] = $dryRun ? 0 : $report['unaggregated_days'];
        }

        $incidents = MonitorIncident::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $report['cutoffs']['incidents']);
        $dailyStats = MonitorDailyStat::query()->where('date', '<', $report['cutoffs']['daily_stats']);

        $report['incidents'] = $dryRun ? $incidents->count() : $incidents->delete();
        $report['daily_stats'] = $dryRun ? $dailyStats->count() : $dailyStats->delete();

        if (! $dryRun && $report['blocked_days'] > 0) {
            Log::warning('Monitoring history pruning skipped monitor-days without a matching daily summary.', [
                'blocked_days' => $report['blocked_days'],
                'cutoff' => $report['cutoffs']['checks']->toDateString(),
            ]);
        }

        return $report;
    }

    /**
     * Deletes whole monitor-days of raw checks whose summary exists and
     * counts exactly the raw rows present. Returns the checks deleted (or,
     * in a dry run, that would be) and the monitor-days left alone.
     *
     * @return array{int, int}
     */
    private function pruneChecks(CarbonImmutable $cutoff, bool $dryRun): array
    {
        $summaries = MonitorDailyStat::query()
            ->where('date', '<', $cutoff)
            ->get(['monitor_id', 'date', 'total_checks'])
            ->mapWithKeys(fn (MonitorDailyStat $stat): array => [$stat->monitor_id . '|' . $stat->date->toDateString() => $stat->total_checks]);

        $days = MonitorCheck::query()
            ->toBase()
            ->selectRaw('monitor_id, date(checked_at) as day, count(*) as total')
            ->where('checked_at', '<', $cutoff)
            ->groupBy('monitor_id', 'day')
            ->get();

        $deleted = 0;
        $unaggregated = 0;

        foreach ($days as $day) {
            $total = (int) $day->total;

            if ($summaries->get($day->monitor_id . '|' . $day->day) !== $total) {
                $unaggregated++;

                continue;
            }

            if ($dryRun) {
                $deleted += $total;

                continue;
            }

            $start = Date::parse((string) $day->day)->toImmutable()->startOfDay();

            $deleted += MonitorCheck::query()
                ->where('monitor_id', $day->monitor_id)
                ->where('checked_at', '>=', $start)
                ->where('checked_at', '<', $start->addDay())
                ->delete();
        }

        return [$deleted, $unaggregated];
    }
}
