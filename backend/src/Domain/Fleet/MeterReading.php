<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'meter_reading')]
class MeterReading
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'meterReadings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vehicle $vehicle;

    #[ORM\Column(enumType: MeterKind::class)]
    private MeterKind $kind;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 1)]
    private string $reading;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $readOn;

    #[ORM\Column(length: 190)]
    private string $actorSub;

    #[ORM\Column(length: 200)]
    private string $actorName;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct(
        Vehicle $vehicle,
        MeterKind $kind,
        string $reading,
        \DateTimeImmutable $readOn,
        string $actorSub,
        string $actorName,
        \DateTimeImmutable $recordedAt,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->vehicle = $vehicle;
        $this->kind = $kind;
        $this->reading = $reading;
        $this->readOn = $readOn;
        $this->actorSub = $actorSub;
        $this->actorName = $actorName;
        $this->recordedAt = $recordedAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): MeterKind
    {
        return $this->kind;
    }

    public function reading(): string
    {
        return $this->reading;
    }

    public function readOn(): \DateTimeImmutable
    {
        return $this->readOn;
    }

    public function actorName(): string
    {
        return $this->actorName;
    }

    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'vehicleId' => $this->vehicle->id(),
            'kind' => $this->kind->value,
            'reading' => $this->reading,
            'readOn' => $this->readOn->format('Y-m-d'),
            'actorName' => $this->actorName,
        ];
    }
}
