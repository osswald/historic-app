<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Fleet\Vehicle;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_vehicle')]
#[ORM\UniqueConstraint(name: 'uniq_event_vehicle', columns: ['event_id', 'vehicle_id'])]
class EventVehicle
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'assignedVehicles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Vehicle $vehicle;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $rentalStatus;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(Event $event, Vehicle $vehicle, ?string $rentalStatus, int $sortOrder, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->vehicle = $vehicle;
        $this->rentalStatus = $rentalStatus;
        $this->sortOrder = $sortOrder;
    }

    public function vehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function rentalStatus(): ?string
    {
        return $this->rentalStatus;
    }

    public function revise(?string $rentalStatus, int $sortOrder): void
    {
        $this->rentalStatus = $rentalStatus;
        $this->sortOrder = $sortOrder;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
