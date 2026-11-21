<?php

declare(strict_types=1);

use Awcodes\Focus\Card;
use Awcodes\Focus\Enums\Size;
use Awcodes\Focus\Screenshot;
use Awcodes\Focus\ScreenshotSuite;

/*
 * Documentation screenshots and share images for Vitals, generated with
 * awcodes/focus from the Workbench (run `composer build` first). The page shows
 * VitalsDemoSeeder's eighteen monitors, which cover every monitoring state.
 */

// The first "Needs attention" entry is the demo outage; clicking it opens the
// same inspection slide-over as the row action.
$firstIssue = '[wire\:key^="monitor-issue-"]:first-child > button';

// The awcodes card templates frame each screenshot at 1400x816. Card sources are
// captured in that shape at three quarters and half size, and the templates
// scale them up.
$cardPage = [1050, 612];
// The slide-over is 42rem (672px) wide, so its card source is captured at that
// width and fills the frame edge to edge.
$cardInspect = [672, 392];

return ScreenshotSuite::make()
    ->screenshots([
        Screenshot::make('page')
            ->viewportSize(1440, 1100)
            ->visit('/admin/vitals')
            ->viewport(),

        Screenshot::make('inspect')
            ->viewportSize(1440, 1100)
            ->visit('/admin/vitals')
            ->click($firstIssue)
            ->waitFor('.vitals-inspect')
            ->viewport(),

        Screenshot::make('alerts-widget')
            ->visit('/admin')
            ->focus('#vitals-alerts-widget'),

        // Share-image sources, shaped to the card templates' screenshot slots. The
        // two-up templates show slot 1 dark, small and in front at the lower left,
        // and slot 2 light as the large screenshot at the back on the right. The
        // page is the main subject, so it takes the large slot and the slide-over
        // the small one; both are captured in both themes.
        Screenshot::make('card-page')
            ->viewportSize(...$cardPage)
            ->visit('/admin/vitals')
            ->viewport(),

        Screenshot::make('card-inspect')
            ->viewportSize(...$cardInspect)
            ->visit('/admin/vitals')
            ->click($firstIssue)
            ->waitFor('.vitals-inspect')
            ->viewport(),
    ])
    ->cardTemplates('https://github.com/awcodes/focus-templates/tree/v2.1.0/dist')
    ->cards([
        // Open Graph and the GitHub social preview share one 2400x1260 template;
        // GitHub crops 30px top and bottom.
        Card::make('social')
            ->template('two-up-wide')
            ->title('Vitals')
            ->screenshots(['card-inspect', 'card-page'])
            ->sizes([Size::OpenGraph, Size::GitHubSocial]),

        // The Filament plugin directory's 2560x1440 thumbnail.
        Card::make('thumbnail')
            ->template('two-up')
            ->title('Vitals')
            ->screenshots(['card-inspect', 'card-page'])
            ->sizes([Size::Filament]),

        // Unbranded 16:9 image for aw.codes, which adds its own heading: the same
        // screenshots, no text or logo.
        Card::make('plain')
            ->template('two-up-plain')
            ->screenshots(['card-inspect', 'card-page'])
            ->sizes([[2560, 1440]])
            ->scale(1),
    ]);
