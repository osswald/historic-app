<?php

declare(strict_types=1);

namespace App\Domain\Gastronomy;

interface GastroPlanRepository
{
    public function add(GastroPlan $plan): void;

    public function byEventId(string $eventId): ?GastroPlan;
}
