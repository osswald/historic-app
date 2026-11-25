<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'vehicle')]
class Vehicle
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $number;

    #[ORM\Column(enumType: VehicleStatus::class)]
    private VehicleStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $technicalNote;

    #[ORM\Column]
    private bool $tracksMotorHours;

    #[ORM\Column]
    private int $sortOrder;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, BatteryCharge> */
    #[ORM\OneToMany(mappedBy: 'vehicle', targetEntity: BatteryCharge::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['chargedAt' => 'DESC'])]
    private Collection $batteryCharges;

    /** @var Collection<int, MeterReading> */
    #[ORM\OneToMany(mappedBy: 'vehicle', targetEntity: MeterReading::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['readOn' => 'DESC', 'recordedAt' => 'DESC'])]
    private Collection $meterReadings;

    public function __construct(
        string $name,
        ?string $number,
        VehicleStatus $status,
        ?string $technicalNote,
        bool $tracksMotorHours,
        int $sortOrder,
        \DateTimeImmutable $now,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->number = $number;
        $this->status = $status;
        $this->technicalNote = $technicalNote;
        $this->tracksMotorHours = $tracksMotorHours;
        $this->sortOrder = $sortOrder;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->batteryCharges = new ArrayCollection();
        $this->meterReadings = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function number(): ?string
    {
        return $this->number;
    }

    public function label(): string
    {
        $number = trim((string) $this->number);

        return $number === '' ? $this->name : $this->name.' '.$number;
    }

    public function status(): VehicleStatus
    {
        return $this->status;
    }

    public function technicalNote(): ?string
    {
        return $this->technicalNote;
    }

    public function tracksMotorHours(): bool
    {
        return $this->tracksMotorHours;
    }

    public function change(
        string $name,
        ?string $number,
        VehicleStatus $status,
        ?string $technicalNote,
        bool $tracksMotorHours,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->number = $number;
        $this->status = $status;
        $this->technicalNote = $technicalNote;
        $this->tracksMotorHours = $tracksMotorHours;
        $this->updatedAt = $now;
    }

    public function recordBattery(string $actorSub, string $actorName, \DateTimeImmutable $at): BatteryCharge
    {
        $charge = new BatteryCharge($this, $actorSub, $actorName, $at);
        $this->batteryCharges->add($charge);

        return $charge;
    }

    public function recordMeter(
        MeterKind $kind,
        string $reading,
        \DateTimeImmutable $readOn,
        string $actorSub,
        string $actorName,
        \DateTimeImmutable $recordedAt,
    ): MeterReading {
        $entry = new MeterReading($this, $kind, $reading, $readOn, $actorSub, $actorName, $recordedAt);
        $this->meterReadings->add($entry);

        return $entry;
    }

    /** @return list<BatteryCharge> */
    public function batteryCharges(): array
    {
        return array_values($this->batteryCharges->toArray());
    }

    /** @return list<MeterReading> */
    public function meterReadings(): array
    {
        return array_values($this->meterReadings->toArray());
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'name' => $this->name,
            'number' => $this->number,
            'status' => $this->status->value,
            'technicalNote' => $this->technicalNote,
            'tracksMotorHours' => $this->tracksMotorHours,
        ];
    }
}
