<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Filament\Widgets;

use Awcodes\Vitals\Models\Monitor;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Spatie\UptimeMonitor\Models\Enums\CertificateStatus;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

final class VitalsAlertsWidget extends Widget
{
    /**
     * Dispatched by the Vitals page whenever monitors change, so the widget
     * stays current without a reload.
     */
    public const string REFRESH_EVENT = 'vitals-monitors-updated';

    /** @var array<int, array{name: string, url: string, uptimeFailing: bool, certificateFailing: bool, reason: string|null}> */
    public array $failingSites = [];

    protected static bool $isLazy = false;

    protected string $view = 'vitals::widgets.alerts';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
    }

    public function mount(): void
    {
        $this->refreshFailingSites();
    }

    #[On(self::REFRESH_EVENT)]
    public function refreshFailingSites(): void
    {
        $this->failingSites = Monitor::query()
            ->whereNull('paused_at')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('uptime_check_enabled', true)
                    ->where('uptime_status', UptimeStatus::DOWN))
                ->orWhere(fn ($query) => $query
                    ->where('certificate_check_enabled', true)
                    ->where('certificate_status', CertificateStatus::INVALID)))
            ->orderBy('name')
            ->get()
            ->map(fn (Monitor $site): array => [
                // Same fallback as the page's attention list, for monitors added
                // outside Vitals without a name.
                'name' => filled($site->name) ? $site->name : (string) $site->url?->getHost(),
                'url' => $site->raw_url,
                'uptimeFailing' => $site->uptime_status === UptimeStatus::DOWN,
                'certificateFailing' => $site->certificate_status === CertificateStatus::INVALID,
                'reason' => $this->failureReason($site),
            ])
            ->all();
    }

    private function failureReason(Monitor $site): ?string
    {
        $reasons = Collection::make([
            $site->uptime_status === UptimeStatus::DOWN ? $site->uptime_check_failure_reason : null,
            $site->certificate_status === CertificateStatus::INVALID ? $site->certificate_check_failure_reason : null,
        ])->filter()->unique();

        return $reasons->isEmpty() ? null : $reasons->implode(' • ');
    }
}
