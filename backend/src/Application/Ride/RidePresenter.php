<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Domain\Fleet\Vehicle;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideVehicle;
use App\Security\MemberUser;

final class RidePresenter
{
    /**
     * @param list<Ride> $rides
     * @param list<string> $warnings
     * @param list<Vehicle> $fleet
     * @return array{canEdit: bool, warnings: list<string>, rides: list<array<string, mixed>>, eventVehicles: list<array<string, mixed>>, fleet: list<array<string, mixed>>}
     */
    public function present(\App\Domain\Event\Event $event, array $rides, MemberUser $user, array $warnings, array $fleet): array
    {
        $canEdit = $user->canPlanRides();

        return [
            'canEdit' => $canEdit,
            'warnings' => $canEdit ? array_values($warnings) : [],
            'rides' => array_map(fn (Ride $ride): array => $this->ride($ride, $canEdit), $rides),
            'eventVehicles' => array_map(static fn (\App\Domain\Event\EventVehicle $link): array => [
                'vehicleId' => $link->vehicle()->id(),
                'name' => $link->vehicle()->name(),
                'number' => $link->vehicle()->number(),
                'label' => $link->vehicle()->label(),
                'external' => $link->vehicle()->isExternal(),
                'status' => $link->vehicle()->status()->value,
                'statusLabel' => $link->vehicle()->status()->label(),
                'rentalStatus' => $link->rentalStatus(),
            ], $event->assignedVehicles()),
            'fleet' => array_map(fn (Vehicle $vehicle): array => $this->fleetVehicle($vehicle), $fleet),
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
                'abbreviation' => $ride->originAbbreviation(),
            ],
            'destination' => [
                'didokCode' => $ride->destinationDidokCode(),
                'name' => $ride->destinationName(),
                'abbreviation' => $ride->destinationAbbreviation(),
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
            'vehicleId' => $vehicle->id(),
            'name' => $vehicle->name(),
            'number' => $vehicle->number(),
            'label' => $assignment->label(),
            'external' => $vehicle->isExternal(),
        ];
    }

    /** @return array<string, mixed> */
    private function fleetVehicle(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id(),
            'name' => $vehicle->name(),
            'number' => $vehicle->number(),
            'label' => $vehicle->label(),
            'external' => $vehicle->isExternal(),
            'status' => $vehicle->status()->value,
            'statusLabel' => $vehicle->status()->label(),
        ];
    }
}
