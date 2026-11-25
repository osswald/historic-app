<?php

declare(strict_types=1);

namespace App\Domain\Event;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Published => 'Veröffentlicht',
            self::Cancelled => 'Abgesagt',
            self::Archived => 'Archiviert',
        };
    }
}
