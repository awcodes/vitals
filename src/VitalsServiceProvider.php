<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VitalsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'vitals';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name);
    }
}
