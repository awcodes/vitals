<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Vitals;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Date;

it('prefers the configured timezone', function (): void {
    config()->set('vitals.timezone', 'America/New_York');
    FilamentTimezone::set('Europe/Paris');

    expect(Vitals::timezone())->toBe('America/New_York');
});

it('falls back to the Filament timezone', function (): void {
    config()->set('vitals.timezone', null);
    FilamentTimezone::set('Europe/Paris');

    expect(Vitals::timezone())->toBe('Europe/Paris');
});

it('falls back to the app timezone', function (): void {
    config()->set('vitals.timezone', null);
    config()->set('app.timezone', 'Asia/Tokyo');
    FilamentTimezone::set(null);

    expect(Vitals::timezone())->toBe('Asia/Tokyo');
});

it('shows a pause end in the resolved timezone', function (): void {
    config()->set('vitals.timezone', 'America/New_York');

    $monitor = Monitor::factory()->paused('Maintenance')->create(['paused_until' => Date::parse('2026-11-21 17:00:00', 'UTC')]);

    expect($monitor->pauseDescription())->toBe('Maintenance · until Nov 21, 12:00 PM');
});
