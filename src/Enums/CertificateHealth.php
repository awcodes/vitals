<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CertificateHealth: string implements HasColor, HasLabel
{
    case Invalid = 'invalid';
    case ExpiringSoon = 'expiring_soon';
    case InspectionFailed = 'inspection_failed';
    case Unknown = 'unknown';
    case Valid = 'valid';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invalid => 'Invalid',
            self::ExpiringSoon => 'Expiring soon',
            self::InspectionFailed => 'Check failed',
            self::Unknown => 'Not checked',
            self::Valid => 'Valid',
            self::Disabled => 'Off',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invalid => 'danger',
            self::ExpiringSoon, self::InspectionFailed => 'warning',
            self::Unknown, self::Disabled => 'gray',
            self::Valid => 'success',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::Invalid, self::ExpiringSoon, self::InspectionFailed], true);
    }
}
