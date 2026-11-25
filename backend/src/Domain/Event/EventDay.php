<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_day')]
class EventDay
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'days')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $occursOn;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $location;

    #[ORM\Column]
    private int $sortOrder;

    /** @var Collection<int, MeetingPoint> */
    #[ORM\OneToMany(mappedBy: 'eventDay', targetEntity: MeetingPoint::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $meetingPoints;

    /** @var Collection<int, EventResponse> */
    #[ORM\OneToMany(mappedBy: 'eventDay', targetEntity: EventResponse::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $responses;

    public function __construct(
        Event $event,
        \DateTimeImmutable $occursOn,
        ?\DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        ?string $location,
        int $sortOrder,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->occursOn = $occursOn;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->location = $location;
        $this->sortOrder = $sortOrder;
        $this->meetingPoints = new ArrayCollection();
        $this->responses = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function occursOn(): \DateTimeImmutable
    {
        return $this->occursOn;
    }

    public function startsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return list<MeetingPoint> */
    public function meetingPoints(): array
    {
        $points = $this->meetingPoints->toArray();
        usort($points, static fn (MeetingPoint $left, MeetingPoint $right): int => $left->sortOrder() <=> $right->sortOrder());

        return array_values($points);
    }

    /** @param list<array{place: Place, meetsAt: ?\DateTimeImmutable, description: ?string}> $points */
    public function replaceMeetingPoints(array $points): void
    {
        foreach ($this->meetingPoints->toArray() as $point) {
            $this->meetingPoints->removeElement($point);
        }
        foreach ($points as $index => $point) {
            $this->meetingPoints->add(new MeetingPoint(
                $this,
                $point['place'],
                $point['meetsAt'],
                $point['description'],
                $index,
            ));
        }
    }

    public function revisePlan(
        \DateTimeImmutable $occursOn,
        ?\DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        ?string $location,
        int $sortOrder,
    ): void {
        $this->occursOn = $occursOn;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->location = $location;
        $this->sortOrder = $sortOrder;
    }

    public function responseFor(string $sub): ?EventResponse
    {
        foreach ($this->responses as $response) {
            if ($response->auth0Sub() === $sub) {
                return $response;
            }
        }

        return null;
    }

    /** @return list<EventResponse> */
    public function responses(): array
    {
        return array_values($this->responses->toArray());
    }

    public function answer(string $sub, string $name, Attendance $answer, ?string $comment, \DateTimeImmutable $at): EventResponse
    {
        $existing = $this->responseFor($sub);
        if ($existing instanceof EventResponse) {
            $existing->revise($answer, $comment, $name, $at);

            return $existing;
        }

        $response = new EventResponse($this, $sub, $name, $answer, $comment, $at);
        $this->responses->add($response);

        return $response;
    }
}
