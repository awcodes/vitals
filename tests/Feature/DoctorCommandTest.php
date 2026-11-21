<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Spatie\UptimeMonitor\Models\Monitor as SpatieMonitor;

it('passes when everything is in place', function (): void {
    $this->artisan('vitals:doctor')
        ->expectsOutputToContain('Vitals is set up.')
        ->assertSuccessful();
});

it('reports the uptime-monitor settings that are missing', function (): void {
    config()->set('uptime-monitor.monitor_model', SpatieMonitor::class);
    config()->set('uptime-monitor.actions.send_uptime_check_request', null);

    $this->artisan('vitals:doctor')
        ->expectsOutputToContain("Set 'monitor_model'")
        ->expectsOutputToContain("Set 'actions.send_uptime_check_request'")
        ->expectsOutputToContain('2 required settings missing')
        ->assertFailed();
});

it('only warns about the recommended resend setting', function (): void {
    config()->set('uptime-monitor.notifications.resend_uptime_check_failed_notification_every_minutes', 60);

    $this->artisan('vitals:doctor')
        ->expectsOutputToContain('Recommended:')
        ->assertSuccessful();
});

it('reports missing tables and columns', function (): void {
    Schema::drop('monitor_daily_stats');
    Schema::table('monitors', fn ($table) => $table->dropColumn('pause_reason'));

    $this->artisan('vitals:doctor')
        ->expectsOutputToContain('monitors is missing pause_reason')
        ->expectsOutputToContain('monitor_daily_stats is missing')
        ->assertFailed();
});

it('changes nothing', function (): void {
    config()->set('uptime-monitor.monitor_model', SpatieMonitor::class);

    $this->artisan('vitals:doctor')->assertFailed();

    expect(config('uptime-monitor.monitor_model'))->toBe(SpatieMonitor::class);
});
