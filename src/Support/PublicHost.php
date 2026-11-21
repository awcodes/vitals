<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects monitor URLs that point at loopback, private, link-local or
 * reserved addresses, so the uptime checker can't be aimed at internal
 * services. TimedUptimeCheckRequest applies the same test to every
 * redirect. DNS can still change between the check and the request, so
 * this does not protect against DNS rebinding.
 */
final readonly class PublicHost implements ValidationRule
{
    /** @var (Closure(string): list<string>) */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /**
     * Every IPv4 and IPv6 address the host resolves to. gethostbynamel()
     * covers A records and the hosts file; dns_get_record() adds AAAA
     * records, so an IPv6-only internal address can't slip through.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...(gethostbynamel($host) ?: []), ...$ipv6]));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            $fail('The :attribute must include a host.');

            return;
        }

        if ($this->addressesFor($host) === []) {
            $fail('The :attribute host could not be resolved.');

            return;
        }

        if ($this->isPrivate($host)) {
            $fail('The :attribute must point to a public address.');
        }
    }

    /**
     * True when the host is, or resolves to, any loopback, private,
     * link-local or reserved address. An unresolvable host is not treated
     * as private; requesting it simply fails.
     */
    public function isPrivate(string $host): bool
    {
        foreach ($this->addressesFor($host) as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }

            if ($this->isNat64($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The NAT64 well-known prefix (64:ff9b::/96) embeds an IPv4 address,
     * so 64:ff9b::7f00:1 reaches 127.0.0.1. PHP's filters don't flag it.
     */
    private function isNat64(string $address): bool
    {
        $packed = @inet_pton($address);

        return $packed !== false
            && mb_strlen($packed, '8bit') === 16
            && mb_substr($packed, 0, 12, '8bit') === mb_substr((string) inet_pton('64:ff9b::'), 0, 12, '8bit');
    }

    /** @return list<string> */
    private function addressesFor(string $host): array
    {
        $host = trim($host, '[]');

        return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
    }
}
