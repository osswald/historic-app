<?php

declare(strict_types=1);

namespace App\Domain\Ride;

enum PathStatus: string
{
    case NotApplicable = 'not_applicable';
    case Requested = 'requested';
    case Ordered = 'ordered';
    case Arranged = 'arranged';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'nicht zutreffend',
            self::Requested => 'angefragt',
            self::Ordered => 'bestellt',
            self::Arranged => 'angeordnet',
        };
    }
}
