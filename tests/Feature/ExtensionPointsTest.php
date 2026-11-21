<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Awcodes\Vitals\Support\MonitorInspection;
use Awcodes\Vitals\Support\TimedUptimeCheckRequest;
use Awcodes\Vitals\Support\UptimeCheckTimings;
use Awcodes\Vitals\Support\UptimeHistory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Notification;
use Spatie\UptimeMonitor\MonitorRepository;

final class AppMonitor extends Monitor
{
    protected $table = 'monitors';

    public function owner(): string
    {
        return 'ops';
    }
}

final class AppUptimeCheckRequest extends TimedUptimeCheckRequest
{
    protected function requestOptions(Spatie\UptimeMonitor\Models\Monitor $monitor): array
    {
        return [...parent::requestOptions($monitor), 'headers' => ['X-Checked-By' => 'app']];
    }
}

beforeEach(function (): void {
    Notification::fake();
    config()->set('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures', 2);
    config()->set('uptime-monitor.monitor_model', AppMonitor::class);
    config()->set('uptime-monitor.actions.send_uptime_check_request', AppUptimeCheckRequest::class);
});

it('lets Spatie load an app subclass of the Vitals monitor', function (): void {
    Monitor::factory()->create(['uptime_status' => 'not yet checked']);

    $monitors = MonitorRepository::getForUptimeCheck();

    expect($monitors)->toHaveCount(1)
        ->and($monitors->first())->toBeInstanceOf(AppMonitor::class)
        ->and($monitors->first()->owner())->toBe('ops');
});

it('keeps recording checks and incidents through the subclass', function (): void {
    $monitor = AppMonitor::query()->findOrFail(Monitor::factory()->create()->getKey());

    $monitor->uptimeRequestFailed('Connection refused');
    $monitor->uptimeRequestFailed('Connection refused');

    expect(MonitorCheck::query()->count())->toBe(2)
        ->and(MonitorIncident::query()->open()->count())->toBe(1)
        ->and(MonitorInspection::for($monitor->load('latestCheck'), UptimeHistory::make(), 'UTC')->incidents)->toHaveCount(1);
});

it('keeps the timing capture and redirect guard in a request subclass', function (): void {
    $monitor = Monitor::factory()->create();

    $options = (fn ($monitor): array => $this->requestOptions($monitor))->call(new AppUptimeCheckRequest, $monitor);
    $options['on_stats'](new TransferStats(new Request('HEAD', 'https://example.com'), new Response(200), 0.05));

    expect($options['headers'])->toBe(['X-Checked-By' => 'app'])
        ->and($options['allow_redirects'])->toHaveKey('on_redirect')
        ->and(resolve(UptimeCheckTimings::class)->pull($monitor->id))->toBe(['http_status' => 200, 'response_time_ms' => 50]);
});

it('accepts the subclasses in vitals:doctor', function (): void {
    $this->artisan('vitals:doctor')
        ->expectsOutputToContain('Vitals is set up.')
        ->assertSuccessful();
});
