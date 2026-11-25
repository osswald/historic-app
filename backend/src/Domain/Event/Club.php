<?php

declare(strict_types=1);

namespace App\Domain\Event;

enum Club: string
{
    case Historic = 'historic';
    case Supporter = 'supporter';

    public function label(): string
    {
        return match ($this) {
            self::Historic => 'SBB Historic-Team Winterthur',
            self::Supporter => 'Supporterclub',
        };
    }
}
