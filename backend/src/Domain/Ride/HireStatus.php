<?php

declare(strict_types=1);

namespace App\Domain\Ride;

enum HireStatus: string
{
    case NotApplicable = 'not_applicable';
    case Requested = 'requested';
    case Ordered = 'ordered';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'nicht zutreffend',
            self::Requested => 'angefragt',
            self::Ordered => 'bestellt',
            self::Confirmed => 'bestätigt',
        };
    }
}
