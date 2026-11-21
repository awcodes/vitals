<?php

declare(strict_types=1);

use Awcodes\Vitals\Enums\CertificateHealth;
use Awcodes\Vitals\Enums\MonitorHealth;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    config()->set('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures', 2);
});

it('derives uptime health from the monitor state', function (array $attributes, MonitorHealth $expected): void {
    $monitor = Monitor::factory()->create([
        'uptime_last_check_date' => Date::now()->subMinutes(5),
        ...$attributes,
    ]);

    expect($monitor->health())->toBe($expected);
})->with([
    'healthy' => [[], MonitorHealth::Healthy],
    'paused' => [['uptime_check_enabled' => false, 'uptime_status' => 'down'], MonitorHealth::Paused],
    'never checked' => [['uptime_status' => 'not yet checked', 'uptime_last_check_date' => null], MonitorHealth::Unknown],
    'stale results' => [['uptime_last_check_date' => Date::now()->subHours(3)], MonitorHealth::Unknown],
    'one failed check' => [['uptime_status' => 'down', 'uptime_check_times_failed_in_a_row' => 1], MonitorHealth::Degraded],
    'confirmed outage' => [['uptime_status' => 'down', 'uptime_check_times_failed_in_a_row' => 2], MonitorHealth::Down],
]);

it('marks a passing but slow monitor as degraded', function (): void {
    $monitor = Monitor::factory()->create(['uptime_last_check_date' => Date::now()]);
    MonitorCheck::factory()->for($monitor)->create(['response_time_ms' => 9000]);

    expect($monitor->load('latestCheck')->health())->toBe(MonitorHealth::Degraded)
        ->and(array_column($monitor->issues(), 'message'))->toBe(['Slow response · 9,000 ms']);
});

it('derives certificate health independently of uptime', function (array $attributes, CertificateHealth $expected): void {
    $monitor = Monitor::factory()->down()->create($attributes);

    expect($monitor->certificateHealth())->toBe($expected);
})->with([
    'valid' => [['certificate_expiration_date' => Date::now()->addDays(60)], CertificateHealth::Valid],
    'expiring soon' => [['certificate_expiration_date' => Date::now()->addDays(5)], CertificateHealth::ExpiringSoon],
    'expired since last check' => [['certificate_expiration_date' => Date::now()->subDay()], CertificateHealth::Invalid],
    'invalid' => [['certificate_status' => 'invalid', 'certificate_expiration_date' => Date::now()->addDays(60)], CertificateHealth::Invalid],
    'inspection failed' => [['certificate_status' => 'invalid', 'certificate_expiration_date' => null], CertificateHealth::InspectionFailed],
    'not checked' => [['certificate_status' => 'not yet checked'], CertificateHealth::Unknown],
    'disabled' => [['certificate_check_enabled' => false, 'certificate_status' => 'invalid'], CertificateHealth::Disabled],
]);

it('does not report a failed certificate inspection as an expired certificate', function (): void {
    $monitor = Monitor::factory()->create();

    $monitor->setCertificateException(new RuntimeException('Could not connect to example.com'));

    expect($monitor->certificateHealth())->toBe(CertificateHealth::InspectionFailed)
        ->and(array_column($monitor->issues(), 'message'))->toBe(['SSL certificate inspection failed']);
});

it('distinguishes never checked from stale results', function (): void {
    $neverChecked = Monitor::factory()->create(['uptime_status' => 'not yet checked', 'uptime_last_check_date' => null]);
    $stale = Monitor::factory()->create(['uptime_last_check_date' => Date::now()->subHours(3)]);

    expect($neverChecked->health())->toBe(MonitorHealth::Unknown)
        ->and($neverChecked->statusLabel())->toBe('Never checked')
        ->and($stale->health())->toBe(MonitorHealth::Unknown)
        ->and($stale->statusLabel())->toBe('Stale');
});

it('does not treat a monitor awaiting its first check as an issue', function (): void {
    $monitor = Monitor::factory()->create(['uptime_status' => 'not yet checked', 'uptime_last_check_date' => null, 'certificate_status' => 'not yet checked']);

    expect($monitor->issues())->toBeEmpty()
        ->and($monitor->attentionRank())->toBe(30);
});

it('lists issues most urgent first with short messages and no raw errors', function (): void {
    $monitor = Monitor::factory()->down('cURL error 28: Operation timed out after 15001 milliseconds')->create([
        'uptime_check_times_failed_in_a_row' => 3,
        'uptime_last_check_date' => Date::now(),
        'certificate_expiration_date' => Date::now()->addDays(4),
    ]);

    expect($monitor->issues())->toBe([
        ['priority' => 1, 'message' => 'Down · 3 failed checks in a row', 'color' => 'danger'],
        ['priority' => 5, 'message' => 'SSL certificate expires in 4 days', 'color' => 'warning'],
    ]);
});

it('keeps an SSL problem from changing uptime status', function (): void {
    $monitor = Monitor::factory()->create(['uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->subDay()]);

    expect($monitor->health())->toBe(MonitorHealth::Healthy)
        ->and($monitor->certificateHealth())->toBe(CertificateHealth::Invalid)
        ->and(array_column($monitor->issues(), 'message'))->toBe(['SSL certificate expired'])
        ->and($monitor->attentionRank())->toBe(13);
});

it('ranks monitors down, degraded, certificate problems by severity, unknown or stale, healthy, paused', function (): void {
    $now = Date::now()->toImmutable();

    $ranks = [
        'down' => Monitor::factory()->down()->make(['uptime_check_times_failed_in_a_row' => 2, 'uptime_last_check_date' => $now])->attentionRank(),
        'degraded' => Monitor::factory()->down()->make(['uptime_check_times_failed_in_a_row' => 1, 'uptime_last_check_date' => $now])->attentionRank(),
        'invalid certificate' => Monitor::factory()->make(['uptime_last_check_date' => $now, 'certificate_status' => 'invalid', 'certificate_expiration_date' => $now->addDays(30)])->attentionRank(),
        'expiring certificate' => Monitor::factory()->make(['uptime_last_check_date' => $now, 'certificate_expiration_date' => $now->addDays(3)])->attentionRank(),
        'stale' => Monitor::factory()->make(['uptime_last_check_date' => $now->subHours(5), 'certificate_expiration_date' => $now->addDays(90)])->attentionRank(),
        'never checked' => Monitor::factory()->make(['uptime_status' => 'not yet checked'])->attentionRank(),
        'healthy' => Monitor::factory()->make(['uptime_last_check_date' => $now, 'certificate_expiration_date' => $now->addDays(90)])->attentionRank(),
        'paused' => Monitor::factory()->paused()->down()->make()->attentionRank(),
    ];

    expect(array_keys(Arr::sort($ranks)))->toBe(['down', 'degraded', 'invalid certificate', 'expiring certificate', 'stale', 'never checked', 'healthy', 'paused'])
        ->and($ranks['stale'])->toBe($ranks['never checked']);
});
