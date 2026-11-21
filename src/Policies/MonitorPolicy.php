<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Policies;

use Awcodes\Vitals\Models\Monitor;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default policy: any authenticated user who can reach the panel may do
 * everything. Panel access itself stays with the app's canAccessPanel().
 * Swap it through vitals.policy, or register your own policy for the
 * Monitor model, to tighten access.
 */
class MonitorPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Monitor $monitor): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, Monitor $monitor): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, Monitor $monitor): bool
    {
        return true;
    }

    /**
     * Covers both pausing and resuming.
     */
    public function pause(Authenticatable $user, Monitor $monitor): bool
    {
        return true;
    }

    /**
     * Running uptime checks on demand: every monitor at once, or one with
     * "Check now".
     */
    public function runChecks(Authenticatable $user): bool
    {
        return true;
    }
}
