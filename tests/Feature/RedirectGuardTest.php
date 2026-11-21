<?php

declare(strict_types=1);

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Awcodes\Vitals\Support\FailureReason;
use Awcodes\Vitals\Support\PublicHost;
use Awcodes\Vitals\Support\TimedUptimeCheckRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Notification;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends the monitor's uptime request through Guzzle's real redirect
 * middleware with canned responses, returning the final response and the
 * responses never requested.
 *
 * @param  list<Response>  $responses
 * @return array{response: ?ResponseInterface, exception: ?Throwable, unused: int}
 */
function requestThroughRedirects(Monitor $monitor, array $responses): array
{
    $options = (fn (Monitor $monitor): array => $this->requestOptions($monitor))
        ->call(new TimedUptimeCheckRequest, $monitor);

    $handler = new MockHandler($responses);
    $client = new Client(['handler' => HandlerStack::create($handler)]);

    try {
        $response = $client->request('HEAD', $monitor->raw_url, $options);

        return ['response' => $response, 'exception' => null, 'unused' => $handler->count()];
    } catch (Throwable $exception) {
        return ['response' => null, 'exception' => $exception, 'unused' => $handler->count()];
    }
}

beforeEach(function (): void {
    $this->monitor = Monitor::factory()->create(['url' => 'https://93.184.215.14']);
});

it('stops before following a redirect to a private address', function (string $location): void {
    $result = requestThroughRedirects($this->monitor, [
        new Response(302, ['Location' => $location]),
        new Response(200),
    ]);

    expect($result['exception'])->toBeInstanceOf(RequestException::class)
        ->and($result['exception'])->toBeInstanceOf(TransferException::class)
        ->and($result['exception']->getMessage())->toContain(TimedUptimeCheckRequest::BLOCKED_REDIRECT)
        ->and($result['unused'])->toBe(1);
})->with([
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'loopback' => ['http://127.0.0.1:8080/admin'],
    'private network' => ['https://10.0.0.12/'],
    'ipv6 loopback' => ['http://[::1]/'],
    'ipv6 unique local' => ['http://[fd00::1]/'],
    'nat64 to loopback' => ['http://[64:ff9b::7f00:1]/'],
]);

it('stops at a later hop in a redirect chain', function (): void {
    $result = requestThroughRedirects($this->monitor, [
        new Response(301, ['Location' => 'https://93.184.215.15/']),
        new Response(302, ['Location' => 'http://192.168.1.1/']),
        new Response(200),
    ]);

    expect($result['exception']?->getMessage())->toContain('(192.168.1.1)')
        ->and($result['unused'])->toBe(1);
});

it('still follows redirects to public addresses', function (): void {
    $result = requestThroughRedirects($this->monitor, [
        new Response(301, ['Location' => 'https://93.184.215.15/home']),
        new Response(200),
    ]);

    expect($result['exception'])->toBeNull()
        ->and($result['response']?->getStatusCode())->toBe(200)
        ->and($result['unused'])->toBe(0);
});

it('records a blocked redirect as a readable failed check', function (): void {
    Notification::fake();

    $this->monitor->uptimeRequestFailed(TimedUptimeCheckRequest::BLOCKED_REDIRECT . ' (169.254.169.254).');

    $check = MonitorCheck::query()->sole();
    $reason = FailureReason::from($check->failure_reason);

    expect($check->succeeded)->toBeFalse()
        ->and($reason->title)->toBe('Unsafe redirect blocked')
        ->and($reason->summary)->toBe('The site redirected to a private network address, so the check stopped.');
});

it('treats only hosts that resolve to private or reserved addresses as private', function (string $host, array $resolvesTo, bool $private): void {
    expect((new PublicHost(fn (): array => $resolvesTo))->isPrivate($host))->toBe($private);
})->with([
    'public literal' => ['93.184.215.14', [], false],
    'public name' => ['example.com', ['93.184.215.14'], false],
    'private literal' => ['10.1.2.3', [], true],
    'name resolving to private' => ['intranet.example.com', ['192.168.0.5'], true],
    'mixed resolution' => ['example.com', ['93.184.215.14', '127.0.0.1'], true],
    'unresolvable' => ['nope.invalid', [], false],
    'public ipv6' => ['example.com', ['2606:4700::1111'], false],
    'ipv6-only internal name' => ['internal.example.com', ['fd00::1'], true],
    'public ipv4 with internal ipv6' => ['example.com', ['93.184.215.14', '::1'], true],
    'link-local ipv6' => ['fe80::1', [], true],
    'nat64 embedding loopback' => ['64:ff9b::7f00:1', [], true],
]);

it('resolves hosts-file names as well as DNS', function (): void {
    expect(PublicHost::resolve('localhost'))->toContain('127.0.0.1')
        ->and((new PublicHost())->isPrivate('localhost'))->toBeTrue();
});
