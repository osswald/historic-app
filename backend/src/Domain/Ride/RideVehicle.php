<?php

declare(strict_types=1);

namespace App\Domain\Ride;

use App\Domain\Fleet\Vehicle;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ride_vehicle')]
class RideVehicle
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'vehicles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ride $ride;

    #[ORM\Column(enumType: AssignmentKind::class)]
    private AssignmentKind $kind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Vehicle $vehicle;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(
        Ride $ride,
        Vehicle $vehicle,
        int $sortOrder,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->ride = $ride;
        $this->kind = $vehicle->isExternal() ? AssignmentKind::External : AssignmentKind::Own;
        $this->vehicle = $vehicle;
        $this->sortOrder = $sortOrder;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): AssignmentKind
    {
        return $this->kind;
    }

    public function vehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function isOwn(): bool
    {
        return !$this->vehicle->isExternal();
    }

    public function label(): string
    {
        return $this->vehicle->label();
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'kind' => $this->kind->value,
            'vehicleId' => $this->vehicle?->id(),
            'name' => $this->label(),
        ];
    }
}
