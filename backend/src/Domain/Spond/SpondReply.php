<?php

declare(strict_types=1);

namespace App\Domain\Spond;

enum SpondReply: string
{
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Unanswered = 'unanswered';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Zugesagt',
            self::Declined => 'Abgesagt',
            self::Unanswered => 'Unbeantwortet',
        };
    }
}
