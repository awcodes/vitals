<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\MonitorInspection;
use Awcodes\Vitals\Support\UptimeHistory;
use Illuminate\Support\Facades\Date;

/*
 * Laravel's default dates are mutable, and the suite runs with them. These
 * guard the places where reading a date could otherwise change the model.
 */
beforeEach(function (): void {
    Date::setTestNow('2026-11-21 12:00:00');
});

it('does not change the certificate expiry while reading it', function (): void {
    $monitor = Monitor::factory()->create(['certificate_expiration_date' => Date::parse('2026-12-01 15:30:00')]);

    $monitor->certificateDaysRemaining();
    MonitorInspection::for($monitor->load('latestCheck'), UptimeHistory::make(), 'America/New_York');

    expect($monitor->certificate_expiration_date->toDateTimeString())->toBe('2026-12-01 15:30:00')
        ->and($monitor->certificate_expiration_date->timezoneName)->toBe('UTC')
        ->and($monitor->isDirty())->toBeFalse();
});

it('builds one entry per day of the history window', function (): void {
    $days = array_map(
        fn (array $day): string => $day['date']->toDateString(),
        UptimeHistory::make()->days(Monitor::factory()->create()),
    );

    expect($days)->toHaveCount(30)
        ->and($days[0])->toBe('2026-10-23')
        ->and(end($days))->toBe('2026-11-21')
        ->and(array_unique($days))->toHaveCount(30);
});
