---
title: Security
description: How Vitals keeps the uptime checker from being aimed at private networks, and the limits of that protection.
---

# Security

An uptime checker makes HTTP requests from your server to URLs that users enter. Without limits, it could be pointed at services on your own network, such as a cloud metadata endpoint or an internal admin page. Vitals guards against this in two places.

## Monitor URLs

When a monitor is added or edited, the `Awcodes\Vitals\Support\PublicHost` validation rule rejects a URL whose host is, or resolves to:

- a loopback address, such as `127.0.0.1` or `::1`;
- a private address, such as `10.0.0.5` or `192.168.1.10`;
- a link-local address, such as `169.254.169.254`;
- any other reserved address.

Every IPv4 address (A records and the hosts file) and every IPv6 address (AAAA records) the host resolves to is checked, so a host that resolves to one public and one private address is refused. Addresses in the NAT64 prefix `64:ff9b::/96`, which embed an IPv4 address and are not flagged by PHP's own filters, are refused too. A host that does not resolve is refused as well.

You can use the rule in your own forms:

```php
use Awcodes\Vitals\Support\PublicHost;

$request->validate([
    'url' => ['required', 'url', new PublicHost],
]);
```

## Redirects

A public site could still redirect the checker to a private address. `TimedUptimeCheckRequest`, which [Installation](installation.md#point-spatie-at-vitals) registers with Spatie, applies the same test to every redirect target:

- Up to five redirects are followed, over `http` and `https` only, without a `Referer` header.
- A redirect to a private address stops the request before it is sent. The check fails with the reason "Unsafe redirect blocked".
- Ordinary redirects, such as `http` to `https`, are still followed.

## Monitoring internal services

Both guards are on by default. If you monitor services on your own network, turn them off in `config/vitals.php`:

```php
'allow_private_addresses' => true,
```

or with `VITALS_ALLOW_PRIVATE_ADDRESSES=true`. The add and edit forms then accept private addresses, and checks follow redirects with Guzzle's defaults, as Spatie's own request does. Anyone who can add or edit a monitor can then make your server request internal addresses, so limit that with the [policy](authorization.md) first. `vitals:doctor` warns while the setting is on.

## What is stored

- Failure reasons are the messages Guzzle and Spatie produce, cut to 255 characters. They may contain the monitored URL.
- Response bodies and request headers are never stored.
- TLS verification is never turned off.

## Known limitations

> [!CAUTION]
> Vitals does not protect against DNS rebinding. A host can resolve to a public address when it is validated and to a private one when it is requested. A complete fix pins the resolved address for each request, which Vitals does not do.

Spatie supports extra request headers per monitor through `uptime_check_additional_headers`, but stores them in plain text on the `monitors` table. Vitals does not offer a way to set them; if you add one, encrypt them.
