<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Domain\Ride\Ride;
use App\Security\MemberUser;

final class RidePresenter
{
    /**
     * @param list<Ride> $rides
     * @param list<string> $warnings
     * @return array{canEdit: bool, warnings: list<string>, rides: list<array<string, mixed>>}
     */
    public function present(array $rides, MemberUser $user, array $warnings): array
    {
        $canEdit = $user->canPlanRides();

        return [
            'canEdit' => $canEdit,
            'warnings' => $canEdit ? array_values($warnings) : [],
            'rides' => array_map(fn (Ride $ride): array => $this->ride($ride, $canEdit), $rides),
        ];
    }

    /** @return array<string, mixed> */
    private function ride(Ride $ride, bool $canSeeInternal): array
    {
        return [
            'id' => $ride->id(),
            'eventDayId' => $ride->eventDay()->id(),
            'departureTime' => $ride->departureTime()->format('H:i'),
            'departureOffset' => $ride->departureOffset()->value,
            'arrivalTime' => $ride->arrivalTime()->format('H:i'),
            'arrivalOffset' => $ride->arrivalOffset()->value,
            'vehicleLabel' => $ride->vehicleLabel(),
            'clubVehicle' => $ride->clubVehicle(),
            'internalNote' => $canSeeInternal ? $ride->internalNote() : null,
        ];
    }
}
