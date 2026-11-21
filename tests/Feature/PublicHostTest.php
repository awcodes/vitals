<?php

declare(strict_types=1);

use Awcodes\Vitals\Support\PublicHost;
use Illuminate\Support\Facades\Validator;

function validatesPublicHost(string $url, array $resolvesTo = []): bool
{
    return Validator::make(['url' => $url], ['url' => [new PublicHost(fn (): array => $resolvesTo)]])->passes();
}

it('accepts hosts that resolve to public addresses', function (): void {
    expect(validatesPublicHost('https://example.com', ['93.184.215.14']))->toBeTrue()
        ->and(validatesPublicHost('https://93.184.215.14'))->toBeTrue();
});

it('rejects private, loopback and reserved destinations', function (string $url, array $resolvesTo): void {
    expect(validatesPublicHost($url, $resolvesTo))->toBeFalse();
})->with([
    'loopback literal' => ['http://127.0.0.1', []],
    'private literal' => ['http://10.0.0.5/admin', []],
    'link-local metadata' => ['http://169.254.169.254/latest/meta-data', []],
    'ipv6 loopback' => ['http://[::1]', []],
    'name resolving to private' => ['https://intranet.example.com', ['192.168.1.10']],
    'mixed resolution' => ['https://example.com', ['93.184.215.14', '10.0.0.1']],
    'unresolvable' => ['https://nope.invalid', []],
]);
