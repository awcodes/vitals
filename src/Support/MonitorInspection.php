<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Support;

use Awcodes\Vitals\Enums\CertificateHealth;
use Awcodes\Vitals\Enums\MonitorHealth;
use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Models\MonitorIncident;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

/**
 * Everything the inspection slide-over shows for one monitor, worked out
 * once from the monitor, its recent checks and incidents, and the shared
 * UptimeHistory. Queries are bounded: the latest 24 checks, the last
 * successful check, and the 10 most recent incidents.
 */
final readonly class MonitorInspection
{
    public const int RECENT_CHECKS = 24;

    /**
     * @param  list<array{label: string, color: string}>  $conditions
     * @param  array{since: string, duration: string, reason: FailureReason}|null  $activeIncident
     * @param  list<array{date: CarbonImmutable, avg_ms: ?int, state: string, label: string}>  $days
     * @param  list<array{at: string, succeeded: bool, slow: bool, label: string}>  $recentChecks
     * @param  array{health: CertificateHealth, badge: string, headline: string, detail: ?string, expires: ?string, issuer: ?string, raw: ?string}  $certificate
     * @param  list<array{ongoing: bool, started: string, ended: ?string, duration: string, reason: FailureReason}>  $incidents
     */
    public function __construct(
        public MonitorHealth $health,
        public string $statusLabel,
        public array $conditions,
        public string $headline,
        public ?string $pauseReason,
        public string $checkTimes,
        public ?array $activeIncident,
        public ?FailureReason $latestFailure,
        public ?int $latestFailureStatus,
        public ?float $uptime,
        public ?int $latestMs,
        public bool $latestFailed,
        public ?int $lastSuccessMs,
        public ?string $lastSuccessAgo,
        public ?int $averageMs,
        public int $slowThresholdMs,
        public array $days,
        public array $recentChecks,
        public array $certificate,
        public array $incidents,
    ) {}

    public static function for(Monitor $monitor, UptimeHistory $history, string $timezone): self
    {
        $health = $monitor->health();
        $latest = $monitor->latestCheck;
        $lastSuccess = $monitor->checks()->where('succeeded', true)->latest('checked_at')->first();
        $recent = $monitor->checks()->latest('checked_at')->limit(self::RECENT_CHECKS)->get()->reverse()->values();
        $incidents = MonitorIncident::query()
            ->whereBelongsTo($monitor)
            ->orderByRaw('resolved_at is null desc')
            ->latest('started_at')
            ->limit(10)
            ->get();
        $openIncident = $incidents->first(fn (MonitorIncident $incident): bool => $incident->resolved_at === null);
        $latestFailed = $latest?->succeeded === false;
        $format = fn (CarbonInterface $date): string => $date->toImmutable()->timezone($timezone)->format('M j, g:i A');

        $responseTrend = $history->responseTrend($monitor);
        $days = array_map(fn (array $day, array $trend): array => [...$day, 'avg_ms' => $trend['avg_ms']], $history->days($monitor), $responseTrend);

        return new self(
            health: $health,
            statusLabel: $monitor->statusLabel(),
            conditions: self::conditions($monitor),
            headline: self::headline($monitor),
            pauseReason: $monitor->isPaused() ? $monitor->pauseDescription() : null,
            checkTimes: self::checkTimes($monitor, $lastSuccess),
            activeIncident: $openIncident instanceof MonitorIncident && ! $monitor->isPaused() ? [
                'since' => $format($openIncident->started_at),
                'duration' => self::duration($openIncident),
                'reason' => FailureReason::from($openIncident->failure_reason),
            ] : null,
            latestFailure: $latestFailed ? FailureReason::from($latest->failure_reason ?? $monitor->uptime_check_failure_reason) : null,
            latestFailureStatus: $latestFailed ? $latest->http_status : null,
            uptime: $history->uptimePercentage($monitor),
            latestMs: $latestFailed ? null : $latest?->response_time_ms,
            latestFailed: $latestFailed,
            lastSuccessMs: $lastSuccess?->response_time_ms,
            lastSuccessAgo: $lastSuccess?->checked_at->diffForHumans(),
            averageMs: $history->averageResponseMs($monitor),
            slowThresholdMs: (int) config('vitals.slow_response_ms'),
            days: $days,
            recentChecks: $recent->map(fn (MonitorCheck $check): array => [
                'at' => $format($check->checked_at),
                'succeeded' => $check->succeeded,
                'slow' => $check->succeeded && $check->response_time_ms > (int) config('vitals.slow_response_ms'),
                'label' => $format($check->checked_at) . ': ' . ($check->succeeded
                    ? 'passed, ' . number_format((int) $check->response_time_ms) . ' ms' . ($check->response_time_ms > (int) config('vitals.slow_response_ms') ? ' (slow)' : '')
                    : 'failed, ' . FailureReason::from($check->failure_reason)->title),
            ])->all(),
            certificate: self::certificate($monitor, $timezone),
            incidents: $incidents->map(fn (MonitorIncident $incident): array => [
                'ongoing' => $incident->resolved_at === null,
                'started' => $format($incident->started_at),
                'ended' => $incident->resolved_at instanceof CarbonInterface ? $format($incident->resolved_at) : null,
                'duration' => self::duration($incident),
                'reason' => FailureReason::from($incident->failure_reason),
            ])->all(),
        );
    }

    /**
     * Availability first, then any certificate problem as its own condition.
     *
     * @return list<array{label: string, color: string}>
     */
    private static function conditions(Monitor $monitor): array
    {
        $conditions = [['label' => $monitor->statusLabel(), 'color' => $monitor->health()->getColor()]];

        if ($monitor->isPaused()) {
            return $conditions;
        }

        $certificate = $monitor->certificateHealth();
        $daysRemaining = $monitor->certificateDaysRemaining();

        $label = match ($certificate) {
            CertificateHealth::Invalid => $daysRemaining !== null && $daysRemaining < 0 ? 'SSL expired' : 'SSL invalid',
            CertificateHealth::InspectionFailed => 'SSL check failed',
            CertificateHealth::ExpiringSoon => 'SSL expiring',
            default => null,
        };

        if ($label !== null) {
            $conditions[] = ['label' => $label, 'color' => $certificate->getColor()];
        }

        return $conditions;
    }

    private static function headline(Monitor $monitor): string
    {
        return match ($monitor->health()) {
            MonitorHealth::Paused => $monitor->paused_at instanceof CarbonInterface
                ? 'Monitoring paused ' . $monitor->paused_at->diffForHumans()
                : 'Monitoring is turned off',
            MonitorHealth::Unknown => $monitor->isNeverChecked()
                ? 'Waiting for the first check'
                : 'No check for ' . $monitor->uptime_last_check_date?->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]) . ', so this status may be out of date',
            MonitorHealth::Down => $monitor->uptime_check_times_failed_in_a_row . ' consecutive failed checks',
            MonitorHealth::Degraded => $monitor->uptime_status === UptimeStatus::DOWN
                ? 'Latest check failed, not yet confirmed as an outage'
                : 'Responding slowly: ' . number_format((int) $monitor->latestCheck?->response_time_ms) . ' ms, slow above ' . number_format((int) config('vitals.slow_response_ms')) . ' ms',
            MonitorHealth::Healthy => 'Responding normally',
        };
    }

    private static function checkTimes(Monitor $monitor, ?MonitorCheck $lastSuccess): string
    {
        if (! $monitor->uptime_last_check_date instanceof CarbonInterface) {
            return 'Never checked';
        }

        $lastSuccessAt = $lastSuccess->checked_at
            ?? ($monitor->uptime_status === UptimeStatus::UP ? $monitor->uptime_last_check_date : null);

        return 'Last checked ' . $monitor->uptime_last_check_date->diffForHumans() . ' · ' . ($lastSuccessAt instanceof CarbonInterface
            ? 'last successful check ' . $lastSuccessAt->diffForHumans()
            : 'no successful check recorded');
    }

    /**
     * Separates an expired certificate, a hostname mismatch, other
     * validation failures and an inspection that could not run.
     *
     * @return array{health: CertificateHealth, badge: string, headline: string, detail: ?string, expires: ?string, issuer: ?string, raw: ?string}
     */
    private static function certificate(Monitor $monitor, string $timezone): array
    {
        $health = $monitor->certificateHealth();
        $days = $monitor->certificateDaysRemaining();
        $reason = $monitor->certificate_check_failure_reason;
        $isHostnameMismatch = str_contains((string) $reason, 'does not apply to');

        [$headline, $detail] = match ($health) {
            CertificateHealth::Disabled => [
                'Not applicable',
                str_starts_with($monitor->raw_url, 'http://') ? 'This site is monitored over plain HTTP.' : 'Certificate checks are turned off for this site.',
            ],
            CertificateHealth::Unknown => ['Not checked yet', null],
            CertificateHealth::InspectionFailed => ['Certificate inspection failed', 'The certificate could not be retrieved, so its expiry is unknown.'],
            CertificateHealth::Invalid => match (true) {
                $days !== null && $days < 0 => ['Expired ' . abs($days) . ' ' . str('day')->plural(abs($days)) . ' ago', null],
                $isHostnameMismatch => ['Hostname mismatch', 'The certificate does not cover this domain.'],
                default => ['Certificate validation failed', null],
            },
            CertificateHealth::ExpiringSoon => ['Expires in ' . $days . ' ' . str('day')->plural((int) $days), null],
            CertificateHealth::Valid => ['Valid · ' . $days . ' ' . str('day')->plural((int) $days) . ' remaining', null],
        };

        return [
            'health' => $health,
            'badge' => match (true) {
                $health === CertificateHealth::Invalid && $days !== null && $days < 0 => 'Expired',
                $health === CertificateHealth::Invalid && $isHostnameMismatch => 'Mismatch',
                default => $health->getLabel(),
            },
            'headline' => $headline,
            'detail' => $detail,
            'expires' => $monitor->certificate_expiration_date?->toImmutable()->timezone($timezone)->format('M j, Y'),
            'issuer' => filled($monitor->certificate_issuer) ? $monitor->certificate_issuer : null,
            'raw' => filled($reason) && $health !== CertificateHealth::Valid ? $reason : null,
        ];
    }

    private static function duration(MonitorIncident $incident): string
    {
        $minutes = max(1, $incident->durationInMinutes());

        return CarbonInterval::minutes($minutes)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
