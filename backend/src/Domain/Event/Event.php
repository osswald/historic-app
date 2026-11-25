<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event')]
class Event
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 200)]
    private string $title;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $clubs;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $categories;

    #[ORM\Column(enumType: EventStatus::class)]
    private EventStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalDescription;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $publicDescription;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $location;

    #[ORM\Column]
    private bool $publishToWebsite;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, EventDay> */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: EventDay::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'occursOn' => 'ASC'])]
    private Collection $days;

    /**
     * @param list<string> $clubs
     * @param list<string> $categories
     */
    public function __construct(
        string $title,
        array $clubs,
        array $categories,
        EventStatus $status,
        ?string $internalDescription,
        ?string $publicDescription,
        ?string $location,
        bool $publishToWebsite,
        \DateTimeImmutable $now,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->title = $title;
        $this->clubs = $clubs;
        $this->categories = $categories;
        $this->status = $status;
        $this->internalDescription = $internalDescription;
        $this->publicDescription = $publicDescription;
        $this->location = $location;
        $this->publishToWebsite = $publishToWebsite;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->days = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return list<Club> */
    public function clubs(): array
    {
        $clubs = [];
        foreach ($this->clubs as $value) {
            $club = Club::tryFrom($value);
            if ($club instanceof Club) {
                $clubs[] = $club;
            }
        }

        return $clubs;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return $this->categories;
    }

    public function status(): EventStatus
    {
        return $this->status;
    }

    public function internalDescription(): ?string
    {
        return $this->internalDescription;
    }

    public function publicDescription(): ?string
    {
        return $this->publicDescription;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function publishToWebsite(): bool
    {
        return $this->publishToWebsite;
    }

    /** @return list<EventDay> */
    public function days(): array
    {
        $days = $this->days->toArray();
        usort(
            $days,
            fn (EventDay $a, EventDay $b): int => [$a->sortOrder(), $a->occursOn()->format('Y-m-d')]
                <=> [$b->sortOrder(), $b->occursOn()->format('Y-m-d')],
        );

        return array_values($days);
    }

    public function day(string $id): ?EventDay
    {
        foreach ($this->days as $day) {
            if ($day->id() === $id) {
                return $day;
            }
        }

        return null;
    }

    public function isVisibleTo(bool $organizer): bool
    {
        return $organizer || $this->status !== EventStatus::Draft;
    }

    public function sortKey(): string
    {
        $day = $this->days()[0] ?? null;

        return $day instanceof EventDay ? $day->occursOn()->format('Y-m-d') : '9999-12-31';
    }

    public function headlineDay(\DateTimeImmutable $today): ?EventDay
    {
        $todayKey = $today->format('Y-m-d');
        foreach ($this->days() as $day) {
            if ($day->occursOn()->format('Y-m-d') >= $todayKey) {
                return $day;
            }
        }

        $days = $this->days();

        return $days === [] ? null : $days[array_key_last($days)];
    }

    /**
     * @param list<string> $clubs
     * @param list<string> $categories
     */
    public function change(
        string $title,
        array $clubs,
        array $categories,
        EventStatus $status,
        ?string $internalDescription,
        ?string $publicDescription,
        ?string $location,
        bool $publishToWebsite,
        \DateTimeImmutable $now,
    ): void {
        $this->title = $title;
        $this->clubs = $clubs;
        $this->categories = $categories;
        $this->status = $status;
        $this->internalDescription = $internalDescription;
        $this->publicDescription = $publicDescription;
        $this->location = $location;
        $this->publishToWebsite = $publishToWebsite;
        $this->updatedAt = $now;
    }

    public function addDay(
        \DateTimeImmutable $occursOn,
        ?\DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        ?string $location,
        ?string $meetingPointName,
        ?string $meetingPointDescription,
        int $sortOrder,
        ?string $id = null,
    ): EventDay {
        $day = new EventDay(
            $this,
            $occursOn,
            $startsAt,
            $endsAt,
            $location,
            $meetingPointName,
            $meetingPointDescription,
            $sortOrder,
            $id,
        );
        $this->days->add($day);

        return $day;
    }

    /** @param list<string> $idsToKeep */
    public function retainDays(array $idsToKeep): void
    {
        foreach ($this->days->toArray() as $day) {
            if (!in_array($day->id(), $idsToKeep, true)) {
                $this->days->removeElement($day);
            }
        }
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'title' => $this->title,
            'clubs' => $this->clubs,
            'categories' => $this->categories,
            'status' => $this->status->value,
            'internalDescription' => $this->internalDescription,
            'publicDescription' => $this->publicDescription,
            'location' => $this->location,
            'publishToWebsite' => $this->publishToWebsite,
            'days' => array_map(static fn (EventDay $day): array => [
                'id' => $day->id(),
                'date' => $day->occursOn()->format('Y-m-d'),
                'startsAt' => $day->startsAt()?->format('H:i'),
                'endsAt' => $day->endsAt()?->format('H:i'),
                'location' => $day->location(),
                'meetingPointName' => $day->meetingPointName(),
                'meetingPointDescription' => $day->meetingPointDescription(),
            ], $this->days()),
        ];
    }
}
