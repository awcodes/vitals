# Vitals

Website uptime, certificate and incident monitoring for Filament panels, built on [spatie/laravel-uptime-monitor](https://github.com/spatie/laravel-uptime-monitor).

[![Latest Version](https://img.shields.io/github/release/awcodes/vitals.svg?style=flat-square&color=blue&label=Release)](https://github.com/awcodes/vitals/releases)
[![MIT Licensed](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/awcodes/vitals.svg?style=flat-square&color=blue&label=Downloads)](https://packagist.org/packages/awcodes/vitals)
[![GitHub Repo stars](https://img.shields.io/github/stars/awcodes/vitals?style=flat-square&color=blue&label=Stars)](https://github.com/awcodes/vitals/stargazers)
[![Filament Version](https://img.shields.io/badge/Filament-4.x%20%26%205.x-d97706.svg?style=flat-square)](https://filamentphp.com/docs/5.x/introduction/installation)

> [!WARNING]
> Vitals is currently a work in progress. Do not use in production yet. Please report any issues you encounter to help us stabilize the package.

## Documentation

The full documentation lives at **[docs.aw.codes/vitals](https://docs.aw.codes/vitals/0.x)**.

## Compatibility

| Filament version | Package version |
|------------------|-----------------|
| 4.x & 5.x        | 0.x             |

## Installation

```bash
composer require awcodes/vitals
```

Then run `php artisan vitals:install`, point `config/uptime-monitor.php` at the Vitals classes, register the plugin on your panel, add the package's views to your Tailwind theme and schedule the checks. See [Installation](https://docs.aw.codes/vitals/0.x/installation) for each step.

## Changelog

Please see the [releases](https://github.com/awcodes/vitals/releases) for what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Adam Weston](https://github.com/awcodes)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
