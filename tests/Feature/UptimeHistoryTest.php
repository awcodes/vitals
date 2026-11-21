<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Awcodes\Vitals\Support\UptimeHistory;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Date::setTestNow('2026-11-21 12:00:00');
    $this->monitor = Monitor::factory()->create();
});

it('buckets checks into days and distinguishes missing data', function (): void {
    MonitorCheck::factory()->for($this->monitor)->count(3)->create(['checked_at' => Date::now()->subDay()]);
    MonitorCheck::factory()->for($this->monitor)->create(['checked_at' => Date::now()->subDays(2)]);
    MonitorCheck::factory()->for($this->monitor)->failed()->create(['checked_at' => Date::now()->subDays(2)]);

    $days = (new UptimeHistory(5))->days($this->monitor);

    expect($days)->toHaveCount(5)
        ->and(array_column($days, 'state'))->toBe(['none', 'none', 'degraded', 'up', 'none'])
        ->and($days[2]['label'])->toBe('Nov 19: 1 of 2 checks failed');
});

it('marks days overlapped by an incident as down', function (): void {
    MonitorCheck::factory()->for($this->monitor)->failed()->count(2)->create(['checked_at' => Date::now()->subDays(2)]);
    MonitorIncident::factory()->for($this->monitor)->create([
        'started_at' => Date::now()->subDays(2),
        'resolved_at' => Date::now()->subDay()->startOfDay()->addHour(),
    ]);

    expect(array_column((new UptimeHistory(4))->days($this->monitor), 'state'))
        ->toBe(['none', 'down', 'down', 'none']);
});

it('calculates uptime over the window and ignores older checks', function (): void {
    MonitorCheck::factory()->for($this->monitor)->count(3)->create(['checked_at' => Date::now()->subHour()]);
    MonitorCheck::factory()->for($this->monitor)->failed()->create(['checked_at' => Date::now()->subHour()]);
    MonitorCheck::factory()->for($this->monitor)->failed()->count(10)->create(['checked_at' => Date::now()->subDays(40)]);

    expect((new UptimeHistory(30))->uptimePercentage($this->monitor))->toBe(75.0);
});

it('returns no uptime when there are no checks', function (): void {
    expect((new UptimeHistory(30))->uptimePercentage($this->monitor))->toBeNull();
});

it('averages response time per day', function (): void {
    MonitorCheck::factory()->for($this->monitor)->create(['checked_at' => Date::now(), 'response_time_ms' => 100]);
    MonitorCheck::factory()->for($this->monitor)->create(['checked_at' => Date::now(), 'response_time_ms' => 300]);

    expect((new UptimeHistory(2))->responseTrend($this->monitor))
        ->sequence(
            fn ($day) => $day->avg_ms->toBeNull(),
            fn ($day) => $day->avg_ms->toBe(200),
        );
});
