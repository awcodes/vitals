<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Support;

/**
 * Carries transfer stats from the uptime request to the check recorder.
 *
 * Spatie only hands the monitor a response or a failure message, so the
 * HTTP status and transfer time are captured here, keyed by monitor id,
 * and pulled once the check result is recorded.
 */
final class UptimeCheckTimings
{
    /** @var array<int, array{http_status: ?int, response_time_ms: ?int}> */
    private array $timings = [];

    public function put(int $monitorId, ?int $httpStatus, ?int $responseTimeMs): void
    {
        $this->timings[$monitorId] = [
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
        ];
    }

    /** @return array{http_status: ?int, response_time_ms: ?int} */
    public function pull(int $monitorId): array
    {
        $timing = $this->timings[$monitorId] ?? ['http_status' => null, 'response_time_ms' => null];

        unset($this->timings[$monitorId]);

        return $timing;
    }
}
