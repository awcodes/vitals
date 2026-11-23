<?php

declare(strict_types=1);

use Awcodes\Vitals\Filament\Pages\VitalsPage;
use Awcodes\Vitals\Filament\Widgets\VitalsAlertsWidget;
use Awcodes\Vitals\Vitals;
use Awcodes\Vitals\VitalsPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Workbench\App\Models\User;

function vitalsPanel(VitalsPlugin $plugin): Panel
{
    $panel = Panel::make()->id('custom')->path('custom')->plugin($plugin);
    Filament::setCurrentPanel($panel);

    return $panel;
}

it('uses the defaults on the workbench panel', function (): void {
    expect(VitalsPage::getSlug())->toBe('vitals')
        ->and(VitalsPage::getNavigationLabel())->toBe('Vitals')
        ->and(VitalsPage::getNavigationIcon())->toBe('vitals-heartbeat')
        ->and(VitalsPage::getNavigationGroup())->toBeNull()
        ->and(VitalsPage::getNavigationSort())->toBe(100)
        ->and(Filament::getPanel('admin')->getPages())->toContain(VitalsPage::class)
        ->and(Filament::getPanel('admin')->getWidgets())->toContain(VitalsAlertsWidget::class);
});

it('applies the fluent navigation options', function (): void {
    $panel = vitalsPanel(VitalsPlugin::make()
        ->navigationLabel('Uptime')
        ->navigationIcon('heroicon-o-signal')
        ->navigationGroup('Operations')
        ->navigationSort(5)
        ->slug('uptime'));

    expect(VitalsPage::getSlug($panel))->toBe('uptime')
        ->and(VitalsPage::getNavigationLabel())->toBe('Uptime')
        ->and(VitalsPage::getNavigationIcon())->toBe('heroicon-o-signal')
        ->and(VitalsPage::getNavigationGroup())->toBe('Operations')
        ->and(VitalsPage::getNavigationSort())->toBe(5);
});

it('accepts an icon enum', function (): void {
    vitalsPanel(VitalsPlugin::make()->navigationIcon(Heroicon::OutlinedSignal));

    expect(VitalsPage::getNavigationIcon())->toBe(Heroicon::OutlinedSignal);
});

it('can leave out the navigation icon', function (): void {
    vitalsPanel(VitalsPlugin::make()->navigationIcon(null));

    expect(VitalsPage::getNavigationIcon())->toBeNull();
});

it('can leave out the alerts widget', function (): void {
    $panel = vitalsPanel(VitalsPlugin::make()->alertsWidget(false));

    expect($panel->getPages())->toContain(VitalsPage::class)
        ->and($panel->getWidgets())->not->toContain(VitalsAlertsWidget::class);
});

it('prefers the plugin timezone over the config', function (): void {
    config()->set('vitals.timezone', 'America/New_York');
    vitalsPanel(VitalsPlugin::make()->timezone('Europe/Berlin'));

    expect(Vitals::timezone())->toBe('Europe/Berlin');
});

it('shows the page in the navigation with the heartbeat icon', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Vitals')
        ->assertSee('M2.25 12h4.5l2.25-6.75 4.5 13.5 2.25-6.75h6', escape: false);
});

it('registers its stylesheet as a Filament asset', function (): void {
    expect(FilamentAsset::getStyleHref('vitals', 'awcodes/vitals'))->toContain('vitals.css');
});
