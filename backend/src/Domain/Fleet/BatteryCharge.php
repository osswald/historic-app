<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'battery_charge')]
class BatteryCharge
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'batteryCharges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vehicle $vehicle;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $chargedAt;

    #[ORM\Column(length: 190)]
    private string $actorSub;

    #[ORM\Column(length: 200)]
    private string $actorName;

    public function __construct(Vehicle $vehicle, string $actorSub, string $actorName, \DateTimeImmutable $chargedAt, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->vehicle = $vehicle;
        $this->actorSub = $actorSub;
        $this->actorName = $actorName;
        $this->chargedAt = $chargedAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function chargedAt(): \DateTimeImmutable
    {
        return $this->chargedAt;
    }

    public function actorName(): string
    {
        return $this->actorName;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'vehicleId' => $this->vehicle->id(),
            'chargedAt' => $this->chargedAt->format(\DateTimeInterface::ATOM),
            'actorName' => $this->actorName,
        ];
    }
}
