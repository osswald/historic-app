<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

enum VehicleStatus: string
{
    case Ready = 'ready';
    case Limited = 'limited';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Einsatzbereit',
            self::Limited => 'Eingeschränkt',
            self::Blocked => 'Gesperrt',
        };
    }
}
