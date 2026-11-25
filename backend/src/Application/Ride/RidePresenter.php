<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Domain\Fleet\Vehicle;
use App\Domain\Ride\AssignmentKind;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideVehicle;
use App\Security\MemberUser;

final class RidePresenter
{
    /**
     * @param list<Ride> $rides
     * @param list<string> $warnings
     * @param list<Vehicle> $fleet
     * @return array{canEdit: bool, warnings: list<string>, rides: list<array<string, mixed>>, ownVehicles: list<array<string, mixed>>}
     */
    public function present(array $rides, MemberUser $user, array $warnings, array $fleet): array
    {
        $canEdit = $user->canPlanRides();

        return [
            'canEdit' => $canEdit,
            'warnings' => $canEdit ? array_values($warnings) : [],
            'rides' => array_map(fn (Ride $ride): array => $this->ride($ride, $canEdit), $rides),
            'ownVehicles' => array_map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id(),
                'name' => $vehicle->name(),
                'number' => $vehicle->number(),
                'label' => $vehicle->label(),
                'status' => $vehicle->status()->value,
                'statusLabel' => $vehicle->status()->label(),
            ], $fleet),
        ];
    }

    /** @return array<string, mixed> */
    private function ride(Ride $ride, bool $canSeeInternal): array
    {
        return [
            'id' => $ride->id(),
            'eventDayId' => $ride->eventDay()->id(),
            'departureTime' => $ride->departureTime()?->format('H:i'),
            'departureOffset' => $ride->departureOffset()?->value,
            'arrivalTime' => $ride->arrivalTime()?->format('H:i'),
            'arrivalOffset' => $ride->arrivalOffset()?->value,
            'trainNumber' => $ride->trainNumber(),
            'origin' => [
                'didokCode' => $ride->originDidokCode(),
                'name' => $ride->originName(),
            ],
            'destination' => [
                'didokCode' => $ride->destinationDidokCode(),
                'name' => $ride->destinationName(),
            ],
            'vehicles' => array_map(fn (RideVehicle $assignment): array => $this->assignment($assignment), $ride->assignments()),
            'internalNote' => $canSeeInternal ? $ride->internalNote() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function assignment(RideVehicle $assignment): array
    {
        $vehicle = $assignment->vehicle();

        return [
            'id' => $assignment->id(),
            'kind' => $assignment->kind()->value,
            'vehicleId' => $vehicle?->id(),
            'name' => $assignment->kind() === AssignmentKind::External ? $assignment->externalName() : $vehicle?->name(),
            'number' => $vehicle?->number(),
            'label' => $assignment->label(),
        ];
    }
}
