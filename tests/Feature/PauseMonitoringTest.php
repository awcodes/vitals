<?php

declare(strict_types=1);

use Awcodes\Vitals\Enums\MonitorHealth;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Spatie\UptimeMonitor\Notifications\Notifications\CertificateCheckFailed;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckFailed;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckRecovered;

beforeEach(function (): void {
    Notification::fake();
    config()->set('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures', 2);
});

it('skips paused monitors in scheduled uptime checks', function (): void {
    $monitor = Monitor::factory()->down()->paused()->create();

    expect($monitor->shouldCheckUptime())->toBeFalse()
        ->and($monitor->health())->toBe(MonitorHealth::Paused);
});

it('discards forced uptime results for paused monitors without notifying', function (): void {
    $monitor = Monitor::factory()->paused()->create();

    foreach (range(1, 4) as $attempt) {
        $monitor->uptimeRequestFailed('Connection refused');
    }

    expect($monitor->refresh()->uptime_check_times_failed_in_a_row)->toBe(0)
        ->and(MonitorCheck::query()->count())->toBe(0)
        ->and(MonitorIncident::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('skips certificate checks for paused monitors', function (): void {
    $monitor = Monitor::factory()->paused()->create(['url' => 'https://does-not-exist.invalid']);

    $monitor->checkCertificate();

    expect($monitor->refresh()->certificate_status)->toBe('valid');
    Notification::assertNotSentTo(new Spatie\UptimeMonitor\Notifications\Notifiable, CertificateCheckFailed::class);
});

it('closes the open incident when pausing', function (): void {
    $monitor = Monitor::factory()->create();
    $monitor->uptimeRequestFailed('Connection refused');
    $monitor->uptimeRequestFailed('Connection refused');

    $monitor->pause('Planned maintenance');

    expect(MonitorIncident::query()->open()->count())->toBe(0)
        ->and(MonitorIncident::query()->sole()->resolved_at)->not->toBeNull();
});

it('resets the failure streak on resume so a still-down site re-confirms before notifying', function (): void {
    $monitor = Monitor::factory()->create();
    $monitor->uptimeRequestFailed('Connection refused');
    $monitor->uptimeRequestFailed('Connection refused');
    Notification::assertSentTimes(UptimeCheckFailed::class, 1);

    $monitor->pause();
    $monitor->resume();

    expect($monitor->health())->toBe(MonitorHealth::Unknown);

    $monitor->uptimeRequestFailed('Connection refused');
    Notification::assertSentTimes(UptimeCheckFailed::class, 1);

    $monitor->uptimeRequestFailed('Connection refused');
    Notification::assertSentTimes(UptimeCheckFailed::class, 2);
    expect(MonitorIncident::query()->open()->count())->toBe(1);
});

it('does not send a recovery for a site that came back while paused', function (): void {
    $monitor = Monitor::factory()->create();
    $monitor->uptimeRequestFailed('Connection refused');
    $monitor->uptimeRequestFailed('Connection refused');

    $monitor->pause();
    $monitor->resume();
    $monitor->uptimeRequestSucceeded(new Response(200));

    Notification::assertNotSentTo(new Spatie\UptimeMonitor\Notifications\Notifiable, UptimeCheckRecovered::class);
});

it('resumes monitors whose pause has expired', function (): void {
    $expired = Monitor::factory()->paused()->create(['paused_until' => Date::now()->subMinute()]);
    $pending = Monitor::factory()->paused()->create(['paused_until' => Date::now()->addHour()]);
    $indefinite = Monitor::factory()->paused()->create(['paused_until' => null]);

    $this->artisan('monitors:resume-expired-pauses')->assertSuccessful();

    expect($expired->refresh()->isPaused())->toBeFalse()
        ->and($pending->refresh()->isPaused())->toBeTrue()
        ->and($indefinite->refresh()->isPaused())->toBeTrue();
});
