<?php

declare(strict_types=1);

use Awcodes\Vitals\VitalsPlugin;
use Filament\Facades\Filament;
use Workbench\App\Filament\Pages\Auth\Login;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

it('registers the plugin on the workbench panel', function (): void {
    expect(Filament::getPanel('admin')->getPlugin('vitals'))->toBeInstanceOf(VitalsPlugin::class);
});

it('prefills the workbench login and signs the development user in', function (): void {
    User::factory()->create(['email' => 'test@example.com']);

    livewire(Login::class)
        ->assertSet('data.email', 'test@example.com')
        ->assertSet('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
    $this->get('/admin')->assertOk();
});
