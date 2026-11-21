<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\UptimeMonitor\Models\Enums\CertificateStatus;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

/**
 * Local-only demo monitors covering every monitoring state, with 30 days of
 * check history, for working on the Vitals page.
 *
 * All monitors use reserved .test domains. Re-running the seeder replaces
 * them (and their checks and incidents) with a fresh set aligned to the
 * current time; any other monitors are left alone. Inserts go straight
 * through the query builder, so no Spatie events or notifications fire.
 *
 * History is generated first (a check every 15 minutes for the last 6
 * hours, every 3 hours before that) and each monitor's uptime status,
 * failure streak and incidents are derived from it, so the table, history
 * strip and uptime percentage always agree. Offsets are fixed, with no
 * randomness, so every run has the same shape.
 *
 * @phpstan-type FailureWindow array{from: int, to: int, reason: string, incident?: bool}
 * @phpstan-type SlowWindow array{from: int, to: int, ms: int}
 * @phpstan-type DemoMonitor array{
 *     name: string,
 *     url: string,
 *     latestMinutesAgo?: int|null,
 *     historyDays?: int,
 *     recordsHistory?: bool,
 *     baseMs?: int,
 *     failures?: list<FailureWindow>,
 *     slow?: list<SlowWindow>,
 *     certificate?: array<string, mixed>,
 *     monitor?: array<string, mixed>,
 * }
 */
final class VitalsDemoSeeder extends Seeder
{
    private const int DAY = 1440;

    private CarbonImmutable $now;

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->warn('VitalsDemoSeeder only runs in the local environment; skipped.');

            return;
        }

        $this->now = Date::now()->toImmutable()->startOfMinute();

        DB::transaction(function (): void {
            $this->removeDemoMonitors();

            foreach ($this->scenarios() as $scenario) {
                $this->seedMonitor($scenario);
            }
        });
    }

    private function removeDemoMonitors(): void
    {
        $ids = DB::table('monitors')->where('url', 'like', '%.test')->pluck('id');

        DB::table('monitor_checks')->whereIn('monitor_id', $ids)->delete();
        DB::table('monitor_incidents')->whereIn('monitor_id', $ids)->delete();
        DB::table('monitors')->whereIn('id', $ids)->delete();
    }

    /** @param  DemoMonitor  $scenario */
    private function seedMonitor(array $scenario): void
    {
        $history = $this->history($scenario);
        $id = DB::table('monitors')->insertGetId($this->monitorRow($scenario, $history));

        if ($scenario['recordsHistory'] ?? true) {
            foreach (array_chunk($history, 500) as $chunk) {
                DB::table('monitor_checks')->insert(array_map(fn (array $check): array => ['monitor_id' => $id, ...$check], $chunk));
            }
        }

        $incidents = array_map(fn (array $incident): array => ['monitor_id' => $id, ...$incident], $this->incidents($scenario, $history));

        if ($incidents !== []) {
            DB::table('monitor_incidents')->insert($incidents);
        }
    }

    /** @return list<DemoMonitor> */
    private function scenarios(): array
    {
        $timeout = 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received';

        return [
            [
                'name' => 'Steady Storefront',
                'url' => 'https://steady-storefront.test',
                'baseMs' => 210,
                'certificate' => ['days' => 120],
            ],
            [
                'name' => 'Calm Docs',
                'url' => 'https://calm-docs.test',
                'baseMs' => 150,
                'certificate' => ['days' => 64],
            ],
            [
                'name' => 'Outage API',
                'url' => 'https://outage-api.test',
                'baseMs' => 320,
                'failures' => [
                    ['from' => 9 * self::DAY + 180, 'to' => 9 * self::DAY - 60, 'reason' => 'Server error: `HEAD https://outage-api.test` resulted in a `502 Bad Gateway` response', 'incident' => true],
                    ['from' => 65, 'to' => 0, 'reason' => $timeout, 'incident' => true],
                ],
                'certificate' => ['days' => 80],
            ],
            [
                'name' => 'Recovered Bookings',
                'url' => 'https://recovered-bookings.test',
                'baseMs' => 280,
                'failures' => [
                    ['from' => 31 * 60, 'to' => 24 * 60, 'reason' => 'Server error: `HEAD https://recovered-bookings.test` resulted in a `503 Service Unavailable` response', 'incident' => true],
                ],
                'certificate' => ['days' => 45],
            ],
            [
                'name' => 'Wobbly Checkout',
                'url' => 'https://wobbly-checkout.test',
                'baseMs' => 260,
                'failures' => [
                    ['from' => 12 * self::DAY + 100, 'to' => 12 * self::DAY - 100, 'reason' => 'Connection reset by peer'],
                    ['from' => 14, 'to' => 0, 'reason' => 'Server error: `HEAD https://wobbly-checkout.test` resulted in a `500 Internal Server Error` response'],
                ],
                'certificate' => ['days' => 200],
            ],
            [
                'name' => 'Sluggish Reports',
                'url' => 'https://sluggish-reports.test',
                'baseMs' => 380,
                'slow' => [
                    ['from' => 5 * self::DAY, 'to' => 0, 'ms' => 4200],
                ],
                'certificate' => ['days' => 90],
            ],
            [
                'name' => 'Flaky Status Page',
                'url' => 'https://flaky-status.test',
                'baseMs' => 240,
                'failures' => [
                    ['from' => 2 * self::DAY + 200, 'to' => 2 * self::DAY - 20, 'reason' => 'Server error: `HEAD https://flaky-status.test` resulted in a `504 Gateway Timeout` response', 'incident' => true],
                    ['from' => 4 * self::DAY + 100, 'to' => 4 * self::DAY - 100, 'reason' => 'Connection refused'],
                    ['from' => 6 * self::DAY + 200, 'to' => 6 * self::DAY - 20, 'reason' => $timeout, 'incident' => true],
                    ['from' => 8 * self::DAY + 100, 'to' => 8 * self::DAY - 100, 'reason' => 'Connection refused'],
                    ['from' => 11 * self::DAY + 200, 'to' => 11 * self::DAY - 20, 'reason' => 'Server error: `HEAD https://flaky-status.test` resulted in a `502 Bad Gateway` response', 'incident' => true],
                    ['from' => 15 * self::DAY + 100, 'to' => 15 * self::DAY - 100, 'reason' => 'Connection refused'],
                ],
                'certificate' => ['days' => 70],
            ],
            [
                'name' => 'Expiring Portal',
                'url' => 'https://expiring-portal.test',
                'baseMs' => 230,
                'certificate' => ['days' => 6],
            ],
            [
                'name' => 'Expired Travel',
                'url' => 'https://expired-travel.test',
                'baseMs' => 300,
                'certificate' => ['days' => -3, 'status' => CertificateStatus::INVALID, 'reason' => 'The certificate has expired'],
            ],
            [
                'name' => 'Mismatched CDN',
                'url' => 'https://mismatched-cdn.test',
                'baseMs' => 190,
                'certificate' => ['days' => 150, 'status' => CertificateStatus::INVALID, 'reason' => 'Certificate does not apply to https://mismatched-cdn.test but only to these domains: *.edge-cache.test'],
            ],
            [
                'name' => 'Unreadable Certificate Blog',
                'url' => 'https://unreadable-cert.test',
                'baseMs' => 270,
                'certificate' => ['days' => null, 'status' => CertificateStatus::INVALID, 'issuer' => '', 'reason' => 'Could not download certificate for host `unreadable-cert.test` because stream_socket_client(): Unable to connect to ssl://unreadable-cert.test:443 (Connection timed out)'],
            ],
            [
                'name' => 'Brand New Landing',
                'url' => 'https://brand-new-landing.test',
                'latestMinutesAgo' => null,
                'certificate' => ['days' => null, 'status' => CertificateStatus::NOT_YET_CHECKED, 'issuer' => null],
            ],
            [
                'name' => 'Forgotten Wiki',
                'url' => 'https://forgotten-wiki.test',
                'latestMinutesAgo' => 380,
                'baseMs' => 340,
                'certificate' => ['days' => 33],
            ],
            [
                'name' => 'Paused Intranet',
                'url' => 'https://paused-intranet.test',
                'latestMinutesAgo' => 4 * self::DAY + 30,
                'baseMs' => 200,
                'failures' => [
                    ['from' => 4 * self::DAY + 90, 'to' => 4 * self::DAY + 30, 'reason' => 'Connection refused'],
                ],
                'certificate' => ['days' => 58],
                'monitor' => ['paused_at' => 4 * self::DAY, 'pause_reason' => 'Migrating to a new host'],
            ],
            [
                'name' => 'Fresh Microsite',
                'url' => 'https://fresh-microsite.test',
                'historyDays' => 9,
                'baseMs' => 180,
                'certificate' => ['days' => 89],
            ],
            [
                'name' => 'Legacy Forum',
                'url' => 'https://legacy-forum.test',
                'recordsHistory' => false,
                'baseMs' => 410,
                'certificate' => ['days' => 27],
            ],
            [
                'name' => 'Troubled Coffee Shop',
                'url' => 'https://troubled-coffee.test',
                'baseMs' => 290,
                'failures' => [
                    ['from' => 5 * self::DAY + 200, 'to' => 5 * self::DAY - 20, 'reason' => 'Connection refused', 'incident' => true],
                    ['from' => 50, 'to' => 0, 'reason' => 'cURL error 7: Failed to connect to troubled-coffee.test port 443: Connection refused', 'incident' => true],
                ],
                'certificate' => ['days' => -10, 'status' => CertificateStatus::INVALID, 'reason' => 'The certificate has expired'],
            ],
            [
                'name' => 'Plain HTTP Archive',
                'url' => 'http://plain-archive.test',
                'baseMs' => 170,
                'certificate' => ['enabled' => false],
            ],
        ];
    }

    /**
     * @param  DemoMonitor  $scenario
     * @return list<array{checked_at: CarbonImmutable, succeeded: bool, http_status: ?int, response_time_ms: ?int, failure_reason: ?string}>
     */
    private function history(array $scenario): array
    {
        $latestMinutesAgo = array_key_exists('latestMinutesAgo', $scenario) ? $scenario['latestMinutesAgo'] : 4 + (crc32($scenario['name']) % 8);

        if ($latestMinutesAgo === null) {
            return [];
        }

        $windowStart = Date::today()->toImmutable()->subDays(($scenario['historyDays'] ?? 30) - 1);
        $offsets = [];

        for ($minutes = $latestMinutesAgo; $minutes <= $latestMinutesAgo + 360 - 15; $minutes += 15) {
            $offsets[] = $minutes;
        }

        for ($minutes = $latestMinutesAgo + 360; $this->now->subMinutes($minutes)->gte($windowStart); $minutes += 180) {
            $offsets[] = $minutes;
        }

        $checks = [];

        foreach (array_reverse($offsets) as $minutesAgo) {
            $failure = $this->windowFor($scenario['failures'] ?? [], $minutesAgo);
            $slow = $this->windowFor($scenario['slow'] ?? [], $minutesAgo);
            $baseMs = $slow['ms'] ?? $scenario['baseMs'] ?? 250;

            $checks[] = [
                'checked_at' => $this->now->subMinutes($minutesAgo),
                'succeeded' => $failure === null,
                'http_status' => $failure === null ? 200 : $this->statusFrom($failure['reason']),
                'response_time_ms' => $failure === null ? $baseMs + (crc32($scenario['name'] . $minutesAgo) % (int) max(60, $baseMs * 0.35)) : null,
                'failure_reason' => $failure['reason'] ?? null,
            ];
        }

        return $checks;
    }

    /**
     * @template T of array{from: int, to: int}
     *
     * @param  list<T>  $windows
     * @return T|null
     */
    private function windowFor(array $windows, int $minutesAgo): ?array
    {
        foreach ($windows as $window) {
            if ($minutesAgo <= $window['from'] && $minutesAgo >= $window['to']) {
                return $window;
            }
        }

        return null;
    }

    private function statusFrom(string $reason): ?int
    {
        return preg_match('/`(\d{3}) /', $reason, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * Spatie's monitor columns, as they would be after the latest check.
     *
     * @param  DemoMonitor  $scenario
     * @param  list<array{checked_at: CarbonImmutable, succeeded: bool, failure_reason: ?string}>  $history
     * @return array<string, mixed>
     */
    private function monitorRow(array $scenario, array $history): array
    {
        $latest = $history === [] ? null : $history[array_key_last($history)];
        $failedInARow = 0;
        $lastChange = $history[0]['checked_at'] ?? null;

        foreach (array_reverse($history) as $check) {
            if ($check['succeeded']) {
                break;
            }

            $failedInARow++;
            $lastChange = $check['checked_at'];
        }

        $certificate = $scenario['certificate'] ?? [];
        $certificateEnabled = $certificate['enabled'] ?? true;
        $certificateDays = array_key_exists('days', $certificate) ? $certificate['days'] : 90;
        $monitor = $scenario['monitor'] ?? [];

        return [
            'name' => $scenario['name'],
            'url' => $scenario['url'],
            'server_url' => null,
            'uptime_check_enabled' => true,
            'uptime_check_method' => 'head',
            'uptime_check_interval_in_minutes' => 15,
            'uptime_status' => match (true) {
                $latest === null => UptimeStatus::NOT_YET_CHECKED,
                $latest['succeeded'] => UptimeStatus::UP,
                default => UptimeStatus::DOWN,
            },
            'uptime_check_times_failed_in_a_row' => $failedInARow,
            'uptime_check_failure_reason' => $failedInARow > 0 ? $latest['failure_reason'] : '',
            'uptime_last_check_date' => $latest['checked_at'] ?? null,
            'uptime_status_last_change_date' => $lastChange,
            'uptime_check_failed_event_fired_on_date' => $failedInARow >= 2 ? $latest['checked_at'] : null,
            'certificate_check_enabled' => $certificateEnabled,
            'certificate_status' => $certificateEnabled ? ($certificate['status'] ?? CertificateStatus::VALID) : CertificateStatus::NOT_YET_CHECKED,
            'certificate_expiration_date' => $certificateEnabled && $certificateDays !== null ? $this->now->addDays($certificateDays) : null,
            'certificate_issuer' => $certificateEnabled ? (array_key_exists('issuer', $certificate) ? $certificate['issuer'] : "Let's Encrypt R11") : null,
            'certificate_check_failure_reason' => $certificate['reason'] ?? '',
            'paused_at' => isset($monitor['paused_at']) ? $this->now->subMinutes($monitor['paused_at']) : null,
            'paused_until' => null,
            'pause_reason' => $monitor['pause_reason'] ?? null,
            'created_at' => $this->now->subDays($scenario['historyDays'] ?? 30),
            'updated_at' => $this->now,
        ];
    }

    /**
     * One incident per failure window marked as an incident: it starts at
     * the window's first failed check and resolves at the next passing one.
     *
     * @param  DemoMonitor  $scenario
     * @param  list<array{checked_at: CarbonImmutable, succeeded: bool, failure_reason: ?string}>  $history
     * @return list<array<string, mixed>>
     */
    private function incidents(array $scenario, array $history): array
    {
        $incidents = [];

        foreach ($scenario['failures'] ?? [] as $window) {
            if (! ($window['incident'] ?? false)) {
                continue;
            }

            $startedAt = null;
            $resolvedAt = null;

            foreach ($history as $check) {
                $minutesAgo = (int) $check['checked_at']->diffInMinutes($this->now, absolute: true);

                if ($startedAt === null && ! $check['succeeded'] && $minutesAgo <= $window['from'] && $minutesAgo >= $window['to']) {
                    $startedAt = $check['checked_at'];
                } elseif ($startedAt !== null && $check['succeeded']) {
                    $resolvedAt = $check['checked_at'];

                    break;
                }
            }

            if ($startedAt !== null) {
                $incidents[] = [
                    'started_at' => $startedAt,
                    'resolved_at' => $resolvedAt,
                    'failure_reason' => mb_strimwidth($window['reason'], 0, 255, '…'),
                    'created_at' => $startedAt,
                    'updated_at' => $resolvedAt ?? $startedAt,
                ];
            }
        }

        return $incidents;
    }
}
