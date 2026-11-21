<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Commands;

use Awcodes\Vitals\Models\Monitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class ResumeExpiredMonitorPausesCommand extends Command
{
    /** @var string */
    protected $signature = 'monitors:resume-expired-pauses';

    /** @var string */
    protected $description = 'Resume monitoring for sites whose pause has reached its end time.';

    public function handle(): int
    {
        $monitors = Monitor::query()
            ->whereNotNull('paused_at')
            ->whereNotNull('paused_until')
            ->where('paused_until', '<=', Date::now())
            ->get();

        $monitors->each(fn (Monitor $monitor) => $monitor->resume());

        $this->info("Resumed {$monitors->count()} monitors.");

        return self::SUCCESS;
    }
}
