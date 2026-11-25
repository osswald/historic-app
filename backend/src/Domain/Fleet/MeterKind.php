<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

enum MeterKind: string
{
    case MotorHours = 'motor_hours';
    case Kilometres = 'kilometres';

    public function label(): string
    {
        return match ($this) {
            self::MotorHours => 'Motorstunden',
            self::Kilometres => 'Kilometer',
        };
    }
}
