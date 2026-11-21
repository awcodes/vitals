<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Policies\MonitorPolicy;
use Awcodes\Vitals\VitalsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

it('registers the default policy for the monitor model', function (): void {
    expect(Gate::getPolicyFor(Monitor::class))->toBeInstanceOf(MonitorPolicy::class);
});

it('allows every ability for an authenticated user by default', function (string $ability, bool $needsRecord): void {
    $user = User::factory()->create();
    $arguments = $needsRecord ? Monitor::factory()->create() : Monitor::class;

    expect(Gate::forUser($user)->allows($ability, $arguments))->toBeTrue();
})->with([
    'viewAny' => ['viewAny', false],
    'view' => ['view', true],
    'create' => ['create', false],
    'update' => ['update', true],
    'delete' => ['delete', true],
    'pause' => ['pause', true],
    'runChecks' => ['runChecks', false],
]);

it('denies guests', function (): void {
    expect(Gate::allows('viewAny', Monitor::class))->toBeFalse();
});

it('uses the policy class named in the config', function (): void {
    $policy = new class extends MonitorPolicy
    {
        public function runChecks($user): bool
        {
            return false;
        }
    };

    Gate::policy(Monitor::class, MonitorPolicy::class);
    (fn () => $this->policies = [])->call(Gate::getFacadeRoot());
    config()->set('vitals.policy', $policy::class);

    (fn () => $this->registerPolicy())->call(app()->getProvider(VitalsServiceProvider::class));

    expect(Gate::getPolicyFor(Monitor::class))->toBeInstanceOf($policy::class)
        ->and(Gate::forUser(User::factory()->create())->allows('runChecks', Monitor::class))->toBeFalse();
});

it('leaves a policy the app already registered in place', function (): void {
    $appPolicy = new class
    {
        public function viewAny($user): bool
        {
            return false;
        }
    };

    Gate::policy(Monitor::class, $appPolicy::class);

    (fn () => $this->registerPolicy())->call(app()->getProvider(VitalsServiceProvider::class));

    expect(Gate::getPolicyFor(Monitor::class))->toBeInstanceOf($appPolicy::class);
});
