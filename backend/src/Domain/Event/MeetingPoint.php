<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_meeting_point')]
class MeetingPoint
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'meetingPoints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EventDay $eventDay;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Place $place;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $meetsAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(
        EventDay $eventDay,
        Place $place,
        ?\DateTimeImmutable $meetsAt,
        ?string $description,
        int $sortOrder,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->eventDay = $eventDay;
        $this->place = $place;
        $this->meetsAt = $meetsAt;
        $this->description = $description;
        $this->sortOrder = $sortOrder;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function place(): Place
    {
        return $this->place;
    }

    public function meetsAt(): ?\DateTimeImmutable
    {
        return $this->meetsAt;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
