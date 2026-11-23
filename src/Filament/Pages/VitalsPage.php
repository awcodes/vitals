<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Filament\Pages;

use Awcodes\Vitals\Enums\CertificateHealth;
use Awcodes\Vitals\Enums\MonitorHealth;
use Awcodes\Vitals\Filament\Widgets\VitalsAlertsWidget;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Support\MonitorInspection;
use Awcodes\Vitals\Support\PublicHost;
use Awcodes\Vitals\Support\UptimeHistory;
use Awcodes\Vitals\Vitals;
use Awcodes\Vitals\VitalsPlugin;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Override;
use UnitEnum;

final class VitalsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var array<string, string> */
    private const array STATE_FILTERS = [
        'attention' => 'Needs attention',
        'issues' => 'Down or degraded',
        'unknown' => 'Never checked or stale',
        'healthy' => 'Healthy',
        'paused' => 'Paused',
    ];

    protected string $view = 'vitals::pages.vitals';

    private ?UptimeHistory $history = null;

    /** @var Collection<int, Monitor>|null */
    private ?Collection $monitors = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return VitalsPlugin::current($panel)?->getSlug() ?? 'vitals';
    }

    public static function getNavigationLabel(): string
    {
        return VitalsPlugin::current()?->getNavigationLabel() ?? 'Vitals';
    }

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        $plugin = VitalsPlugin::current();

        return $plugin instanceof VitalsPlugin ? $plugin->getNavigationIcon() : 'vitals-heartbeat';
    }

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        return VitalsPlugin::current()?->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $plugin = VitalsPlugin::current();

        return $plugin instanceof VitalsPlugin ? $plugin->getNavigationSort() : 100;
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
    }

    #[Override]
    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    #[Override]
    public function getSubheading(): ?string
    {
        $monitors = $this->monitors();

        if ($monitors->isEmpty()) {
            return null;
        }

        $lastCheck = $monitors->max('uptime_last_check_date');

        return collect([
            $monitors->count() . ' ' . str('website')->plural($monitors->count()),
            $lastCheck ? 'last check ' . $lastCheck->diffForHumans() : 'not checked yet',
        ])->implode(' · ');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Monitor::query()->with('latestCheck'))
            ->defaultSort(fn (Builder $query): Builder => $this->orderByAttention($query))
            ->header(fn (): View => view('vitals::components.table-header', $this->tableHeaderData()))
            ->columns([
                TextColumn::make('name')
                    ->label('Website')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Monitor $record): string => (string) $record->url?->getHost())
                    ->searchable(['name', 'url'])
                    ->sortable(),
                TextColumn::make('health')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Monitor $record): MonitorHealth => $record->health())
                    ->formatStateUsing(fn (Monitor $record): string => $record->statusLabel())
                    ->description(fn (Monitor $record): ?string => $record->pauseDescription()),
                TextColumn::make('uptime')
                    ->label('Uptime')
                    ->state(fn (Monitor $record): ?float => $this->history()->uptimePercentage($record))
                    ->formatStateUsing(fn (float $state): string => $this->formatUptime($state))
                    ->placeholder('—')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->tooltip(fn (Monitor $record): ?string => $this->uptimeTooltip($record))
                    ->visibleFrom('sm'),
                ViewColumn::make('history')
                    ->label('Last ' . config('vitals.history_days') . ' days')
                    ->view('vitals::components.uptime-strip')
                    ->state(fn (Monitor $record): array => $this->history()->days($record))
                    ->visibleFrom('lg'),
                TextColumn::make('response')
                    ->label('Response')
                    ->state(fn (Monitor $record): int | string | null => match (true) {
                        $record->latestCheck === null => null,
                        ! $record->latestCheck->succeeded => 'Failed',
                        default => $record->latestCheck->response_time_ms,
                    })
                    ->formatStateUsing(fn (int | string $state): string => is_int($state) ? number_format($state) . ' ms' : $state)
                    ->color(fn (Monitor $record): ?string => match (true) {
                        $record->latestCheck?->succeeded === false => 'danger',
                        $record->isSlow() => 'warning',
                        default => null,
                    })
                    ->placeholder('—')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->visibleFrom('lg'),
                TextColumn::make('certificate')
                    ->label('SSL')
                    ->state(fn (Monitor $record): CertificateHealth => $record->certificateHealth())
                    ->formatStateUsing(fn (Monitor $record): string => $this->certificateSummary($record))
                    ->badge(fn (CertificateHealth $state): bool => $state->needsAttention())
                    ->color(fn (CertificateHealth $state): string => $state->needsAttention() ? $state->getColor() : 'gray')
                    ->tooltip(fn (Monitor $record): ?string => $record->certificate_expiration_date?->toImmutable()->timezone(Vitals::timezone())->format('\E\x\p\i\r\e\s M j, Y'))
                    ->visibleFrom('sm'),
                TextColumn::make('uptime_last_check_date')
                    ->label('Last checked')
                    ->since()
                    ->dateTimeTooltip(timezone: Vitals::timezone())
                    ->placeholder('Never')
                    ->color(fn (Monitor $record): string => $record->statusLabel() === 'Stale' ? 'warning' : 'gray')
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(self::STATE_FILTERS)
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereKey($this->monitorKeysForState((string) $data['value']))),
                Filter::make('certificate')
                    ->label('SSL needs attention')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereKey($this->monitorKeysWithCertificateIssues())),
                TernaryFilter::make('paused')
                    ->label('Monitoring')
                    ->trueLabel('Paused')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('paused_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('paused_at'),
                    ),
            ])
            ->recordAction('inspect')
            ->recordActions([
                $this->inspectAction(),
                ActionGroup::make([
                    $this->checkNowAction(),
                    $this->editAction(),
                    $this->pauseAction(),
                    $this->resumeAction(),
                    Action::make('openServer')
                        ->label('Open server')
                        ->icon(Heroicon::OutlinedServer)
                        ->authorize('view')
                        ->visible(fn (Monitor $record): bool => filled($record->server_url))
                        ->url(fn (Monitor $record): ?string => $record->server_url, shouldOpenInNewTab: true),
                    DeleteAction::make()
                        ->authorize('delete')
                        ->after(fn () => $this->refreshMonitors()),
                ]),
            ])
            ->emptyStateHeading('No websites monitored yet')
            ->emptyStateDescription('Add a website to start checking its uptime and SSL certificate.')
            ->paginated([25, 50, 'all']);
    }

    /**
     * Quick filter used by the summary strip. Selecting the active state
     * again clears it.
     */
    public function filterMonitorsByState(?string $state = null): void
    {
        $state = array_key_exists((string) $state, self::STATE_FILTERS) ? $state : null;
        $current = $this->tableFilters['state']['value'] ?? null;

        $this->tableFilters['state']['value'] = $state === $current ? null : $state;

        $this->updatedTableFilters();
    }

    public function filterMonitorsByCertificate(): void
    {
        $this->tableFilters['certificate']['isActive'] = ! ($this->tableFilters['certificate']['isActive'] ?? false);

        $this->updatedTableFilters();
    }

    public function runChecksAction(): Action
    {
        return Action::make('runChecks')
            ->label('Run uptime checks')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('runChecks', Monitor::class)
            ->color('gray')
            ->action(function (): void {
                Artisan::call('monitor:check-uptime', ['--force' => true]);

                $this->refreshMonitors();

                Notification::make()->title('Uptime checks finished')->success()->send();
            });
    }

    public function addMonitorAction(): Action
    {
        return Action::make('addMonitor')
            ->label('Add monitor')
            ->icon(Heroicon::Plus)
            ->authorize('create', Monitor::class)
            ->modalWidth(Width::Medium)
            ->schema($this->monitorFormSchema())
            ->action(function (array $data): void {
                $url = rtrim((string) $data['url'], '/');

                $monitor = Monitor::query()->create([
                    'url' => $url,
                    'name' => $data['name'],
                    'server_url' => $data['server_url'] ?? null,
                    'uptime_check_method' => 'head',
                    'certificate_check_enabled' => str_starts_with($url, 'https://'),
                    'uptime_check_interval_in_minutes' => config('uptime-monitor.uptime_check.run_interval_in_minutes'),
                ]);

                $this->runChecksFor($monitor);
            });
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->runChecksAction(),
            $this->addMonitorAction(),
        ];
    }

    /**
     * @return array{
     *     segments: list<array{state: ?string, label: string, count: int, color: string}>,
     *     activeState: ?string,
     *     certificateCount: int,
     *     certificateFilterActive: bool,
     *     issues: list<array{key: int, name: string, message: string, color: string, more: int}>,
     *     issueCount: int,
     * }
     */
    private function tableHeaderData(): array
    {
        $monitors = $this->monitors();
        $attention = $monitors->filter(fn (Monitor $monitor): bool => $monitor->needsAttention());

        $issues = $attention
            ->sortBy(fn (Monitor $monitor): array => [$monitor->issues()[0]['priority'], $monitor->name])
            ->take((int) config('vitals.attention_limit'))
            ->map(function (Monitor $monitor): array {
                $issues = $monitor->issues();

                return [
                    'key' => $monitor->id,
                    'name' => (string) ($monitor->name ?? $monitor->url?->getHost()),
                    'message' => $issues[0]['message'],
                    'color' => $issues[0]['color'],
                    'more' => count($issues) - 1,
                ];
            })
            ->values()
            ->all();

        return [
            'segments' => [
                ['state' => null, 'label' => 'All', 'count' => $monitors->count(), 'color' => 'none'],
                ['state' => 'healthy', 'label' => 'Healthy', 'count' => count($this->monitorKeysForState('healthy')), 'color' => 'success'],
                ['state' => 'issues', 'label' => 'Down or degraded', 'count' => count($this->monitorKeysForState('issues')), 'color' => 'danger'],
                ['state' => 'unknown', 'label' => 'Unknown', 'count' => count($this->monitorKeysForState('unknown')), 'color' => 'gray'],
                ['state' => 'paused', 'label' => 'Paused', 'count' => count($this->monitorKeysForState('paused')), 'color' => 'gray'],
            ],
            'activeState' => $this->tableFilters['state']['value'] ?? null,
            'certificateCount' => count($this->monitorKeysWithCertificateIssues()),
            'certificateFilterActive' => (bool) ($this->tableFilters['certificate']['isActive'] ?? false),
            'issues' => $issues,
            'issueCount' => $attention->count(),
        ];
    }

    /** @return list<int> */
    private function monitorKeysForState(string $state): array
    {
        return $this->monitors()
            ->filter(fn (Monitor $monitor): bool => match ($state) {
                'attention' => $monitor->needsAttention(),
                'issues' => in_array($monitor->health(), [MonitorHealth::Down, MonitorHealth::Degraded], true),
                'unknown' => $monitor->health() === MonitorHealth::Unknown,
                'healthy' => $monitor->health() === MonitorHealth::Healthy,
                'paused' => $monitor->health() === MonitorHealth::Paused,
                default => true,
            })
            ->modelKeys();
    }

    /** @return list<int> */
    private function monitorKeysWithCertificateIssues(): array
    {
        return $this->monitors()
            ->filter(fn (Monitor $monitor): bool => ! $monitor->isPaused() && $monitor->certificateHealth()->needsAttention())
            ->modelKeys();
    }

    /**
     * Orders by the same status rules the page displays, then by name. The
     * rank is computed in PHP and applied as a CASE over the primary key, so
     * it works on both SQLite and MySQL.
     *
     * @param  Builder<Monitor>  $query
     * @return Builder<Monitor>
     */
    private function orderByAttention(Builder $query): Builder
    {
        $ranks = $this->monitors()->mapWithKeys(fn (Monitor $monitor): array => [$monitor->id => $monitor->attentionRank()]);

        if ($ranks->isNotEmpty()) {
            $query->orderByRaw(
                'case id ' . str_repeat('when ? then ? ', $ranks->count()) . 'else 9 end',
                $ranks->flatMap(fn (int $rank, int $id): array => [$id, $rank])->values()->all(),
            );
        }

        return $query->orderBy('name');
    }

    private function inspectAction(): Action
    {
        return Action::make('inspect')
            ->label('Inspect')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->authorize('view')
            ->iconButton()
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (Monitor $record): string => $record->name ?? $record->raw_url)
            ->modalDescription(fn (Monitor $record): string => $record->raw_url)
            ->schema(fn (Monitor $record): array => $this->inspectionSchema($record))
            ->extraModalWindowAttributes(['class' => 'vitals-inspect'])
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }

    /**
     * Current health first, then performance, certificate and incidents.
     *
     * @return list<Component>
     */
    private function inspectionSchema(Monitor $record): array
    {
        $inspection = MonitorInspection::for($record, $this->history(), Vitals::timezone());
        $section = fn (string $view): ViewEntry => ViewEntry::make($view)
            ->hiddenLabel()
            ->view("vitals::components.inspection.{$view}")
            ->state($inspection);

        return [
            $section('health'),
            Section::make('Performance and uptime')
                ->contained(false)
                ->schema([$section('performance')]),
            Section::make('SSL certificate')
                ->contained(false)
                ->schema([$section('certificate')]),
            Section::make('Recent incidents')
                ->contained(false)
                ->schema([$section('incidents')]),
        ];
    }

    private function checkNowAction(): Action
    {
        return Action::make('checkNow')
            ->label('Check now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('runChecks', Monitor::class)
            ->visible(fn (Monitor $record): bool => ! $record->isPaused())
            ->action(fn (Monitor $record) => $this->runChecksFor($record));
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize('update')
            ->modalWidth(Width::Medium)
            ->fillForm(fn (Monitor $record): array => [
                'name' => $record->name,
                'url' => $record->raw_url,
                'server_url' => $record->server_url,
                'certificate_check_enabled' => $record->certificate_check_enabled,
            ])
            ->schema(fn (Monitor $record): array => [
                ...$this->monitorFormSchema($record),
                Toggle::make('certificate_check_enabled')->label('Check SSL certificate'),
            ])
            ->action(function (Monitor $record, array $data): void {
                $url = rtrim((string) $data['url'], '/');
                $urlChanged = $url !== $record->raw_url;

                $record->update([
                    'name' => $data['name'],
                    'url' => $url,
                    'server_url' => $data['server_url'] ?? null,
                    'certificate_check_enabled' => (bool) $data['certificate_check_enabled'],
                ]);

                if ($urlChanged) {
                    $this->runChecksFor($record);

                    return;
                }

                $this->refreshMonitors();
            });
    }

    private function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Pause monitoring')
            ->icon(Heroicon::OutlinedPause)
            ->authorize('pause')
            ->visible(fn (Monitor $record): bool => ! $record->isPaused())
            ->modalHeading(fn (Monitor $record): string => 'Pause monitoring for ' . $record->name)
            ->modalDescription('No uptime or SSL checks run and no notifications are sent while paused. Any open incident is closed.')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Pause')
            ->schema([
                TextInput::make('reason')
                    ->label('Reason')
                    ->placeholder('Planned maintenance')
                    ->maxLength(255),
                DateTimePicker::make('until')
                    ->label('Resume automatically at')
                    ->helperText('Leave empty to pause until resumed by hand.')
                    ->seconds(false)
                    ->timezone(Vitals::timezone())
                    ->after('now'),
            ])
            ->action(function (Monitor $record, array $data): void {
                $record->pause($data['reason'] ?? null, filled($data['until'] ?? null) ? Date::parse($data['until']) : null);

                $this->refreshMonitors();

                Notification::make()->title('Monitoring paused')->success()->send();
            });
    }

    private function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Resume monitoring')
            ->icon(Heroicon::OutlinedPlay)
            ->authorize('pause')
            ->visible(fn (Monitor $record): bool => $record->isPaused())
            ->action(function (Monitor $record): void {
                $record->resume();

                $this->runChecksFor($record);

                Notification::make()->title('Monitoring resumed')->success()->send();
            });
    }

    /** @return list<TextInput> */
    private function monitorFormSchema(?Monitor $record = null): array
    {
        return [
            TextInput::make('name')
                ->label('Site name')
                ->required()
                ->maxLength(255),
            TextInput::make('url')
                ->label('Site URL')
                ->required()
                ->url()
                ->rule(new PublicHost, condition: fn (): bool => ! config('vitals.allow_private_addresses'))
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $alreadyMonitored = Monitor::query()
                        ->where('url', rtrim((string) $value, '/'))
                        ->when($record, fn (Builder $query, Monitor $record): Builder => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($alreadyMonitored) {
                        $fail('This site is already being monitored.');
                    }
                }),
            TextInput::make('server_url')
                ->label('Server URL')
                ->url(),
        ];
    }

    private function runChecksFor(Monitor $monitor): void
    {
        Artisan::call('monitor:check-uptime', ['--url' => $monitor->raw_url, '--force' => true]);

        if ($monitor->certificate_check_enabled) {
            Artisan::call('monitor:check-certificate', ['--url' => $monitor->raw_url]);
        }

        $this->refreshMonitors();
    }

    private function refreshMonitors(): void
    {
        $this->monitors = null;
        $this->history = null;

        $this->dispatch(VitalsAlertsWidget::REFRESH_EVENT);
    }

    private function certificateSummary(Monitor $record): string
    {
        $health = $record->certificateHealth();
        $daysRemaining = $record->certificateDaysRemaining();

        return match ($health) {
            CertificateHealth::Valid => $this->daysLabel((int) $daysRemaining),
            CertificateHealth::ExpiringSoon => 'Expires in ' . $this->daysLabel((int) $daysRemaining),
            CertificateHealth::Invalid => $daysRemaining !== null && $daysRemaining < 0 ? 'Expired' : 'Invalid',
            CertificateHealth::Disabled => '—',
            default => $health->getLabel(),
        };
    }

    private function daysLabel(int $days): string
    {
        return match (true) {
            $days < 0 => abs($days) . ' ' . str('day')->plural(abs($days)) . ' ago',
            default => $days . ' ' . str('day')->plural($days),
        };
    }

    private function uptimeTooltip(Monitor $record): ?string
    {
        $checks = $this->history()->checkCount($record);
        $firstDay = $this->history()->firstRecordedDay($record);

        if ($checks === 0 || ! $firstDay instanceof CarbonImmutable) {
            return null;
        }

        $since = $firstDay->lte(Date::today()->subDays((int) config('vitals.history_days') - 1))
            ? 'over the last ' . config('vitals.history_days') . ' days'
            : 'since ' . $firstDay->format('M j');

        return number_format($checks) . ' checks ' . $since;
    }

    private function formatUptime(float $uptime): string
    {
        return $uptime === 100.0 ? '100%' : number_format($uptime, 2) . '%';
    }

    private function history(): UptimeHistory
    {
        return $this->history ??= UptimeHistory::make();
    }

    /** @return Collection<int, Monitor> */
    private function monitors(): Collection
    {
        return $this->monitors ??= Monitor::query()->with('latestCheck')->orderBy('name')->get();
    }
}
