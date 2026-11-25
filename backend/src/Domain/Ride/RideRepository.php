<?php

declare(strict_types=1);

namespace App\Domain\Ride;

interface RideRepository
{
    public function add(Ride $ride): void;

    public function remove(Ride $ride): void;

    /** @return list<Ride> */
    public function forEvent(string $eventId): array;

    /**
     * Fahrten anderer Veranstaltungen mit mindestens einem Vereinsfahrzeug, Entwürfe eingeschlossen.
     *
     * @return list<Ride>
     */
    public function withOwnVehiclesExcept(string $eventId): array;
}
