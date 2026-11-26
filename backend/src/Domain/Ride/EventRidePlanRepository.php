<?php

declare(strict_types=1);

namespace App\Domain\Ride;

interface EventRidePlanRepository
{
    public function add(EventRidePlan $plan): void;

    public function forEvent(string $eventId): ?EventRidePlan;
}
