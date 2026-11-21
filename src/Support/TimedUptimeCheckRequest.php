<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Support;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use Override;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\UptimeMonitor\Actions\SendUptimeCheckRequestAction;
use Spatie\UptimeMonitor\Models\Monitor;

/**
 * Spatie's uptime request, plus:
 * - an on_stats hook recording the HTTP status and transfer time for the
 *   check history;
 * - a redirect guard that stops the request when a redirect points at a
 *   private or reserved address, so a monitored site can't steer the
 *   checker into the server's own network. It throws a Guzzle
 *   RequestException so Spatie records an ordinary failed check.
 *
 * Registered through uptime-monitor.actions.send_uptime_check_request.
 */
final class TimedUptimeCheckRequest extends SendUptimeCheckRequestAction
{
    public const string BLOCKED_REDIRECT = 'Redirect to a private address was blocked';

    /** @return array<string, mixed> */
    #[Override]
    protected function requestOptions(Monitor $monitor): array
    {
        $timings = resolve(UptimeCheckTimings::class);
        $monitorId = (int) $monitor->getKey();
        $publicHost = new PublicHost;

        return [
            ...parent::requestOptions($monitor),
            'allow_redirects' => [
                'max' => 5,
                'protocols' => ['http', 'https'],
                'referer' => false,
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $target) use ($publicHost): void {
                    if ($publicHost->isPrivate($target->getHost())) {
                        throw new RequestException(self::BLOCKED_REDIRECT . " ({$target->getHost()}).", $request, $response);
                    }
                },
            ],
            'on_stats' => function (TransferStats $stats) use ($timings, $monitorId): void {
                $transferTime = $stats->getTransferTime();

                $timings->put(
                    $monitorId,
                    $stats->getResponse()?->getStatusCode(),
                    $stats->hasResponse() && $transferTime !== null ? (int) round($transferTime * 1000) : null,
                );
            },
        ];
    }
}
