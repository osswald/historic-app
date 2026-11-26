<?php

declare(strict_types=1);

namespace App\Domain\Event;

enum Attendance: string
{
    case Yes = 'yes';
    case No = 'no';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Ja',
            self::No => 'Nein',
        };
    }
}
