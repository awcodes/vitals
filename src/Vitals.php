<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Filament\Support\Facades\FilamentTimezone;

final class Vitals
{
    /**
     * The timezone dates are shown in: the current panel's ->timezone() on
     * the plugin, then vitals.timezone, then Filament's configured timezone,
     * which itself falls back to app.timezone.
     */
    public static function timezone(): string
    {
        $plugin = VitalsPlugin::current()?->getTimezone();

        if (is_string($plugin) && $plugin !== '') {
            return $plugin;
        }

        $configured = config('vitals.timezone');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $filament = FilamentTimezone::get();

        return $filament !== '' ? $filament : (string) config('app.timezone', 'UTC');
    }
}
