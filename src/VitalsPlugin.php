<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Awcodes\Vitals\Filament\Pages\VitalsPage;
use Awcodes\Vitals\Filament\Widgets\VitalsAlertsWidget;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Throwable;
use UnitEnum;

class VitalsPlugin implements Plugin
{
    public const string ID = 'vitals';

    protected string $navigationLabel = 'Vitals';

    protected string $navigationIcon = 'vitals-heartbeat';

    protected string | UnitEnum | null $navigationGroup = null;

    protected ?int $navigationSort = 100;

    protected string $slug = 'vitals';

    protected ?string $timezone = null;

    protected bool $hasAlertsWidget = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    /**
     * The plugin registered on the current panel, or null outside a panel
     * request or when the panel does not use it. Pages and widgets fall
     * back to the defaults then.
     */
    public static function current(?Panel $panel = null): ?static
    {
        try {
            $panel ??= Filament::getCurrentOrDefaultPanel();
        } catch (Throwable) {
            return null;
        }

        if (! $panel instanceof Panel || ! $panel->hasPlugin(self::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(self::ID);

        return $plugin instanceof static ? $plugin : null;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([VitalsPage::class]);

        if ($this->hasAlertsWidget) {
            $panel->widgets([VitalsAlertsWidget::class]);
        }
    }

    public function boot(Panel $panel): void {}

    public function navigationLabel(string $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function navigationIcon(string $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function navigationGroup(string | UnitEnum | null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function slug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * Overrides vitals.timezone for this panel.
     */
    public function timezone(?string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function alertsWidget(bool $condition = true): static
    {
        $this->hasAlertsWidget = $condition;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        return $this->navigationLabel;
    }

    public function getNavigationIcon(): string
    {
        return $this->navigationIcon;
    }

    public function getNavigationGroup(): string | UnitEnum | null
    {
        return $this->navigationGroup;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function hasAlertsWidget(): bool
    {
        return $this->hasAlertsWidget;
    }
}
