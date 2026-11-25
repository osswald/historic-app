<?php

declare(strict_types=1);

namespace App\Domain\Poll;

enum PollKind: string
{
    case Normal = 'normal';
    case Depot = 'depot';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Terminfindung',
            self::Depot => 'Depottag',
        };
    }
}
