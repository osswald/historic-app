<?php

declare(strict_types=1);

namespace App\Domain\Ride;

use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Fleet\Vehicle;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\Column(length: 20)]
    private string $trainNumber;

    #[ORM\Column(length: 12)]
    private string $originDidokCode;

    #[ORM\Column(length: 120)]
    private string $originName;

    #[ORM\Column(length: 12)]
    private string $destinationDidokCode;

    #[ORM\Column(length: 120)]
    private string $destinationName;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalNote;

    #[ORM\Column]
    private int $sortOrder;

    /** @var Collection<int, RideVehicle> */
    #[ORM\OneToMany(mappedBy: 'ride', targetEntity: RideVehicle::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $vehicles;

    public function __construct(
        Event $event,
        EventDay $eventDay,
        \DateTimeImmutable $departureTime,
        DayOffset $departureOffset,
        \DateTimeImmutable $arrivalTime,
        DayOffset $arrivalOffset,
        string $trainNumber,
        string $originDidokCode,
        string $originName,
        string $destinationDidokCode,
        string $destinationName,
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
        $this->trainNumber = $trainNumber;
        $this->originDidokCode = $originDidokCode;
        $this->originName = $originName;
        $this->destinationDidokCode = $destinationDidokCode;
        $this->destinationName = $destinationName;
        $this->internalNote = $internalNote;
        $this->sortOrder = $sortOrder;
        $this->vehicles = new ArrayCollection();
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

    public function trainNumber(): string
    {
        return $this->trainNumber;
    }

    public function originDidokCode(): string
    {
        return $this->originDidokCode;
    }

    public function originName(): string
    {
        return $this->originName;
    }

    public function destinationDidokCode(): string
    {
        return $this->destinationDidokCode;
    }

    public function destinationName(): string
    {
        return $this->destinationName;
    }

    public function internalNote(): ?string
    {
        return $this->internalNote;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return list<RideVehicle> */
    public function assignments(): array
    {
        return array_values($this->vehicles->toArray());
    }

    /** @return list<RideVehicle> */
    public function ownAssignments(): array
    {
        return array_values(array_filter(
            $this->assignments(),
            static fn (RideVehicle $assignment): bool => $assignment->isOwn(),
        ));
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
        string $trainNumber,
        string $originDidokCode,
        string $originName,
        string $destinationDidokCode,
        string $destinationName,
        ?string $internalNote,
        int $sortOrder,
    ): void {
        $this->eventDay = $eventDay;
        $this->departureTime = $departureTime;
        $this->departureOffset = $departureOffset;
        $this->arrivalTime = $arrivalTime;
        $this->arrivalOffset = $arrivalOffset;
        $this->trainNumber = $trainNumber;
        $this->originDidokCode = $originDidokCode;
        $this->originName = $originName;
        $this->destinationDidokCode = $destinationDidokCode;
        $this->destinationName = $destinationName;
        $this->internalNote = $internalNote;
        $this->sortOrder = $sortOrder;
    }

    /**
     * @param list<array{kind: AssignmentKind, vehicle: ?Vehicle, externalName: ?string}> $assignments
     */
    public function replaceAssignments(array $assignments): void
    {
        foreach ($this->vehicles->toArray() as $current) {
            $this->vehicles->removeElement($current);
        }
        foreach ($assignments as $index => $assignment) {
            $this->vehicles->add(new RideVehicle(
                $this,
                $assignment['kind'],
                $assignment['vehicle'],
                $assignment['externalName'],
                $index,
            ));
        }
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
            'trainNumber' => $this->trainNumber,
            'originDidokCode' => $this->originDidokCode,
            'originName' => $this->originName,
            'destinationDidokCode' => $this->destinationDidokCode,
            'destinationName' => $this->destinationName,
            'vehicles' => array_map(static fn (RideVehicle $assignment): array => $assignment->toAudit(), $this->assignments()),
            'internalNote' => $this->internalNote,
        ];
    }
}
