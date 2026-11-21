<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Commands;

use Awcodes\Vitals\Actions\PruneMonitoringHistoryAction;
use Illuminate\Console\Command;

final class PruneMonitoringHistoryCommand extends Command
{
    /** @var string */
    protected $signature = 'monitors:prune-history {--dry-run : Report what would be aggregated and deleted without writing anything}';

    /** @var string */
    protected $description = 'Summarise completed days of monitoring checks, then prune raw checks, resolved incidents and summaries past their retention.';

    public function handle(PruneMonitoringHistoryAction $prune): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $prune->handle($dryRun);

        if (! $report['enabled'] && ! $dryRun) {
            $this->warn('Monitoring history pruning is disabled (vitals.retention.enabled). Nothing was changed.');

            return self::SUCCESS;
        }

        if ($report['checks_skipped'] !== null) {
            $this->warn($report['checks_skipped'] . ' Raw checks were not pruned.');
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';

        $this->table(['', 'Cutoff', 'Count'], [
            [$dryRun ? 'Days to aggregate first' : 'Days aggregated', '—', $dryRun ? 'all completed days with raw checks' : $report['aggregated_days']],
            ["{$verb} raw checks", 'before ' . $report['cutoffs']['checks']->toDateString(), $report['checks']],
            [$dryRun ? 'Monitor-days not yet aggregated' : 'Monitor-days blocked', '—', $report['unaggregated_days']],
            ["{$verb} resolved incidents", 'resolved before ' . $report['cutoffs']['incidents']->toDateTimeString(), $report['incidents']],
            ['Open incidents kept past cutoff', '—', $report['open_incidents_kept']],
            ["{$verb} daily summaries", 'before ' . $report['cutoffs']['daily_stats']->toDateString(), $report['daily_stats']],
        ]);

        if ($dryRun && $report['unaggregated_days'] > 0) {
            $this->line('A real run aggregates first, so those monitor-days are normally summarised and pruned too.');
        }

        if (! $dryRun && $report['blocked_days'] > 0) {
            $this->warn("{$report['blocked_days']} monitor-days were kept because their summary did not match the raw checks.");
        }

        return self::SUCCESS;
    }
}
