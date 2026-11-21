<?php

declare(strict_types=1);

use Awcodes\Vitals\Actions\AggregateMonitorChecksAction;
use Awcodes\Vitals\Actions\PruneMonitoringHistoryAction;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorDailyStat;
use Awcodes\Vitals\Models\MonitorIncident;
use Awcodes\Vitals\Support\UptimeHistory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Date::setTestNow('2026-11-21 12:00:00');
    $this->monitor = Monitor::factory()->create(['uptime_last_check_date' => Date::now()]);
});

function checkAt(Monitor $monitor, string $at, bool $succeeded = true, ?int $ms = 200): MonitorCheck
{
    return MonitorCheck::factory()->for($monitor)->create([
        'checked_at' => Date::parse($at),
        'succeeded' => $succeeded,
        'response_time_ms' => $succeeded ? $ms : null,
        'failure_reason' => $succeeded ? null : 'Connection refused',
    ]);
}

function statFor(Monitor $monitor, string $date): ?MonitorDailyStat
{
    return MonitorDailyStat::query()->whereBelongsTo($monitor)->whereDate('date', $date)->first();
}

describe('aggregation', function (): void {
    it('summarises completed days with response times from successful checks only', function (): void {
        checkAt($this->monitor, '2026-11-20 01:00', ms: 100);
        checkAt($this->monitor, '2026-11-20 09:00', ms: 300);
        checkAt($this->monitor, '2026-11-20 17:00', succeeded: false);

        expect(resolve(AggregateMonitorChecksAction::class)->handle())->toBe(1)
            ->and(statFor($this->monitor, '2026-11-20'))->total_checks->toBe(3)->failed_checks->toBe(1)->avg_response_ms->toBe(200)->min_response_ms->toBe(100)->max_response_ms->toBe(300);
    });

    it('stores no response times for a day where every check failed', function (): void {
        checkAt($this->monitor, '2026-11-20 01:00', succeeded: false);

        resolve(AggregateMonitorChecksAction::class)->handle();

        expect(statFor($this->monitor, '2026-11-20'))
            ->failed_checks->toBe(1)
            ->avg_response_ms->toBeNull()
            ->min_response_ms->toBeNull();
    });

    it('never aggregates today', function (): void {
        checkAt($this->monitor, '2026-11-21 00:30');

        expect(resolve(AggregateMonitorChecksAction::class)->handle())->toBe(0)
            ->and(MonitorDailyStat::query()->count())->toBe(0);
    });

    it('splits days on UTC midnight', function (): void {
        checkAt($this->monitor, '2026-11-19 23:59:59');
        checkAt($this->monitor, '2026-11-20 00:00:00');

        resolve(AggregateMonitorChecksAction::class)->handle();

        expect(statFor($this->monitor, '2026-11-19')->total_checks)->toBe(1)
            ->and(statFor($this->monitor, '2026-11-20')->total_checks)->toBe(1);
    });

    it('creates no row for days without checks, such as paused or unmonitored days', function (): void {
        checkAt($this->monitor, '2026-11-17 10:00');
        checkAt($this->monitor, '2026-11-20 10:00');

        resolve(AggregateMonitorChecksAction::class)->handle();

        expect(MonitorDailyStat::query()->pluck('date')->map->toDateString()->all())->toBe(['2026-11-17', '2026-11-20']);
    });

    it('is idempotent and picks up late-arriving checks on the next run', function (): void {
        checkAt($this->monitor, '2026-11-20 10:00');

        $aggregate = resolve(AggregateMonitorChecksAction::class);
        $aggregate->handle();
        $aggregate->handle();

        expect(MonitorDailyStat::query()->count())->toBe(1)
            ->and(statFor($this->monitor, '2026-11-20')->total_checks)->toBe(1);

        checkAt($this->monitor, '2026-11-20 22:00', succeeded: false);
        $aggregate->handle();

        expect(MonitorDailyStat::query()->count())->toBe(1)
            ->and(statFor($this->monitor, '2026-11-20'))->total_checks->toBe(2)->failed_checks->toBe(1);
    });
});

describe('pruning', function (): void {
    it('deletes aggregated raw checks older than the retention period and keeps the rest', function (): void {
        $old = checkAt($this->monitor, '2026-10-20 10:00');
        $boundary = checkAt($this->monitor, '2026-10-22 00:00');
        $recent = checkAt($this->monitor, '2026-11-20 10:00');

        $report = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($report['checks'])->toBe(1)
            ->and($report['blocked_days'])->toBe(0)
            ->and($report['cutoffs']['checks']->toDateString())->toBe('2026-10-22')
            ->and(MonitorCheck::query()->pluck('id')->all())->toEqualCanonicalizing([$boundary->id, $recent->id])
            ->and(statFor($this->monitor, '2026-10-20')->total_checks)->toBe(1)
            ->and($old->fresh())->toBeNull();
    });

    it('keeps raw checks for a day that changed after it was summarised', function (): void {
        $log = Log::spy();
        checkAt($this->monitor, '2026-10-01 10:00');

        $lateArrivalInserted = false;
        DB::listen(function (QueryExecuted $query) use (&$lateArrivalInserted): void {
            if ($lateArrivalInserted || ! str_contains($query->sql, 'monitor_daily_stats') || ! str_starts_with(mb_strtolower($query->sql), 'insert')) {
                return;
            }

            $lateArrivalInserted = true;
            checkAt($this->monitor, '2026-10-01 11:00');
        });

        $report = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($lateArrivalInserted)->toBeTrue()
            ->and($report['checks'])->toBe(0)
            ->and($report['blocked_days'])->toBe(1)
            ->and(MonitorCheck::query()->count())->toBe(2);

        $log->shouldHaveReceived('warning')->once();

        $next = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($next['checks'])->toBe(2)
            ->and($next['blocked_days'])->toBe(0)
            ->and(statFor($this->monitor, '2026-10-01')->total_checks)->toBe(2);
    });

    it('deletes nothing when aggregation fails', function (): void {
        checkAt($this->monitor, '2026-10-01 10:00');
        MonitorIncident::factory()->for($this->monitor)->create(['started_at' => Date::parse('2025-06-01'), 'resolved_at' => Date::parse('2025-06-01 02:00')]);
        Schema::drop('monitor_daily_stats');

        expect(fn () => resolve(PruneMonitoringHistoryAction::class)->handle())->toThrow(QueryException::class)
            ->and(MonitorCheck::query()->count())->toBe(1)
            ->and(MonitorIncident::query()->count())->toBe(1);
    });

    it('never deletes open incidents, however old', function (): void {
        $open = MonitorIncident::factory()->for($this->monitor)->create(['started_at' => Date::parse('2025-01-01'), 'resolved_at' => null]);
        $oldResolved = MonitorIncident::factory()->for($this->monitor)->create(['started_at' => Date::parse('2025-06-01'), 'resolved_at' => Date::parse('2025-06-01 03:00')]);
        $recentResolved = MonitorIncident::factory()->for($this->monitor)->create(['started_at' => Date::parse('2026-01-01'), 'resolved_at' => Date::parse('2026-01-01 03:00')]);

        $report = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($report['incidents'])->toBe(1)
            ->and($report['open_incidents_kept'])->toBe(1)
            ->and(MonitorIncident::query()->pluck('id')->all())->toEqualCanonicalizing([$open->id, $recentResolved->id])
            ->and($oldResolved->fresh())->toBeNull();
    });

    it('deletes daily summaries older than their retention period', function (): void {
        MonitorDailyStat::factory()->for($this->monitor)->create(['date' => Date::parse('2025-11-20')]);
        $kept = MonitorDailyStat::factory()->for($this->monitor)->create(['date' => Date::parse('2025-11-21')]);

        $report = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($report['daily_stats'])->toBe(1)
            ->and(MonitorDailyStat::query()->pluck('id')->all())->toBe([$kept->id]);
    });

    it('leaves monitor configuration and current state untouched', function (): void {
        $monitor = Monitor::factory()->down()->invalidCertificate()->create([
            'uptime_check_times_failed_in_a_row' => 4,
            'uptime_last_check_date' => Date::parse('2026-09-01'),
            'certificate_expiration_date' => Date::parse('2026-09-30'),
        ]);
        checkAt($monitor, '2026-09-01 10:00', succeeded: false);
        $before = $monitor->fresh()->getAttributes();

        resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($monitor->fresh()->getAttributes())->toBe($before)
            ->and(MonitorCheck::query()->whereBelongsTo($monitor)->count())->toBe(0);
    });

    it('writes nothing in a dry run, not even summaries', function (): void {
        checkAt($this->monitor, '2026-10-01 10:00');
        MonitorIncident::factory()->for($this->monitor)->create(['started_at' => Date::parse('2025-06-01'), 'resolved_at' => Date::parse('2025-06-01 02:00')]);

        $report = resolve(PruneMonitoringHistoryAction::class)->handle(dryRun: true);

        expect($report)->toMatchArray(['dry_run' => true, 'checks' => 0, 'unaggregated_days' => 1, 'incidents' => 1])
            ->and(MonitorCheck::query()->count())->toBe(1)
            ->and(MonitorDailyStat::query()->count())->toBe(0)
            ->and(MonitorIncident::query()->count())->toBe(1);
    });

    it('is safe to run repeatedly', function (): void {
        checkAt($this->monitor, '2026-10-01 10:00');
        checkAt($this->monitor, '2026-11-20 10:00');

        $prune = resolve(PruneMonitoringHistoryAction::class);
        $first = $prune->handle();
        $second = $prune->handle();

        expect($first['checks'])->toBe(1)
            ->and($second['checks'])->toBe(0)
            ->and($second['blocked_days'])->toBe(0)
            ->and(MonitorCheck::query()->count())->toBe(1)
            ->and(MonitorDailyStat::query()->count())->toBe(2);
    });

    it('does nothing when pruning is disabled', function (): void {
        config()->set('vitals.retention.enabled', false);
        checkAt($this->monitor, '2026-10-01 10:00');

        $report = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($report['enabled'])->toBeFalse()
            ->and(MonitorCheck::query()->count())->toBe(1)
            ->and(MonitorDailyStat::query()->count())->toBe(0);
    });

    it('refuses to prune raw checks the page window still needs', function (): void {
        config()->set('vitals.retention.checks_days', 7);
        checkAt($this->monitor, '2026-11-01 10:00');

        $report = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($report['checks_skipped'])->toContain('shorter than the page history window')
            ->and(MonitorCheck::query()->count())->toBe(1);
    });
});

describe('reporting', function (): void {
    it('keeps uptime, history and response summaries identical after pruning', function (): void {
        foreach (range(0, 59) as $daysAgo) {
            $day = Date::today()->subDays($daysAgo);
            checkAt($this->monitor, $day->setTime(6, 0)->toDateTimeString(), ms: 200 + $daysAgo);
            checkAt($this->monitor, $day->setTime(18, 0)->toDateTimeString(), succeeded: $daysAgo % 7 !== 3);
        }

        $snapshot = function (): array {
            $history = UptimeHistory::make();

            return [
                $history->uptimePercentage($this->monitor),
                $history->averageResponseMs($this->monitor),
                $history->days($this->monitor),
                $history->responseTrend($this->monitor),
            ];
        };

        $before = $snapshot();
        $report = resolve(PruneMonitoringHistoryAction::class)->handle();

        expect($report['checks'])->toBeGreaterThan(0)
            ->and($snapshot())->toEqual($before);
    });
});

it('previews pruning from the command line', function (): void {
    checkAt($this->monitor, '2026-10-01 10:00');

    $this->artisan('monitors:prune-history', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete raw checks')
        ->assertSuccessful();

    expect(MonitorCheck::query()->count())->toBe(1);
});

it('prunes from the command line', function (): void {
    checkAt($this->monitor, '2026-10-01 10:00');

    $this->artisan('monitors:prune-history')
        ->expectsOutputToContain('Deleted raw checks')
        ->assertSuccessful();

    expect(MonitorCheck::query()->count())->toBe(0)
        ->and(MonitorDailyStat::query()->count())->toBe(1);
});
