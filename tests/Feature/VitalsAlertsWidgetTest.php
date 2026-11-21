<?php

declare(strict_types=1);

use Awcodes\Vitals\Filament\Widgets\VitalsAlertsWidget;
use Awcodes\Vitals\Models\Monitor;
use Livewire\Livewire;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('renders the widget', function (): void {
    Livewire::test(VitalsAlertsWidget::class)
        ->assertOk();
});

it('shows a healthy state when no checks are failing', function (): void {
    Monitor::factory()->create(['name' => 'Healthy Site']);

    Livewire::test(VitalsAlertsWidget::class)
        ->assertSee('All monitored sites are passing')
        ->assertDontSee('Healthy Site');
});

it('lists sites with a failing uptime check', function (): void {
    Monitor::factory()->down('Connection refused')->create(['name' => 'Down Site']);

    Livewire::test(VitalsAlertsWidget::class)
        ->assertSee('Down Site')
        ->assertSee('Connection refused');
});

it('lists sites with a failing certificate check', function (): void {
    Monitor::factory()->invalidCertificate('The certificate has expired')->create(['name' => 'Expired Site']);

    Livewire::test(VitalsAlertsWidget::class)
        ->assertSee('Expired Site')
        ->assertSee('The certificate has expired');
});

it('ignores failures for checks that are disabled', function (): void {
    Monitor::factory()
        ->down()
        ->invalidCertificate()
        ->create([
            'name' => 'Unmonitored Site',
            'uptime_check_enabled' => false,
            'certificate_check_enabled' => false,
        ]);

    Livewire::test(VitalsAlertsWidget::class)
        ->assertDontSee('Unmonitored Site');
});

it('ignores paused sites', function (): void {
    Monitor::factory()->down()->invalidCertificate()->paused()->create(['name' => 'Paused Site']);

    Livewire::test(VitalsAlertsWidget::class)
        ->assertDontSee('Paused Site');
});

it('refreshes when sites are updated elsewhere', function (): void {
    $component = Livewire::test(VitalsAlertsWidget::class)
        ->assertDontSee('Late Failure');

    Monitor::factory()->down()->create(['name' => 'Late Failure']);

    $component->dispatch(VitalsAlertsWidget::REFRESH_EVENT)
        ->assertSee('Late Failure');
});

it('shows the host for a failing monitor without a name', function (): void {
    Monitor::factory()->down('Connection refused')->create(['name' => null, 'url' => 'https://unnamed.example.com']);

    Livewire::test(VitalsAlertsWidget::class)
        ->assertSet('failingSites.0.name', 'unnamed.example.com')
        ->assertSee('Connection refused');
});
