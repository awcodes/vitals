# Vitals Package Guidelines

`awcodes/vitals` is a Filament plugin for website uptime, certificate and incident
monitoring. `spatie/laravel-uptime-monitor` is the check engine; Vitals adds check
history, incidents, presentation and retention on top of it.

## Compatibility

- One branch (`main`) supports PHP 8.3+, Laravel 12 and 13, and Filament 4 and 5.
- Write PHP 8.3 syntax only. No `array_any()`/`array_all()`, no property hooks,
  no asymmetric visibility, no `new Foo()->bar()` without wrapping parentheses.
  Rector is pinned to the PHP 8.3 set so it does not reintroduce them.
- Filament 4 (Livewire 3) and Filament 5 (Livewire 4) must both pass the suite.
  Where an API differs, branch on a small capability check; do not split branches.
- Laravel support comes through Filament. Do not add `illuminate/*` or
  `laravel/framework` to `require` only to express it.

## Schema

Migrations are package-prescribed and consumer-materialized: they ship as stubs
and are published into the consuming app by `vitals:install`. Every stub must be
idempotent against an existing schema (guard with `Schema::hasTable()` /
`Schema::hasColumn()`), because existing installs adopt the package with their
tables already in place. The Workbench obtains the migrations through the same
install path, never by copying their bodies.

## Commands

- `composer test` runs Rector (dry run), Pint (test), PHPStan and Pest.
- `composer lint` and `composer refactor` apply fixes.
- `composer serve` builds and serves the Workbench at `/admin`; the login form is
  prefilled with `test@example.com` / `password`.

## Rules

- Tests never make network calls. Fake HTTP with Guzzle's `MockHandler` or
  Laravel's HTTP fakes.
- Apps own their schedule. The package must not auto-register scheduled tasks.
- The package never rewrites the app's `config/uptime-monitor.php`.
- Do not tag releases, publish to Packagist, or push to `main` without explicit
  instruction from the maintainer.
