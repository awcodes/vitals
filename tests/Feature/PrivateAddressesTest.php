<?php

declare(strict_types=1);

use Awcodes\Vitals\Filament\Pages\VitalsPage;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\TimedUptimeCheckRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

function redirectOptions(Monitor $monitor): array
{
    return (fn (Monitor $monitor): array => $this->requestOptions($monitor))->call(new TimedUptimeCheckRequest, $monitor);
}

it('blocks private addresses by default', function (): void {
    expect(config('vitals.allow_private_addresses'))->toBeFalse()
        ->and(redirectOptions(Monitor::factory()->create()))->toHaveKey('allow_redirects');
});

it('follows redirects to private addresses when they are allowed', function (): void {
    config()->set('vitals.allow_private_addresses', true);
    $monitor = Monitor::factory()->create(['url' => 'https://93.184.215.14']);
    $options = redirectOptions($monitor);

    $handler = new MockHandler([new Response(302, ['Location' => 'http://10.0.0.12/']), new Response(200)]);
    $response = (new Client(['handler' => HandlerStack::create($handler)]))->request('HEAD', $monitor->raw_url, $options);

    expect($options)->not->toHaveKey('allow_redirects')
        ->and($response->getStatusCode())->toBe(200)
        ->and($handler->count())->toBe(0);
});

it('lets the add form accept a private address when they are allowed', function (): void {
    config()->set('vitals.allow_private_addresses', true);
    $this->actingAs(User::factory()->create());
    $artisan = Mockery::mock(Kernel::class);
    $artisan->shouldReceive('call');
    Artisan::swap($artisan);

    livewire(VitalsPage::class)
        ->callAction('addMonitor', ['name' => 'Intranet', 'url' => 'http://10.0.0.12'])
        ->assertHasNoFormErrors();

    expect(Monitor::query()->where('name', 'Intranet')->exists())->toBeTrue();
});

it('warns in vitals:doctor when private addresses are allowed', function (): void {
    config()->set('vitals.allow_private_addresses', true);

    $this->artisan('vitals:doctor')
        ->expectsOutputToContain('vitals.allow_private_addresses is on')
        ->assertSuccessful();
});
