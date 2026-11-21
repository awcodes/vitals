<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MonitorHealth: string implements HasColor, HasLabel
{
    case Down = 'down';
    case Degraded = 'degraded';
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Paused = 'paused';

    public function getLabel(): string
    {
        return match ($this) {
            self::Down => 'Down',
            self::Degraded => 'Degraded',
            self::Unknown => 'Unknown',
            self::Healthy => 'Healthy',
            self::Paused => 'Paused',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Down => 'danger',
            self::Degraded => 'warning',
            self::Unknown, self::Paused => 'gray',
            self::Healthy => 'success',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::Down, self::Degraded, self::Unknown], true);
    }
}
