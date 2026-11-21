<?php

declare(strict_types=1);

use Awcodes\Focus\Enums\Viewport;
use Awcodes\Focus\Screenshot;
use Awcodes\Focus\ScreenshotSuite;

/*
 * Documentation screenshots for Vitals, generated with awcodes/focus from the
 * Workbench (run `composer build` first). The page shows VitalsDemoSeeder's
 * eighteen monitors, which cover every monitoring state.
 */

return ScreenshotSuite::make()
    ->screenshots([
        Screenshot::make('page')
            ->viewportSize(1440, 1100)
            ->visit('/admin/vitals')
            ->viewport(),

        Screenshot::make('page-mobile')
            ->viewportSize(Viewport::Mobile)
            ->visit('/admin/vitals')
            ->viewport(),

        // The first "Needs attention" entry is the demo outage; clicking it
        // opens the same inspection slide-over as the row action.
        Screenshot::make('inspect')
            ->viewportSize(1440, 1100)
            ->visit('/admin/vitals')
            ->click('[wire\:key^="monitor-issue-"]:first-child > button')
            ->waitFor('.vitals-inspect')
            ->viewport(),

        Screenshot::make('alerts-widget')
            ->visit('/admin')
            ->focus('#vitals-alerts-widget'),
    ]);
