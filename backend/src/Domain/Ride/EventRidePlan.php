<?php

declare(strict_types=1);

namespace App\Domain\Ride;

use App\Domain\Event\Event;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_ride_plan')]
#[ORM\UniqueConstraint(name: 'uniq_event_ride_plan_event', columns: ['event_id'])]
class EventRidePlan
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(nullable: true)]
    private ?bool $driverReported = null;

    #[ORM\Column(enumType: PathStatus::class, nullable: true)]
    private ?PathStatus $pathStatus = null;

    #[ORM\Column(enumType: HireStatus::class, nullable: true)]
    private ?HireStatus $hireStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $shuntingNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $breaksNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $furtherInfo = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Event $event, \DateTimeImmutable $now, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function driverReported(): ?bool
    {
        return $this->driverReported;
    }

    public function pathStatus(): ?PathStatus
    {
        return $this->pathStatus;
    }

    public function hireStatus(): ?HireStatus
    {
        return $this->hireStatus;
    }

    public function shuntingNote(): ?string
    {
        return $this->shuntingNote;
    }

    public function breaksNote(): ?string
    {
        return $this->breaksNote;
    }

    public function furtherInfo(): ?string
    {
        return $this->furtherInfo;
    }

    public function revise(
        ?bool $driverReported,
        ?PathStatus $pathStatus,
        ?HireStatus $hireStatus,
        ?string $shuntingNote,
        ?string $breaksNote,
        ?string $furtherInfo,
        \DateTimeImmutable $now,
    ): void {
        $this->driverReported = $driverReported;
        $this->pathStatus = $pathStatus;
        $this->hireStatus = $hireStatus;
        $this->shuntingNote = $shuntingNote;
        $this->breaksNote = $breaksNote;
        $this->furtherInfo = $furtherInfo;
        $this->updatedAt = $now;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'eventId' => $this->event->id(),
            'driverReported' => $this->driverReported,
            'pathStatus' => $this->pathStatus?->value,
            'hireStatus' => $this->hireStatus?->value,
            'shunting' => $this->shuntingNote,
            'breaks' => $this->breaksNote,
            'furtherInfo' => $this->furtherInfo,
        ];
    }
}
