<?php

declare(strict_types=1);

namespace App\Application;

final class ZurichClock
{
    private const ZONE = 'Europe/Zurich';

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::ZONE));
    }

    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(self::ZONE));
    }
}
