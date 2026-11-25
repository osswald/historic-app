<?php

declare(strict_types=1);

namespace App\Domain\Poll;

enum Daypart: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Vormittag',
            self::Afternoon => 'Nachmittag',
            self::Evening => 'Abend',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::Morning => 0,
            self::Afternoon => 1,
            self::Evening => 2,
        };
    }

    public function standardStart(): string
    {
        return match ($this) {
            self::Morning => '09:00',
            self::Afternoon => '13:00',
            self::Evening => '17:00',
        };
    }

    public function standardEnd(): string
    {
        return match ($this) {
            self::Morning => '13:00',
            self::Afternoon => '17:00',
            self::Evening => '21:00',
        };
    }
}
