<?php

declare(strict_types=1);

use Awcodes\Vitals\Filament\Pages\VitalsPage;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

/**
 * Testbench's console kernel is final, so the Artisan facade can't mock it
 * directly. Swap in a mock of the contract instead.
 */
function fakeArtisan(): MockInterface
{
    $artisan = Mockery::mock(Kernel::class);
    Artisan::swap($artisan);

    return $artisan;
}

it('redirects guests to the login page', function (): void {
    $this->get(VitalsPage::getUrl())->assertRedirect();
});

describe('authenticated', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->create());
        // aw.codes shows dates in New York; the pause tests below depend on it.
        config()->set('vitals.timezone', 'America/New_York');
        config()->set('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures', 2);
    });

    it('renders the page', function (): void {
        $this->get(VitalsPage::getUrl())->assertOk()->assertSee('Vitals');
    });

    it('lists unhealthy monitors before healthy ones', function (): void {
        $healthy = Monitor::factory()->create(['name' => 'Alpha', 'uptime_last_check_date' => Date::now()]);
        $down = Monitor::factory()->down()->create(['name' => 'Zulu', 'uptime_check_times_failed_in_a_row' => 3, 'uptime_last_check_date' => Date::now()]);
        $paused = Monitor::factory()->paused()->down()->create(['name' => 'Beta']);
        $never = Monitor::factory()->create(['name' => 'Aardvark', 'uptime_status' => 'not yet checked', 'uptime_last_check_date' => null]);
        $sslProblem = Monitor::factory()->create(['name' => 'Yankee', 'uptime_last_check_date' => Date::now(), 'certificate_status' => 'invalid']);

        livewire(VitalsPage::class)
            ->assertCanSeeTableRecords([$down, $sslProblem, $never, $healthy, $paused], inOrder: true);
    });

    it('summarises the fleet and lists issues concisely', function (): void {
        Monitor::factory()->create(['name' => 'Fine Site', 'uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->addDays(60)]);
        Monitor::factory()->down('cURL error 7: Failed to connect to host port 443')->create(['name' => 'Broken Site', 'uptime_check_times_failed_in_a_row' => 3, 'uptime_last_check_date' => Date::now()]);
        Monitor::factory()->create(['name' => 'Expiring Site', 'uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->addDays(3)]);
        Monitor::factory()->create(['name' => 'Brand New', 'uptime_status' => 'not yet checked', 'uptime_last_check_date' => null]);

        livewire(VitalsPage::class)
            ->assertSeeInOrder(['Healthy', '2', 'Down or degraded', '1', 'Unknown', '1', 'Paused', '0', 'SSL issues', '1'])
            ->assertSeeHtml('monitor-issue-')
            ->assertSeeInOrder(['Broken Site', 'Down · 3 failed checks in a row', 'Expiring Site', 'SSL certificate expires in 3 days'])
            ->assertDontSee('cURL error 7')
            ->assertDontSee('View all');
    });

    it('caps the attention list and links to the full set', function (): void {
        Monitor::factory()->count(7)->down()->sequence(fn ($sequence): array => ['name' => 'Outage ' . $sequence->index])->create([
            'uptime_check_times_failed_in_a_row' => 2,
            'uptime_last_check_date' => Date::now(),
        ]);

        $html = livewire(VitalsPage::class)
            ->assertSee('View all 7')
            ->html();

        expect(mb_substr_count($html, 'monitor-issue-'))->toBe(5);
    });

    it('hides the attention list when nothing is actionable', function (): void {
        Monitor::factory()->create(['uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->addDays(60)]);
        Monitor::factory()->create(['uptime_status' => 'not yet checked', 'uptime_last_check_date' => null]);

        livewire(VitalsPage::class)->assertDontSeeHtml('monitor-issue-');
    });

    it('filters by status from the summary strip and toggles back to all', function (): void {
        $healthy = Monitor::factory()->create(['uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->addDays(60)]);
        $stale = Monitor::factory()->create(['uptime_last_check_date' => Date::now()->subDay(), 'certificate_expiration_date' => Date::now()->addDays(60)]);
        $never = Monitor::factory()->create(['uptime_status' => 'not yet checked', 'uptime_last_check_date' => null]);

        livewire(VitalsPage::class)
            ->call('filterMonitorsByState', 'unknown')
            ->assertCanSeeTableRecords([$stale, $never])
            ->assertCanNotSeeTableRecords([$healthy])
            ->call('filterMonitorsByState', 'unknown')
            ->assertCanSeeTableRecords([$healthy, $stale, $never]);
    });

    it('ignores unknown quick filter values', function (): void {
        $monitor = Monitor::factory()->create();

        livewire(VitalsPage::class)
            ->call('filterMonitorsByState', 'drop table')
            ->assertSet('tableFilters.state.value', null)
            ->assertCanSeeTableRecords([$monitor]);
    });

    it('filters by status without mixing in certificate problems', function (string $state, array $visible, array $hidden): void {
        $records = [
            'healthy' => Monitor::factory()->create(['uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->addDays(60)]),
            'healthyWithBadCertificate' => Monitor::factory()->create(['uptime_last_check_date' => Date::now(), 'certificate_status' => 'invalid', 'certificate_expiration_date' => Date::now()->addDays(60)]),
            'down' => Monitor::factory()->down()->create(['uptime_check_times_failed_in_a_row' => 2, 'uptime_last_check_date' => Date::now()]),
            'paused' => Monitor::factory()->paused()->create(),
        ];

        livewire(VitalsPage::class)
            ->filterTable('state', $state)
            ->assertCanSeeTableRecords(array_map(fn (string $key): Monitor => $records[$key], $visible))
            ->assertCanNotSeeTableRecords(array_map(fn (string $key): Monitor => $records[$key], $hidden));
    })->with([
        'healthy' => ['healthy', ['healthy', 'healthyWithBadCertificate'], ['down', 'paused']],
        'down or degraded' => ['issues', ['down'], ['healthy', 'healthyWithBadCertificate', 'paused']],
        'needs attention' => ['attention', ['down', 'healthyWithBadCertificate'], ['healthy', 'paused']],
        'paused' => ['paused', ['paused'], ['healthy', 'healthyWithBadCertificate', 'down']],
    ]);

    it('filters to certificate problems separately', function (): void {
        $badCertificate = Monitor::factory()->create(['uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->addDays(2)]);
        $fine = Monitor::factory()->create(['uptime_last_check_date' => Date::now(), 'certificate_expiration_date' => Date::now()->addDays(60)]);

        livewire(VitalsPage::class)
            ->call('filterMonitorsByCertificate')
            ->assertCanSeeTableRecords([$badCertificate])
            ->assertCanNotSeeTableRecords([$fine]);
    });

    it('always shows a rolling 30 day window and reports partial coverage per monitor', function (): void {
        Date::setTestNow('2026-11-21 12:00');
        $partial = Monitor::factory()->create();
        $full = Monitor::factory()->create();
        MonitorCheck::factory()->for($partial)->count(2)->create(['checked_at' => Date::parse('2026-11-19 10:00')]);
        MonitorCheck::factory()->for($full)->create(['checked_at' => Date::parse('2026-10-23 10:00')]);

        livewire(VitalsPage::class)
            ->assertSee('Last 30 days')
            ->assertDontSee('Since Nov')
            ->assertSeeHtml('checks recorded since Nov 19')
            ->assertSeeHtml('full period recorded')
            ->assertTableColumnStateSet('uptime', 100.0, $partial);
    });

    it('searches by name and url', function (): void {
        $match = Monitor::factory()->create(['name' => 'Docs Hub', 'url' => 'https://docs.example.com']);
        $other = Monitor::factory()->create(['name' => 'Shop', 'url' => 'https://shop.example.com']);

        livewire(VitalsPage::class)
            ->searchTable('docs.example')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    });

    it('renders uptime and history from recorded checks', function (): void {
        $monitors = Monitor::factory()->count(5)->create(['uptime_last_check_date' => Date::now()]);
        $monitors->each(fn (Monitor $monitor) => MonitorCheck::factory()->for($monitor)->count(3)->create());

        $component = livewire(VitalsPage::class)
            ->assertTableColumnStateSet('uptime', 100.0, $monitors->first());

        expect($component->html())->toContain('3 checks, all passed');
    });

    it('does not add queries per monitor row', function (): void {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            livewire(VitalsPage::class);

            return count(DB::getQueryLog());
        };

        Monitor::factory()->count(2)->has(MonitorCheck::factory()->count(2), 'checks')->create();
        $few = $countQueries();

        Monitor::factory()->count(8)->has(MonitorCheck::factory()->count(2), 'checks')->create();

        expect($countQueries())->toBe($few);
    });

    it('opens the details slide-over', function (): void {
        $monitor = Monitor::factory()->create(['name' => 'Inspect Me', 'certificate_issuer' => "Let's Encrypt"]);
        MonitorCheck::factory()->for($monitor)->failed('HTTP 502')->create();
        MonitorIncident::factory()->for($monitor)->resolved()->create(['failure_reason' => 'Gateway timeout']);

        livewire(VitalsPage::class)
            ->mountAction(TestAction::make('inspect')->table($monitor))
            ->assertMountedActionModalSee(['Recent incidents', 'Gateway timeout', 'Technical details', 'HTTP 502', "Let's Encrypt", 'Daily average']);
    });

    it('pauses a monitor with a reason and an automatic resume time', function (): void {
        $monitor = Monitor::factory()->create();
        Date::setTestNow('2026-11-21 12:00:00');
        $localResumeTime = '2026-11-23 15:00:00';

        livewire(VitalsPage::class)
            ->assertActionHidden(TestAction::make('resume')->table($monitor))
            ->callAction(TestAction::make('pause')->table($monitor), ['reason' => 'Server migration', 'until' => $localResumeTime])
            ->assertHasNoFormErrors()
            ->assertNotified('Monitoring paused');

        $monitor->refresh();

        expect($monitor->isPaused())->toBeTrue()
            ->and($monitor->pause_reason)->toBe('Server migration')
            ->and($monitor->paused_until?->toDateTimeString())->toBe('2026-11-23 20:00:00')
            ->and($monitor->uptime_check_enabled)->toBeTrue();
    });

    it('rejects an automatic resume time in the past', function (): void {
        $monitor = Monitor::factory()->create();

        livewire(VitalsPage::class)
            ->callAction(TestAction::make('pause')->table($monitor), ['until' => Date::now()->subDay()->toDateTimeString()])
            ->assertHasFormErrors(['until']);

        expect($monitor->refresh()->isPaused())->toBeFalse();
    });

    it('resumes a paused monitor and checks it straight away', function (): void {
        $monitor = Monitor::factory()->paused()->create(['url' => 'https://93.184.215.14']);
        $artisan = fakeArtisan();
        $artisan->shouldReceive('call')->once()->with('monitor:check-uptime', ['--url' => 'https://93.184.215.14', '--force' => true]);
        $artisan->shouldReceive('call')->once()->with('monitor:check-certificate', ['--url' => 'https://93.184.215.14']);

        livewire(VitalsPage::class)
            ->assertActionHidden(TestAction::make('pause')->table($monitor))
            ->callAction(TestAction::make('resume')->table($monitor))
            ->assertNotified('Monitoring resumed');

        expect($monitor->refresh()->isPaused())->toBeFalse();
    });

    it('keeps paused monitors out of the attention list and summary', function (): void {
        Monitor::factory()->down('Connection refused')->paused('Rebuilding')->create([
            'name' => 'Paused Site',
            'uptime_check_times_failed_in_a_row' => 5,
            'uptime_last_check_date' => Date::now(),
            'certificate_status' => 'invalid',
        ]);

        livewire(VitalsPage::class)
            ->assertSeeInOrder(['Down or degraded', '0', 'Unknown', '0', 'Paused', '1', 'SSL issues', '0'])
            ->assertDontSeeHtml('monitor-issue-')
            ->assertSee('Rebuilding');
    });

    it('filters by paused state', function (): void {
        $active = Monitor::factory()->create();
        $paused = Monitor::factory()->paused()->create();

        livewire(VitalsPage::class)
            ->filterTable('paused', true)
            ->assertCanSeeTableRecords([$paused])
            ->assertCanNotSeeTableRecords([$active]);
    });

    it('runs uptime checks for every monitor in one concurrent pass', function (): void {
        fakeArtisan()->shouldReceive('call')->once()->with('monitor:check-uptime', ['--force' => true]);

        livewire(VitalsPage::class)
            ->assertActionHasLabel('runChecks', 'Run uptime checks')
            ->callAction('runChecks')
            ->assertNotified('Uptime checks finished');
    });

    it('reads the automatic resume time in the display timezone', function (): void {
        $monitor = Monitor::factory()->create();

        livewire(VitalsPage::class)
            ->mountAction(TestAction::make('pause')->table($monitor))
            ->assertFormFieldExists('until', checkFieldUsing: fn (DateTimePicker $field): bool => $field->getTimezone() === 'America/New_York');
    });

    it('rejects a URL that only differs from a monitored one by a trailing slash', function (): void {
        Monitor::factory()->create(['url' => 'https://93.184.215.14']);

        livewire(VitalsPage::class)
            ->callAction('addMonitor', ['name' => 'Duplicate', 'url' => 'https://93.184.215.14/'])
            ->assertHasFormErrors(['url']);

        expect(Monitor::query()->count())->toBe(1);
    });

    it('lets a monitor keep its own URL when edited with a trailing slash', function (): void {
        $monitor = Monitor::factory()->create(['url' => 'https://93.184.215.14', 'name' => 'Before']);

        livewire(VitalsPage::class)
            ->callAction(TestAction::make('edit')->table($monitor), ['name' => 'After', 'url' => 'https://93.184.215.14/'])
            ->assertHasNoFormErrors();

        expect($monitor->refresh()->name)->toBe('After');
    });

    it('adds a monitor and checks it straight away', function (): void {
        $artisan = fakeArtisan();
        $artisan->shouldReceive('call')->once()->with('monitor:check-uptime', ['--url' => 'https://93.184.215.14', '--force' => true]);
        $artisan->shouldReceive('call')->once()->with('monitor:check-certificate', ['--url' => 'https://93.184.215.14']);

        livewire(VitalsPage::class)
            ->callAction('addMonitor', ['name' => 'Public', 'url' => 'https://93.184.215.14/'])
            ->assertHasNoFormErrors();

        expect(Monitor::query()->where('name', 'Public')->sole()->certificate_check_enabled)->toBeTrue();
    });

    it('refuses to monitor private network addresses', function (): void {
        livewire(VitalsPage::class)
            ->callAction('addMonitor', ['name' => 'Metadata', 'url' => 'http://169.254.169.254'])
            ->assertHasFormErrors(['url']);

        expect(Monitor::query()->count())->toBe(0);
    });
});
