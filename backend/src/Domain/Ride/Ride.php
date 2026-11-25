<?php

declare(strict_types=1);

namespace App\Domain\Ride;

use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ride')]
class Ride
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EventDay $eventDay;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $departureTime;

    #[ORM\Column(type: Types::SMALLINT, enumType: DayOffset::class)]
    private DayOffset $departureOffset;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $arrivalTime;

    #[ORM\Column(type: Types::SMALLINT, enumType: DayOffset::class)]
    private DayOffset $arrivalOffset;

    #[ORM\Column(length: 120)]
    private string $vehicleLabel;

    #[ORM\Column]
    private bool $clubVehicle;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalNote;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(
        Event $event,
        EventDay $eventDay,
        \DateTimeImmutable $departureTime,
        DayOffset $departureOffset,
        \DateTimeImmutable $arrivalTime,
        DayOffset $arrivalOffset,
        string $vehicleLabel,
        bool $clubVehicle,
        ?string $internalNote,
        int $sortOrder,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->eventDay = $eventDay;
        $this->departureTime = $departureTime;
        $this->departureOffset = $departureOffset;
        $this->arrivalTime = $arrivalTime;
        $this->arrivalOffset = $arrivalOffset;
        $this->vehicleLabel = $vehicleLabel;
        $this->clubVehicle = $clubVehicle;
        $this->internalNote = $internalNote;
        $this->sortOrder = $sortOrder;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function eventDay(): EventDay
    {
        return $this->eventDay;
    }

    public function departureTime(): \DateTimeImmutable
    {
        return $this->departureTime;
    }

    public function departureOffset(): DayOffset
    {
        return $this->departureOffset;
    }

    public function arrivalTime(): \DateTimeImmutable
    {
        return $this->arrivalTime;
    }

    public function arrivalOffset(): DayOffset
    {
        return $this->arrivalOffset;
    }

    public function vehicleLabel(): string
    {
        return $this->vehicleLabel;
    }

    public function clubVehicle(): bool
    {
        return $this->clubVehicle;
    }

    public function internalNote(): ?string
    {
        return $this->internalNote;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function window(): RideWindow
    {
        return RideWindow::on(
            $this->eventDay,
            $this->departureOffset,
            $this->departureTime,
            $this->arrivalOffset,
            $this->arrivalTime,
        );
    }

    public function revise(
        EventDay $eventDay,
        \DateTimeImmutable $departureTime,
        DayOffset $departureOffset,
        \DateTimeImmutable $arrivalTime,
        DayOffset $arrivalOffset,
        string $vehicleLabel,
        bool $clubVehicle,
        ?string $internalNote,
        int $sortOrder,
    ): void {
        $this->eventDay = $eventDay;
        $this->departureTime = $departureTime;
        $this->departureOffset = $departureOffset;
        $this->arrivalTime = $arrivalTime;
        $this->arrivalOffset = $arrivalOffset;
        $this->vehicleLabel = $vehicleLabel;
        $this->clubVehicle = $clubVehicle;
        $this->internalNote = $internalNote;
        $this->sortOrder = $sortOrder;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'eventId' => $this->event->id(),
            'eventDayId' => $this->eventDay->id(),
            'departureTime' => $this->departureTime->format('H:i'),
            'departureOffset' => $this->departureOffset->value,
            'arrivalTime' => $this->arrivalTime->format('H:i'),
            'arrivalOffset' => $this->arrivalOffset->value,
            'vehicleLabel' => $this->vehicleLabel,
            'clubVehicle' => $this->clubVehicle,
            'internalNote' => $this->internalNote,
        ];
    }
}
