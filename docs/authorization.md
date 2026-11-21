---
title: Authorization
description: Control who can view and change monitors through the Vitals policy.
---

# Authorization

Vitals authorizes the page and every action through a policy registered for its `Monitor` model.

## The default policy

`Awcodes\Vitals\Policies\MonitorPolicy` allows every ability for any authenticated user. Access to the panel itself stays with your user model's `canAccessPanel()`, so by default anyone who can reach the panel can use Vitals fully.

| Ability | Controls |
| --- | --- |
| `viewAny` | The Vitals page and the alerts widget. |
| `view` | Inspecting a monitor and **Open server**. |
| `create` | **Add monitor**. |
| `update` | **Edit**. |
| `delete` | **Delete**. |
| `pause` | **Pause monitoring** and **Resume monitoring**. |
| `runChecks` | **Run uptime checks** and **Check now**. It is checked against the model class, without a record. |

An action the policy denies is hidden, and calling it anyway is refused.

## Using your own policy

Extend the default policy and override the abilities you want to restrict:

```php
namespace App\Policies;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Policies\MonitorPolicy;
use Illuminate\Contracts\Auth\Authenticatable;

class VitalsMonitorPolicy extends MonitorPolicy
{
    public function delete(Authenticatable $user, Monitor $monitor): bool
    {
        return $user->is_admin;
    }

    public function runChecks(Authenticatable $user): bool
    {
        return $user->is_admin;
    }
}
```

Then register it in one of two ways:

- Set it in `config/vitals.php`:

  ```php
  'policy' => \App\Policies\VitalsMonitorPolicy::class,
  ```

- Or register it yourself, for example in a service provider's `boot()` method:

  ```php
  use Awcodes\Vitals\Models\Monitor;
  use Illuminate\Support\Facades\Gate;

  Gate::policy(Monitor::class, \App\Policies\VitalsMonitorPolicy::class);
  ```

Vitals only registers `vitals.policy` when no policy is registered for the model yet, and a policy your application registers later replaces it.
