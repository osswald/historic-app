<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventDayRepository
{
    public function byId(string $id): ?EventDay;
}
