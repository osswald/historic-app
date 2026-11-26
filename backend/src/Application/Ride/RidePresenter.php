<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Domain\Fleet\Vehicle;
use App\Domain\Ride\EventRidePlan;
use App\Domain\Ride\EventRidePlanRepository;
use App\Domain\Ride\Evu;
use App\Domain\Ride\EvuRepository;
use App\Domain\Ride\HireStatus;
use App\Domain\Ride\PathStatus;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideType;
use App\Domain\Ride\RideTypeRepository;
use App\Domain\Ride\RideVehicle;
use App\Domain\Ride\RideVia;
use App\Security\MemberUser;

final class RidePresenter
{
    public function __construct(
        private RideTypeRepository $rideTypes,
        private EvuRepository $evus,
        private EventRidePlanRepository $plans,
    ) {
    }

    /**
     * @param list<Ride> $rides
     * @param list<string> $warnings
     * @param list<Vehicle> $fleet
     * @return array<string, mixed>
     */
    public function present(\App\Domain\Event\Event $event, array $rides, MemberUser $user, array $warnings, array $fleet): array
    {
        $canEdit = $user->canPlanRides();

        return [
            'canEdit' => $canEdit,
            'canManageCatalogs' => $user->isAdmin(),
            'warnings' => $canEdit ? array_values($warnings) : [],
            'rides' => array_map(fn (Ride $ride): array => $this->ride($ride, $canEdit), $rides),
            'planning' => $this->planning($this->plans->forEvent($event->id())),
            'rideTypes' => array_values(array_map(static fn (RideType $type): array => [
                'id' => $type->id(),
                'name' => $type->name(),
                'active' => true,
            ], array_filter($this->rideTypes->all(), static fn (RideType $type): bool => $type->isActive()))),
            'evus' => array_values(array_map(static fn (Evu $evu): array => [
                'id' => $evu->id(),
                'name' => $evu->name(),
                'active' => true,
            ], array_filter($this->evus->all(), static fn (Evu $evu): bool => $evu->isActive()))),
            'pathStatuses' => array_map(static fn (PathStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ], PathStatus::cases()),
            'hireStatuses' => array_map(static fn (HireStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ], HireStatus::cases()),
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
            'vias' => array_map(static fn (RideVia $via): array => [
                'didokCode' => $via->didokCode(),
                'name' => $via->name(),
                'abbreviation' => $via->abbreviation(),
            ], $ride->vias()),
            'rideType' => $ride->rideType() instanceof RideType ? [
                'id' => $ride->rideType()->id(),
                'name' => $ride->rideType()->name(),
                'active' => $ride->rideType()->isActive(),
            ] : null,
            'evu' => $ride->evu() instanceof Evu ? [
                'id' => $ride->evu()->id(),
                'name' => $ride->evu()->name(),
                'active' => $ride->evu()->isActive(),
            ] : null,
            'vehicles' => array_map(fn (RideVehicle $assignment): array => $this->assignment($assignment), $ride->assignments()),
            'internalNote' => $canSeeInternal ? $ride->internalNote() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function planning(?EventRidePlan $plan): array
    {
        return [
            'driverReported' => $plan?->driverReported(),
            'pathStatus' => $plan?->pathStatus()?->value,
            'pathStatusLabel' => $plan?->pathStatus()?->label(),
            'hireStatus' => $plan?->hireStatus()?->value,
            'hireStatusLabel' => $plan?->hireStatus()?->label(),
            'shunting' => $plan?->shuntingNote(),
            'breaks' => $plan?->breaksNote(),
            'furtherInfo' => $plan?->furtherInfo(),
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
