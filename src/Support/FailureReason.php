<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Support;

/**
 * Turns the raw failure text Guzzle and Spatie record (cURL errors, HTTP
 * exception messages) into a short title and one-line explanation for
 * people. The raw text is kept alongside for technical details.
 */
final readonly class FailureReason
{
    public function __construct(
        public string $title,
        public string $summary,
        public ?string $raw,
    ) {}

    public static function from(?string $raw): self
    {
        $text = (string) $raw;

        if (preg_match('/`(\d{3}) ([^`]+)`/', $text, $matches) === 1) {
            $status = (int) $matches[1];

            return new self(
                $status >= 500 ? "Server error ({$status} {$matches[2]})" : "HTTP {$status} {$matches[2]}",
                $status >= 500 ? 'The server responded with an error.' : 'The server rejected the request.',
                $raw,
            );
        }

        [$title, $summary] = match (true) {
            blank($text) => ['Check failed', 'No reason was recorded.'],
            self::matches($text, [TimedUptimeCheckRequest::BLOCKED_REDIRECT]) => ['Unsafe redirect blocked', 'The site redirected to a private network address, so the check stopped.'],
            self::matches($text, ['cURL error 28:', 'timed out']) => ['Timed out', 'The website did not respond in time.'],
            self::matches($text, ['cURL error 6:', 'Could not resolve host']) => ['DNS lookup failed', 'The domain name could not be resolved.'],
            self::matches($text, ['cURL error 35:', 'cURL error 60:', 'SSL certificate problem']) => ['Secure connection failed', 'A TLS connection could not be established.'],
            self::matches($text, ['Connection reset']) => ['Connection reset', 'The connection was dropped before a response arrived.'],
            self::matches($text, ['cURL error 7:', 'Failed to connect', 'Connection refused']) => ['Connection failed', 'The website could not be reached.'],
            self::matches($text, ['was not found on the response']) => ['Expected content missing', 'The page loaded but did not contain the expected text.'],
            default => ['Check failed', 'See technical details.'],
        };

        return new self($title, $summary, $raw);
    }

    /** @param  list<string>  $needles */
    private static function matches(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
