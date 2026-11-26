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

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $departureTime;

    #[ORM\Column(type: Types::SMALLINT, enumType: DayOffset::class, nullable: true)]
    private ?DayOffset $departureOffset;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $arrivalTime;

    #[ORM\Column(type: Types::SMALLINT, enumType: DayOffset::class, nullable: true)]
    private ?DayOffset $arrivalOffset;

    #[ORM\Column(length: 20)]
    private string $trainNumber;

    #[ORM\Column(length: 12)]
    private string $originDidokCode;

    #[ORM\Column(length: 120)]
    private string $originName;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $originAbbreviation;

    #[ORM\Column(length: 12)]
    private string $destinationDidokCode;

    #[ORM\Column(length: 120)]
    private string $destinationName;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $destinationAbbreviation;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalNote;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?RideType $rideType = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Evu $evu = null;

    #[ORM\Column]
    private int $sortOrder;

    /** @var Collection<int, RideVehicle> */
    #[ORM\OneToMany(mappedBy: 'ride', targetEntity: RideVehicle::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $vehicles;

    /** @var Collection<int, RideVia> */
    #[ORM\OneToMany(mappedBy: 'ride', targetEntity: RideVia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $vias;

    public function __construct(
        Event $event,
        EventDay $eventDay,
        ?\DateTimeImmutable $departureTime,
        ?DayOffset $departureOffset,
        ?\DateTimeImmutable $arrivalTime,
        ?DayOffset $arrivalOffset,
        string $trainNumber,
        string $originDidokCode,
        string $originName,
        ?string $originAbbreviation,
        string $destinationDidokCode,
        string $destinationName,
        ?string $destinationAbbreviation,
        ?string $internalNote,
        int $sortOrder,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->eventDay = $eventDay;
        self::assertTimePair($departureTime, $departureOffset, $arrivalTime, $arrivalOffset);
        $this->departureTime = $departureTime;
        $this->departureOffset = $departureOffset;
        $this->arrivalTime = $arrivalTime;
        $this->arrivalOffset = $arrivalOffset;
        $this->trainNumber = $trainNumber;
        $this->originDidokCode = $originDidokCode;
        $this->originName = $originName;
        $this->originAbbreviation = $originAbbreviation;
        $this->destinationDidokCode = $destinationDidokCode;
        $this->destinationName = $destinationName;
        $this->destinationAbbreviation = $destinationAbbreviation;
        $this->internalNote = $internalNote;
        $this->sortOrder = $sortOrder;
        $this->vehicles = new ArrayCollection();
        $this->vias = new ArrayCollection();
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

    public function departureTime(): ?\DateTimeImmutable
    {
        return $this->departureTime;
    }

    public function departureOffset(): ?DayOffset
    {
        return $this->departureOffset;
    }

    public function arrivalTime(): ?\DateTimeImmutable
    {
        return $this->arrivalTime;
    }

    public function arrivalOffset(): ?DayOffset
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

    public function originAbbreviation(): ?string
    {
        return $this->originAbbreviation;
    }

    public function destinationDidokCode(): string
    {
        return $this->destinationDidokCode;
    }

    public function destinationName(): string
    {
        return $this->destinationName;
    }

    public function destinationAbbreviation(): ?string
    {
        return $this->destinationAbbreviation;
    }

    public function internalNote(): ?string
    {
        return $this->internalNote;
    }

    public function rideType(): ?RideType
    {
        return $this->rideType;
    }

    public function evu(): ?Evu
    {
        return $this->evu;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return list<RideVia> */
    public function vias(): array
    {
        return array_values($this->vias->toArray());
    }

    public function chooseType(?RideType $rideType): void
    {
        $this->rideType = $rideType;
    }

    public function chooseEvu(?Evu $evu): void
    {
        $this->evu = $evu;
    }

    /**
     * @param list<array{didokCode: string, name: string, abbreviation: ?string}> $stations
     */
    public function replaceVias(array $stations): void
    {
        foreach ($this->vias->toArray() as $current) {
            $this->vias->removeElement($current);
        }
        foreach ($stations as $index => $station) {
            $this->vias->add(new RideVia(
                $this,
                $station['didokCode'],
                $station['name'],
                $station['abbreviation'],
                $index,
            ));
        }
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
        return RideWindow::occupying(
            $this->eventDay,
            $this->departureOffset,
            $this->departureTime,
            $this->arrivalOffset,
            $this->arrivalTime,
        );
    }

    public function revise(
        EventDay $eventDay,
        ?\DateTimeImmutable $departureTime,
        ?DayOffset $departureOffset,
        ?\DateTimeImmutable $arrivalTime,
        ?DayOffset $arrivalOffset,
        string $trainNumber,
        string $originDidokCode,
        string $originName,
        ?string $originAbbreviation,
        string $destinationDidokCode,
        string $destinationName,
        ?string $destinationAbbreviation,
        ?string $internalNote,
        int $sortOrder,
    ): void {
        self::assertTimePair($departureTime, $departureOffset, $arrivalTime, $arrivalOffset);
        $this->eventDay = $eventDay;
        $this->departureTime = $departureTime;
        $this->departureOffset = $departureOffset;
        $this->arrivalTime = $arrivalTime;
        $this->arrivalOffset = $arrivalOffset;
        $this->trainNumber = $trainNumber;
        $this->originDidokCode = $originDidokCode;
        $this->originName = $originName;
        $this->originAbbreviation = $originAbbreviation;
        $this->destinationDidokCode = $destinationDidokCode;
        $this->destinationName = $destinationName;
        $this->destinationAbbreviation = $destinationAbbreviation;
        $this->internalNote = $internalNote;
        $this->sortOrder = $sortOrder;
    }

    /**
     * @param list<Vehicle> $vehicles
     */
    public function replaceAssignments(array $vehicles): void
    {
        foreach ($this->vehicles->toArray() as $current) {
            $this->vehicles->removeElement($current);
        }
        foreach ($vehicles as $index => $vehicle) {
            $this->vehicles->add(new RideVehicle($this, $vehicle, $index));
        }
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'eventId' => $this->event->id(),
            'eventDayId' => $this->eventDay->id(),
            'departureTime' => $this->departureTime?->format('H:i'),
            'departureOffset' => $this->departureOffset?->value,
            'arrivalTime' => $this->arrivalTime?->format('H:i'),
            'arrivalOffset' => $this->arrivalOffset?->value,
            'trainNumber' => $this->trainNumber,
            'originDidokCode' => $this->originDidokCode,
            'originName' => $this->originName,
            'originAbbreviation' => $this->originAbbreviation,
            'destinationDidokCode' => $this->destinationDidokCode,
            'destinationName' => $this->destinationName,
            'destinationAbbreviation' => $this->destinationAbbreviation,
            'vehicles' => array_map(static fn (RideVehicle $assignment): array => $assignment->toAudit(), $this->assignments()),
            'vias' => array_map(static fn (RideVia $via): array => $via->toAudit(), $this->vias()),
            'rideTypeId' => $this->rideType?->id(),
            'rideTypeName' => $this->rideType?->name(),
            'evuId' => $this->evu?->id(),
            'evuName' => $this->evu?->name(),
            'internalNote' => $this->internalNote,
        ];
    }

    private static function assertTimePair(
        ?\DateTimeImmutable $departureTime,
        ?DayOffset $departureOffset,
        ?\DateTimeImmutable $arrivalTime,
        ?DayOffset $arrivalOffset,
    ): void {
        if (($departureTime === null) !== ($departureOffset === null) || ($arrivalTime === null) !== ($arrivalOffset === null)) {
            throw new \InvalidArgumentException('Eine Zeit und ihr Tagversatz gehören zusammen.');
        }
    }
}
