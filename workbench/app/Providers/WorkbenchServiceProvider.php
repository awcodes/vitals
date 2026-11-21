<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\TimedUptimeCheckRequest;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * The uptime-monitor settings the installation docs ask a consuming app
     * to set in config/uptime-monitor.php.
     */
    public function register(): void
    {
        config([
            'uptime-monitor.monitor_model' => Monitor::class,
            'uptime-monitor.actions.send_uptime_check_request' => TimedUptimeCheckRequest::class,
            'uptime-monitor.notifications.resend_uptime_check_failed_notification_every_minutes' => 0,
        ]);
    }
}
