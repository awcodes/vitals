<?php

declare(strict_types=1);

namespace Awcodes\Vitals;

use Filament\Support\Facades\FilamentTimezone;

final class Vitals
{
    /**
     * The timezone dates are shown in: vitals.timezone, then Filament's
     * configured timezone, which itself falls back to app.timezone.
     */
    public static function timezone(): string
    {
        $configured = config('vitals.timezone');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $filament = FilamentTimezone::get();

        return $filament !== '' ? $filament : (string) config('app.timezone', 'UTC');
    }
}
