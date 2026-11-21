<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Models;

use Awcodes\Vitals\Actions\RecordUptimeCheckAction;
use Awcodes\Vitals\Database\Factories\MonitorFactory;
use Awcodes\Vitals\Enums\CertificateHealth;
use Awcodes\Vitals\Enums\MonitorHealth;
use Awcodes\Vitals\Vitals;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Date;
use Override;
use Psr\Http\Message\ResponseInterface;
use Spatie\UptimeMonitor\Models\Enums\CertificateStatus;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;
use Spatie\UptimeMonitor\Models\Monitor as BaseMonitor;
use Spatie\Url\Url;

/**
 * @property int $id
 * @property-read Url|null $url
 * @property-read string $raw_url
 * @property bool $uptime_check_enabled
 * @property string $look_for_string
 * @property string $uptime_check_interval_in_minutes
 * @property string $uptime_status
 * @property string $uptime_check_failure_reason
 * @property int $uptime_check_times_failed_in_a_row
 * @property ?CarbonInterface $uptime_status_last_change_date
 * @property ?CarbonInterface $uptime_last_check_date
 * @property ?CarbonInterface $uptime_check_failed_event_fired_on_date
 * @property string $uptime_check_method
 * @property string $uptime_check_payload
 * @property string $uptime_check_additional_headers
 * @property string $uptime_check_response_checker
 * @property bool $certificate_check_enabled
 * @property string $certificate_status
 * @property ?CarbonInterface $certificate_expiration_date
 * @property ?string $certificate_issuer
 * @property string $certificate_check_failure_reason
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property ?string $server_url
 * @property ?string $name
 * @property ?CarbonImmutable $paused_at
 * @property ?CarbonImmutable $paused_until
 * @property ?string $pause_reason
 * @property-read ?MonitorCheck $latestCheck
 */
class Monitor extends BaseMonitor
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    // Keys are named explicitly: Eloquent would otherwise derive them from the
    // class name, which breaks for an app subclass such as AppMonitor.

    /** @return HasMany<MonitorCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class, 'monitor_id');
    }

    /** @return HasOne<MonitorCheck, $this> */
    public function latestCheck(): HasOne
    {
        return $this->hasOne(MonitorCheck::class, 'monitor_id')->latestOfMany('checked_at');
    }

    /** @return HasMany<MonitorIncident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class, 'monitor_id');
    }

    public function isPaused(): bool
    {
        return $this->paused_at instanceof CarbonInterface;
    }

    /**
     * Stops every uptime and certificate check, and so every notification,
     * until resumed. The monitor's own check settings are left untouched so
     * resuming restores them as they were. An open incident is closed at the
     * moment of pausing, because nothing is observed after that.
     */
    public function pause(?string $reason = null, ?CarbonInterface $until = null): void
    {
        $now = Date::now();

        $this->update([
            'paused_at' => $now,
            'paused_until' => $until,
            'pause_reason' => blank($reason) ? null : $reason,
        ]);

        $this->incidents()->open()->update(['resolved_at' => $now]);
    }

    /**
     * Clears the pause and Spatie's failure streak, so a site that is still
     * down needs the usual consecutive failures before anyone is notified,
     * and a site that came back during the pause doesn't send a recovery.
     */
    public function resume(): void
    {
        $this->update([
            'paused_at' => null,
            'paused_until' => null,
            'pause_reason' => null,
            'uptime_status' => UptimeStatus::NOT_YET_CHECKED,
            'uptime_check_failure_reason' => '',
            'uptime_check_times_failed_in_a_row' => 0,
            'uptime_check_failed_event_fired_on_date' => null,
        ]);
    }

    #[Override]
    public function shouldCheckUptime(): bool
    {
        return ! $this->isPaused() && parent::shouldCheckUptime();
    }

    /**
     * Guards the paths that skip shouldCheckUptime(), such as
     * monitor:check-uptime --force, which also includes monitors that only
     * have certificate checks on: the result for a paused monitor, or one
     * with uptime checks turned off, is discarded before Spatie can record
     * it or fire events.
     */
    #[Override]
    public function uptimeRequestSucceeded(ResponseInterface $response): void
    {
        if ($this->isPaused() || ! $this->uptime_check_enabled) {
            return;
        }

        parent::uptimeRequestSucceeded($response);

        resolve(RecordUptimeCheckAction::class)->handle($this);
    }

    #[Override]
    public function uptimeRequestFailed(string $reason): void
    {
        if ($this->isPaused() || ! $this->uptime_check_enabled) {
            return;
        }

        parent::uptimeRequestFailed($reason);

        resolve(RecordUptimeCheckAction::class)->handle($this);
    }

    #[Override]
    public function checkCertificate(): void
    {
        if ($this->isPaused()) {
            return;
        }

        parent::checkCertificate();
    }

    /**
     * Failing for at least as many checks in a row as Spatie needs before it
     * notifies. A single failed check is not an outage.
     */
    public function hasConfirmedOutage(): bool
    {
        return $this->uptime_status === UptimeStatus::DOWN
            && $this->uptime_check_times_failed_in_a_row >= (int) config('uptime-monitor.uptime_check.fire_monitor_failed_event_after_consecutive_failures');
    }

    /**
     * Stale or missing results read as Unknown, never Healthy. Response time
     * only counts when latestCheck is eager loaded.
     */
    public function health(): MonitorHealth
    {
        if ($this->isPaused() || ! $this->uptime_check_enabled) {
            return MonitorHealth::Paused;
        }

        if ($this->uptime_status === UptimeStatus::NOT_YET_CHECKED || ! $this->uptime_last_check_date instanceof CarbonInterface) {
            return MonitorHealth::Unknown;
        }

        if ($this->uptime_last_check_date->lt(Date::now()->subMinutes((int) config('vitals.stale_after_minutes')))) {
            return MonitorHealth::Unknown;
        }

        if ($this->uptime_status === UptimeStatus::DOWN) {
            return $this->hasConfirmedOutage() ? MonitorHealth::Down : MonitorHealth::Degraded;
        }

        if ($this->isSlow()) {
            return MonitorHealth::Degraded;
        }

        return MonitorHealth::Healthy;
    }

    public function isSlow(): bool
    {
        $responseTimeMs = $this->relationLoaded('latestCheck') ? $this->latestCheck?->response_time_ms : null;

        return $responseTimeMs !== null && $responseTimeMs > (int) config('vitals.slow_response_ms');
    }

    /**
     * Certificate health, independent of uptime. Spatie marks a certificate
     * invalid when it can't fetch one at all; that reads as a failed check,
     * not an expired certificate.
     */
    public function certificateHealth(): CertificateHealth
    {
        if (! $this->certificate_check_enabled) {
            return CertificateHealth::Disabled;
        }

        if ($this->certificate_status === CertificateStatus::NOT_YET_CHECKED) {
            return CertificateHealth::Unknown;
        }

        if ($this->certificate_status === CertificateStatus::INVALID) {
            return $this->certificate_expiration_date instanceof CarbonInterface
                ? CertificateHealth::Invalid
                : CertificateHealth::InspectionFailed;
        }

        $daysRemaining = $this->certificateDaysRemaining();

        if ($daysRemaining === null) {
            return CertificateHealth::Unknown;
        }

        if ($daysRemaining < 0) {
            return CertificateHealth::Invalid;
        }

        if ($daysRemaining <= (int) config('uptime-monitor.certificate_check.fire_expiring_soon_event_if_certificate_expires_within_days')) {
            return CertificateHealth::ExpiringSoon;
        }

        return CertificateHealth::Valid;
    }

    public function certificateDaysRemaining(): ?int
    {
        if (! $this->certificate_expiration_date instanceof CarbonInterface) {
            return null;
        }

        return (int) Date::today()->diffInDays($this->certificate_expiration_date->toImmutable()->startOfDay(), absolute: false);
    }

    public function isNeverChecked(): bool
    {
        return $this->uptime_status === UptimeStatus::NOT_YET_CHECKED
            || ! $this->uptime_last_check_date instanceof CarbonInterface;
    }

    /**
     * Splits Unknown into "Never checked" and "Stale" for display, without
     * adding a status value.
     */
    public function statusLabel(): string
    {
        $health = $this->health();

        if ($health !== MonitorHealth::Unknown) {
            return $health->getLabel();
        }

        return $this->isNeverChecked() ? 'Never checked' : 'Stale';
    }

    /**
     * Actionable problems, most urgent first, as short messages for the
     * overview. Raw failure reasons stay in the inspection view. A monitor
     * waiting for its first check is not an issue, and a paused one never is.
     *
     * Priority: 1 outage, 2 degraded, 3 invalid certificate, 4 certificate
     * inspection failed, 5 certificate expiring, 6 stale results.
     *
     * @return list<array{priority: int, message: string, color: string}>
     */
    public function issues(): array
    {
        if ($this->isPaused()) {
            return [];
        }

        $issues = array_values(array_filter([
            $this->uptimeIssue(),
            $this->certificateIssue(),
        ]));

        usort($issues, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $issues;
    }

    public function needsAttention(): bool
    {
        return $this->issues() !== [];
    }

    /**
     * Table ordering, lowest first: down, degraded, other issues by severity
     * (certificate problems), never checked or stale, healthy, paused.
     */
    public function attentionRank(): int
    {
        $health = $this->health();

        return match (true) {
            $health === MonitorHealth::Paused => 50,
            $health === MonitorHealth::Down => 0,
            $health === MonitorHealth::Degraded => 1,
            $this->certificateIssue() !== null => 10 + $this->certificateIssue()['priority'],
            $health === MonitorHealth::Unknown => 30,
            default => 40,
        };
    }

    public function pauseDescription(): ?string
    {
        if (! $this->isPaused()) {
            return null;
        }

        return collect([
            $this->pause_reason,
            $this->paused_until instanceof CarbonInterface ? 'until ' . $this->paused_until->timezone(Vitals::timezone())->format('M j, g:i A') : null,
        ])->filter()->implode(' · ') ?: null;
    }

    protected static function newFactory(): MonitorFactory
    {
        return MonitorFactory::new();
    }

    /**
     * Merged with Spatie's $casts property.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'paused_at' => 'immutable_datetime',
            'paused_until' => 'immutable_datetime',
        ];
    }

    /** @return array{priority: int, message: string, color: string}|null */
    protected function uptimeIssue(): ?array
    {
        return match ($this->health()) {
            MonitorHealth::Down => ['priority' => 1, 'message' => "Down · {$this->uptime_check_times_failed_in_a_row} failed checks in a row", 'color' => 'danger'],
            MonitorHealth::Degraded => $this->uptime_status === UptimeStatus::DOWN
                ? ['priority' => 2, 'message' => 'Latest check failed', 'color' => 'warning']
                : ['priority' => 2, 'message' => 'Slow response · ' . number_format((int) $this->latestCheck?->response_time_ms) . ' ms', 'color' => 'warning'],
            MonitorHealth::Unknown => $this->isNeverChecked()
                ? null
                : ['priority' => 6, 'message' => 'No check for ' . $this->uptime_last_check_date?->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]), 'color' => 'gray'],
            default => null,
        };
    }

    /** @return array{priority: int, message: string, color: string}|null */
    protected function certificateIssue(): ?array
    {
        $daysRemaining = $this->certificateDaysRemaining();

        return match ($this->certificateHealth()) {
            CertificateHealth::Invalid => ['priority' => 3, 'message' => $daysRemaining !== null && $daysRemaining < 0 ? 'SSL certificate expired' : 'SSL certificate invalid', 'color' => 'danger'],
            CertificateHealth::InspectionFailed => ['priority' => 4, 'message' => 'SSL certificate inspection failed', 'color' => 'warning'],
            CertificateHealth::ExpiringSoon => ['priority' => 5, 'message' => 'SSL certificate expires in ' . $daysRemaining . ' ' . str('day')->plural((int) $daysRemaining), 'color' => 'warning'],
            default => null,
        };
    }
}
