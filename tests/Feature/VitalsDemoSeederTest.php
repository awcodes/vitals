<?php

declare(strict_types=1);

use Awcodes\Vitals\Database\Seeders\VitalsDemoSeeder;
use Awcodes\Vitals\Enums\CertificateHealth;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Awcodes\Vitals\Support\UptimeHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Date::setTestNow('2026-11-21 12:00:00');
    config()->set('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures', 2);
});

function seedDemoMonitors(): void
{
    app()->detectEnvironment(fn (): string => 'local');

    test()->seed(VitalsDemoSeeder::class);
}

/** @return Collection<string, Monitor> */
function demoMonitors(): Collection
{
    return Monitor::query()->with('latestCheck')->where('url', 'like', '%.test')->get()->keyBy('name');
}

it('only runs in the local environment', function (string $environment): void {
    // Migrate before switching: migrate:fresh refuses to run in production.
    expect(Monitor::query()->count())->toBe(0);

    app()->detectEnvironment(fn (): string => $environment);

    // Called directly: db:seed itself asks for confirmation in production.
    resolve(VitalsDemoSeeder::class)->run();

    expect(Monitor::query()->count())->toBe(0);
})->with(['testing', 'production', 'staging']);

it('seeds 18 demo monitors on .test domains with 30 days of history', function (): void {
    seedDemoMonitors();

    $monitors = demoMonitors();

    expect($monitors)->toHaveCount(18)
        ->and($monitors->every(fn (Monitor $monitor): bool => str_ends_with((string) $monitor->url?->getHost(), '.test')))->toBeTrue()
        ->and(Date::parse(MonitorCheck::query()->min('checked_at'))->toDateString())->toBe('2026-10-23');
});

it('replaces its own monitors on re-run and leaves others alone', function (): void {
    $real = Monitor::factory()->create(['name' => 'Real Site', 'url' => 'https://example.com']);
    MonitorCheck::factory()->for($real)->create();

    seedDemoMonitors();
    $checks = MonitorCheck::query()->count();

    seedDemoMonitors();

    expect(demoMonitors())->toHaveCount(18)
        ->and(MonitorCheck::query()->count())->toBe($checks)
        ->and($real->fresh())->not->toBeNull()
        ->and(MonitorCheck::query()->whereBelongsTo($real)->count())->toBe(1);
});

it('sends no notifications', function (): void {
    Notification::fake();

    seedDemoMonitors();

    Notification::assertNothingSent();
});

it('covers every monitoring state', function (): void {
    seedDemoMonitors();

    $monitors = demoMonitors();

    expect($monitors->map(fn (Monitor $monitor): string => $monitor->statusLabel())->all())->toMatchArray([
        'Steady Storefront' => 'Healthy',
        'Outage API' => 'Down',
        'Wobbly Checkout' => 'Degraded',
        'Sluggish Reports' => 'Degraded',
        'Flaky Status Page' => 'Healthy',
        'Recovered Bookings' => 'Healthy',
        'Brand New Landing' => 'Never checked',
        'Forgotten Wiki' => 'Stale',
        'Paused Intranet' => 'Paused',
        'Legacy Forum' => 'Healthy',
        'Troubled Coffee Shop' => 'Down',
    ])->and($monitors->map(fn (Monitor $monitor): CertificateHealth => $monitor->certificateHealth())->all())->toMatchArray([
        'Outage API' => CertificateHealth::Valid,
        'Expiring Portal' => CertificateHealth::ExpiringSoon,
        'Expired Travel' => CertificateHealth::Invalid,
        'Mismatched CDN' => CertificateHealth::Invalid,
        'Unreadable Certificate Blog' => CertificateHealth::InspectionFailed,
        'Troubled Coffee Shop' => CertificateHealth::Invalid,
        'Plain HTTP Archive' => CertificateHealth::Disabled,
        'Brand New Landing' => CertificateHealth::Unknown,
    ]);
});

it('derives uptime from the seeded checks and does not count missing history as success', function (): void {
    seedDemoMonitors();

    $history = UptimeHistory::make();

    foreach (demoMonitors() as $monitor) {
        $checks = MonitorCheck::query()->whereBelongsTo($monitor)->where('checked_at', '>=', Date::today()->subDays(29));
        $total = (clone $checks)->count();
        $expected = $total === 0 ? null : round((clone $checks)->where('succeeded', true)->count() / $total * 100, 2);

        expect($history->uptimePercentage($monitor))->toBe($expected, $monitor->name);
    }

    $monitors = demoMonitors();

    expect($history->uptimePercentage($monitors['Legacy Forum']))->toBeNull()
        ->and(collect($history->days($monitors['Fresh Microsite']))->where('state', 'none'))->toHaveCount(21)
        ->and(collect($history->days($monitors['Paused Intranet']))->take(-3)->pluck('state')->unique()->all())->toBe(['none']);
});

it('shows outages, failed checks and slow days in the history', function (): void {
    seedDemoMonitors();

    $monitors = demoMonitors();
    $states = fn (string $name): array => collect(UptimeHistory::make()->days($monitors[$name]))->countBy('state')->all();

    expect($states('Flaky Status Page'))->toMatchArray(['down' => 3, 'degraded' => 3])
        ->and($states('Recovered Bookings'))->toMatchArray(['down' => 1])
        ->and($states('Sluggish Reports')['slow'] ?? 0)->toBeGreaterThanOrEqual(5)
        ->and($states('Steady Storefront'))->toBe(['up' => 30]);
});

it('derives incidents from the history, closing resolved ones', function (): void {
    seedDemoMonitors();

    $monitors = demoMonitors();

    expect(MonitorIncident::query()->whereBelongsTo($monitors['Outage API'])->open()->count())->toBe(1)
        ->and(MonitorIncident::query()->whereBelongsTo($monitors['Recovered Bookings'])->open()->count())->toBe(0)
        ->and(MonitorIncident::query()->whereBelongsTo($monitors['Recovered Bookings'])->count())->toBe(1)
        ->and(MonitorIncident::query()->whereBelongsTo($monitors['Wobbly Checkout'])->count())->toBe(0);
});
