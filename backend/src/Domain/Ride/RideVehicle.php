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
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Vehicle $vehicle;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalName;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(
        Ride $ride,
        AssignmentKind $kind,
        ?Vehicle $vehicle,
        ?string $externalName,
        int $sortOrder,
        ?string $id = null,
    ) {
        if ($kind === AssignmentKind::Own && !$vehicle instanceof Vehicle) {
            throw new \InvalidArgumentException('Ein Vereinsfahrzeug braucht einen Stammdatensatz.');
        }
        if ($kind === AssignmentKind::External && ($externalName === null || $externalName === '' || $vehicle instanceof Vehicle)) {
            throw new \InvalidArgumentException('Ein externes Fahrzeug braucht einen Namen.');
        }

        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->ride = $ride;
        $this->kind = $kind;
        $this->vehicle = $kind === AssignmentKind::Own ? $vehicle : null;
        $this->externalName = $kind === AssignmentKind::External ? $externalName : null;
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

    public function vehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function externalName(): ?string
    {
        return $this->externalName;
    }

    public function isOwn(): bool
    {
        return $this->kind === AssignmentKind::Own && $this->vehicle instanceof Vehicle;
    }

    public function label(): string
    {
        if ($this->vehicle instanceof Vehicle) {
            return $this->vehicle->label();
        }

        return (string) $this->externalName;
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
