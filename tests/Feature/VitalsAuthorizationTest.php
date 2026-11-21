<?php

declare(strict_types=1);

use Awcodes\Vitals\Filament\Pages\VitalsPage;
use Awcodes\Vitals\Filament\Widgets\VitalsAlertsWidget;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Policies\MonitorPolicy;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

final class RestrictiveMonitorPolicy extends MonitorPolicy
{
    public static bool $canViewAny = true;

    public function viewAny(Authenticatable $user): bool
    {
        return self::$canViewAny;
    }

    public function delete(Authenticatable $user, Monitor $monitor): bool
    {
        return false;
    }

    public function runChecks(Authenticatable $user): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    RestrictiveMonitorPolicy::$canViewAny = true;
    Gate::policy(Monitor::class, RestrictiveMonitorPolicy::class);
    $this->actingAs(User::factory()->create());
});

it('hides the actions the policy denies', function (): void {
    $monitor = Monitor::factory()->create();

    livewire(VitalsPage::class)
        ->assertActionHidden('runChecks')
        ->assertActionHidden(TestAction::make('checkNow')->table($monitor))
        ->assertActionHidden(TestAction::make('delete')->table($monitor))
        ->assertActionVisible('addMonitor')
        ->assertActionVisible(TestAction::make('edit')->table($monitor))
        ->assertActionVisible(TestAction::make('pause')->table($monitor));
});

it('refuses denied actions even when called directly', function (): void {
    $monitor = Monitor::factory()->create();
    $artisan = Mockery::mock(Kernel::class);
    $artisan->shouldNotReceive('call');
    Artisan::swap($artisan);

    // Bypasses the test helpers, which refuse to call a hidden action, to
    // send what a crafted request would.
    livewire(VitalsPage::class)
        ->call('mountAction', 'runChecks')
        ->call('callMountedAction')
        ->call('mountTableAction', 'checkNow', (string) $monitor->getKey())
        ->call('callMountedAction')
        ->call('mountTableAction', 'delete', (string) $monitor->getKey())
        ->call('callMountedAction');

    expect($monitor->fresh())->not->toBeNull();
});

it('denies the page and hides the widget without viewAny', function (): void {
    RestrictiveMonitorPolicy::$canViewAny = false;

    $this->get(VitalsPage::getUrl())->assertForbidden();

    expect(VitalsAlertsWidget::canView())->toBeFalse();
});
