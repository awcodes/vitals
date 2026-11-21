<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Awcodes\Vitals\Support\FailureReason;
use Awcodes\Vitals\Support\MonitorInspection;
use Awcodes\Vitals\Support\UptimeHistory;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Date::setTestNow('2026-11-21 12:00:00');
    config()->set('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures', 2);
});

function inspect(Monitor $monitor): MonitorInspection
{
    return MonitorInspection::for($monitor->load('latestCheck'), UptimeHistory::make(), 'UTC');
}

it('turns raw failure text into a readable title and summary', function (?string $raw, string $title, string $summary): void {
    $reason = FailureReason::from($raw);

    expect($reason->title)->toBe($title)
        ->and($reason->summary)->toBe($summary)
        ->and($reason->raw)->toBe($raw);
})->with([
    'timeout' => ['cURL error 28: Operation timed out after 15001 milliseconds', 'Timed out', 'The website did not respond in time.'],
    'connection' => ['cURL error 7: Failed to connect to example.test port 443: Connection refused', 'Connection failed', 'The website could not be reached.'],
    'dns' => ['cURL error 6: Could not resolve host: example.test', 'DNS lookup failed', 'The domain name could not be resolved.'],
    'tls' => ['cURL error 60: SSL certificate problem: unable to get local issuer certificate', 'Secure connection failed', 'A TLS connection could not be established.'],
    'reset' => ['Connection reset by peer', 'Connection reset', 'The connection was dropped before a response arrived.'],
    'server error' => ['Server error: `HEAD https://example.test` resulted in a `502 Bad Gateway` response', 'Server error (502 Bad Gateway)', 'The server responded with an error.'],
    'client error' => ['Client error: `HEAD https://example.test` resulted in a `404 Not Found` response', 'HTTP 404 Not Found', 'The server rejected the request.'],
    'missing content' => ['String `Welcome` was not found on the response.', 'Expected content missing', 'The page loaded but did not contain the expected text.'],
    'unknown' => ['Something odd', 'Check failed', 'See technical details.'],
    'empty' => [null, 'Check failed', 'No reason was recorded.'],
]);

it('lists ongoing incidents first, then newest first, with accurate durations', function (): void {
    $monitor = Monitor::factory()->down()->create(['uptime_check_times_failed_in_a_row' => 3, 'uptime_last_check_date' => Date::now()]);
    MonitorIncident::factory()->for($monitor)->create(['started_at' => Date::parse('2026-11-10 08:00'), 'resolved_at' => Date::parse('2026-11-10 10:30')]);
    MonitorIncident::factory()->for($monitor)->create(['started_at' => Date::parse('2026-11-18 08:00'), 'resolved_at' => Date::parse('2026-11-18 08:45')]);
    MonitorIncident::factory()->for($monitor)->create(['started_at' => Date::parse('2026-11-21 11:15'), 'resolved_at' => null, 'failure_reason' => 'cURL error 28: Operation timed out']);

    $inspection = inspect($monitor);

    expect(array_column($inspection->incidents, 'started'))->toBe(['Nov 21, 11:15 AM', 'Nov 18, 8:00 AM', 'Nov 10, 8:00 AM'])
        ->and(array_column($inspection->incidents, 'ongoing'))->toBe([true, false, false])
        ->and(array_column($inspection->incidents, 'duration'))->toBe(['45m', '45m', '2h 30m'])
        ->and(array_column($inspection->incidents, 'ended'))->toBe([null, 'Nov 18, 8:45 AM', 'Nov 10, 10:30 AM'])
        ->and($inspection->activeIncident)->toMatchArray(['since' => 'Nov 21, 11:15 AM', 'duration' => '45m'])
        ->and($inspection->activeIncident['reason']->title)->toBe('Timed out');
});

it('keeps availability and certificate conditions separate', function (): void {
    $monitor = Monitor::factory()->down()->create([
        'uptime_check_times_failed_in_a_row' => 3,
        'uptime_last_check_date' => Date::now(),
        'certificate_status' => 'invalid',
        'certificate_expiration_date' => Date::now()->subDays(10),
    ]);

    expect(inspect($monitor)->conditions)->toBe([
        ['label' => 'Down', 'color' => 'danger'],
        ['label' => 'SSL expired', 'color' => 'danger'],
    ]);
});

it('averages only successful response times, weighted by check', function (): void {
    $monitor = Monitor::factory()->create(['uptime_last_check_date' => Date::now()]);
    MonitorCheck::factory()->for($monitor)->create(['checked_at' => Date::now()->subDays(2), 'response_time_ms' => 100]);
    MonitorCheck::factory()->for($monitor)->create(['checked_at' => Date::now()->subDay(), 'response_time_ms' => 400]);
    MonitorCheck::factory()->for($monitor)->create(['checked_at' => Date::now()->subDay(), 'response_time_ms' => 400]);
    MonitorCheck::factory()->for($monitor)->failed()->create(['checked_at' => Date::now()->subDay()]);

    expect(UptimeHistory::make()->averageResponseMs($monitor))->toBe(300);
});

it('never reports a failed latest check as a response time', function (): void {
    $monitor = Monitor::factory()->down()->create(['uptime_last_check_date' => Date::now()]);
    MonitorCheck::factory()->for($monitor)->create(['checked_at' => Date::now()->subHour(), 'response_time_ms' => 412]);
    MonitorCheck::factory()->for($monitor)->failed('Connection refused')->create(['checked_at' => Date::now()]);

    $inspection = inspect($monitor);

    expect($inspection->latestFailed)->toBeTrue()
        ->and($inspection->latestMs)->toBeNull()
        ->and($inspection->lastSuccessMs)->toBe(412)
        ->and($inspection->latestFailure?->title)->toBe('Connection failed')
        ->and(collect($inspection->recentChecks)->pluck('succeeded')->all())->toBe([true, false]);
});

it('handles a monitor with no history without inventing values', function (): void {
    $monitor = Monitor::factory()->create(['uptime_status' => 'not yet checked', 'uptime_last_check_date' => null, 'certificate_status' => 'not yet checked']);

    $inspection = inspect($monitor);

    expect($inspection->uptime)->toBeNull()
        ->and($inspection->averageMs)->toBeNull()
        ->and($inspection->latestMs)->toBeNull()
        ->and($inspection->recentChecks)->toBeEmpty()
        ->and($inspection->incidents)->toBeEmpty()
        ->and($inspection->checkTimes)->toBe('Never checked')
        ->and($inspection->certificate['headline'])->toBe('Not checked yet')
        ->and(collect($inspection->days)->pluck('avg_ms')->filter())->toBeEmpty();
});

it('bounds the recent checks it loads', function (): void {
    $monitor = Monitor::factory()->create(['uptime_last_check_date' => Date::now()]);
    MonitorCheck::factory()->for($monitor)->count(40)->sequence(fn ($sequence): array => ['checked_at' => Date::now()->subMinutes($sequence->index * 15)])->create();

    expect(inspect($monitor)->recentChecks)->toHaveCount(MonitorInspection::RECENT_CHECKS);
});
