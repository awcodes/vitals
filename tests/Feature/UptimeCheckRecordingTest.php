<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Awcodes\Vitals\Support\TimedUptimeCheckRequest;
use Awcodes\Vitals\Support\UptimeCheckTimings;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckFailed;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckRecovered;

beforeEach(function (): void {
    Notification::fake();
    config()->set('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures', 2);
    $this->monitor = Monitor::factory()->create(['uptime_status' => 'not yet checked']);
});

it('records a successful check with the captured status and response time', function (): void {
    resolve(UptimeCheckTimings::class)->put($this->monitor->id, 200, 245);

    $this->monitor->uptimeRequestSucceeded(new Response(200));

    $check = MonitorCheck::query()->sole();

    expect($check->succeeded)->toBeTrue()
        ->and($check->http_status)->toBe(200)
        ->and($check->response_time_ms)->toBe(245)
        ->and($check->failure_reason)->toBeNull();
});

it('records a failed check with its reason and no response time', function (): void {
    resolve(UptimeCheckTimings::class)->put($this->monitor->id, null, null);

    $this->monitor->uptimeRequestFailed('cURL error 28: Operation timed out');

    $check = MonitorCheck::query()->sole();

    expect($check->succeeded)->toBeFalse()
        ->and($check->response_time_ms)->toBeNull()
        ->and($check->failure_reason)->toBe('cURL error 28: Operation timed out');
});

it('records a response that fails the content check as a failure', function (): void {
    $this->monitor->update(['look_for_string' => 'Welcome']);

    $this->monitor->uptimeRequestSucceeded(new Response(200, [], 'Maintenance'));

    expect(MonitorCheck::query()->sole()->succeeded)->toBeFalse();
});

it('truncates long failure reasons', function (): void {
    $this->monitor->uptimeRequestFailed(str_repeat('x', 500));

    expect((string) MonitorCheck::query()->sole()->failure_reason)->toHaveLength(255);
});

it('does not open an incident for a single failed check', function (): void {
    $this->monitor->uptimeRequestFailed('Connection refused');

    expect(MonitorIncident::query()->count())->toBe(0);
});

it('opens one incident once the failure threshold is reached', function (): void {
    $this->monitor->uptimeRequestFailed('Connection refused');
    $this->monitor->uptimeRequestFailed('Connection refused');
    $this->monitor->uptimeRequestFailed('Connection refused');
    $this->monitor->uptimeRequestFailed('Connection refused');

    $incident = MonitorIncident::query()->sole();

    expect($incident->resolved_at)->toBeNull()
        ->and($incident->failure_reason)->toBe('Connection refused')
        ->and(MonitorCheck::query()->count())->toBe(4);
});

it('resolves the open incident when the monitor recovers', function (): void {
    $this->monitor->uptimeRequestFailed('Connection refused');
    $this->monitor->uptimeRequestFailed('Connection refused');

    Date::setTestNow(Date::now()->addMinutes(30));

    $this->monitor->uptimeRequestSucceeded(new Response(200));

    $incident = MonitorIncident::query()->sole();

    expect($incident->resolved_at)->not->toBeNull()
        ->and($incident->durationInMinutes())->toBeGreaterThanOrEqual(30);
});

it('opens a new incident for a later outage instead of reopening the old one', function (): void {
    $this->monitor->uptimeRequestFailed('Connection refused');
    $this->monitor->uptimeRequestFailed('Connection refused');
    $this->monitor->uptimeRequestSucceeded(new Response(200));
    $this->monitor->uptimeRequestFailed('HTTP 503');
    $this->monitor->uptimeRequestFailed('HTTP 503');

    expect(MonitorIncident::query()->count())->toBe(2)
        ->and(MonitorIncident::query()->open()->sole()->failure_reason)->toBe('HTTP 503');
});

it('captures the HTTP status and transfer time from the uptime request', function (): void {
    $options = (fn (Monitor $monitor): array => $this->requestOptions($monitor))
        ->call(new TimedUptimeCheckRequest, $this->monitor);

    $options['on_stats'](new TransferStats(new Request('HEAD', 'https://example.com'), new Response(301), 0.1234));

    expect(resolve(UptimeCheckTimings::class)->pull($this->monitor->id))
        ->toBe(['http_status' => 301, 'response_time_ms' => 123])
        ->and($options)->toHaveKey('connect_timeout');
});

it('notifies once per outage rather than on every failed check', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->monitor->uptimeRequestFailed('Connection refused');
    }

    $this->monitor->uptimeRequestSucceeded(new Response(200));

    Notification::assertSentTimes(UptimeCheckFailed::class, 1);
    Notification::assertSentTimes(UptimeCheckRecovered::class, 1);
});

it('does not resend failure notifications during a long outage', function (): void {
    Date::setTestNow('2026-11-21 08:00:00');

    $this->monitor->uptimeRequestFailed('Connection refused');
    Notification::assertNothingSent();

    foreach (range(1, 12) as $quarterHour) {
        Date::setTestNow(Date::now()->addMinutes(15));
        $this->monitor->uptimeRequestFailed('Connection refused');
    }

    Date::setTestNow(Date::now()->addHours(3));
    $this->monitor->uptimeRequestFailed('Connection refused');

    Notification::assertSentTimes(UptimeCheckFailed::class, 1);

    $this->monitor->uptimeRequestSucceeded(new Response(200));
    $this->monitor->uptimeRequestFailed('HTTP 503');
    $this->monitor->uptimeRequestFailed('HTTP 503');

    Notification::assertSentTimes(UptimeCheckRecovered::class, 1);
    Notification::assertSentTimes(UptimeCheckFailed::class, 2);
});

it('ignores forced results for monitors with uptime checks turned off', function (): void {
    $monitor = Monitor::factory()->create(['uptime_check_enabled' => false, 'certificate_check_enabled' => true]);

    foreach (range(1, 3) as $attempt) {
        $monitor->uptimeRequestFailed('Connection refused');
    }

    expect($monitor->refresh()->uptime_check_times_failed_in_a_row)->toBe(0)
        ->and(MonitorCheck::query()->whereBelongsTo($monitor)->count())->toBe(0)
        ->and(MonitorIncident::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('records the check and skips the incident update when another check holds the lock', function (): void {
    $log = Log::spy();
    $lock = Cache::lock("monitor-incident:{$this->monitor->id}", 10);
    $lock->get();

    $this->monitor->uptimeRequestFailed('Connection refused');
    $this->monitor->uptimeRequestFailed('Connection refused');

    expect(MonitorCheck::query()->count())->toBe(2)
        ->and(MonitorIncident::query()->count())->toBe(0);
    $log->shouldHaveReceived('warning')->twice();

    $lock->release();
    $this->monitor->uptimeRequestFailed('Connection refused');

    expect(MonitorIncident::query()->open()->count())->toBe(1);
});
