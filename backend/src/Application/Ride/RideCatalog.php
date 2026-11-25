<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Application\Event\EventCatalog;
use App\Domain\Fleet\VehicleRepository;
use App\Domain\Ride\RideRepository;
use App\Security\MemberUser;

final class RideCatalog
{
    public function __construct(
        private EventCatalog $events,
        private RideRepository $rides,
        private VehicleRepository $vehicles,
        private ClubVehicleOverlap $overlap,
        private RidePresenter $presenter,
    ) {
    }

    /** @return array{canEdit: bool, warnings: list<string>, rides: list<array<string, mixed>>, ownVehicles: list<array<string, mixed>>} */
    public function show(MemberUser $user, string $eventId): array
    {
        $event = $this->events->visible($eventId, $user);
        $rides = $this->rides->forEvent($event->id());
        $warnings = $user->canPlanRides()
            ? $this->overlap->warnings($rides, $this->rides->withOwnVehiclesExcept($event->id()))
            : [];

        return $this->presenter->present($rides, $user, $warnings, $this->vehicles->all());
    }
}
