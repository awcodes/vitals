<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Filament\Contracts\Plugin;
use Filament\Panel;

class VitalsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'vitals';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
