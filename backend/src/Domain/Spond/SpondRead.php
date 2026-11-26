<?php

declare(strict_types=1);

namespace App\Domain\Spond;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'spond_read')]
class SpondRead
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 200)]
    private string $groupName;

    #[ORM\Column(length: 80)]
    private string $groupSpondId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $readAt;

    /** @var Collection<int, SpondPerson> */
    #[ORM\OneToMany(mappedBy: 'read', targetEntity: SpondPerson::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $people;

    /** @var Collection<int, SpondEvent> */
    #[ORM\OneToMany(mappedBy: 'read', targetEntity: SpondEvent::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $events;

    public function __construct(string $groupName, string $groupSpondId, \DateTimeImmutable $readAt)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->groupName = $groupName;
        $this->groupSpondId = $groupSpondId;
        $this->readAt = $readAt;
        $this->people = new ArrayCollection();
        $this->events = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function groupName(): string
    {
        return $this->groupName;
    }

    public function groupSpondId(): string
    {
        return $this->groupSpondId;
    }

    public function readAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }

    public function addPerson(string $spondId, ?string $matchedSub, ?string $displayName = null): void
    {
        $this->people->add(new SpondPerson($this, $spondId, $matchedSub, $displayName));
    }

    /**
     * @param list<array{spondId: string, name: string, description: string|null, accepted: int, declined: int, unanswered: int}> $options
     */
    public function addEvent(
        string $spondId,
        string $heading,
        \DateTimeImmutable $eventOn,
        ?string $description,
        ?string $comment,
        array $options,
    ): SpondEvent {
        $event = new SpondEvent($this, $spondId, $heading, $eventOn, $description, $comment, $options);
        $this->events->add($event);

        return $event;
    }

    /** @return list<SpondPerson> */
    public function people(): array
    {
        return $this->people->getValues();
    }

    /** @return list<SpondEvent> */
    public function events(): array
    {
        return $this->events->getValues();
    }

    public function earliestOn(): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($this->events as $event) {
            if (!$earliest instanceof \DateTimeImmutable || $event->eventOn() < $earliest) {
                $earliest = $event->eventOn();
            }
        }

        return $earliest;
    }

    public function latestOn(): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($this->events as $event) {
            if (!$latest instanceof \DateTimeImmutable || $event->eventOn() > $latest) {
                $latest = $event->eventOn();
            }
        }

        return $latest;
    }

    public function replyCount(SpondReply $reply): int
    {
        $count = 0;
        foreach ($this->events as $event) {
            $count += $event->replyCount($reply);
        }

        return $count;
    }

    public function optionCount(): int
    {
        $count = 0;
        foreach ($this->events as $event) {
            $count += count($event->options());
        }

        return $count;
    }

    public function storedIdCount(): int
    {
        $ids = [];
        foreach ($this->people as $person) {
            $ids[$person->spondId()] = true;
        }
        foreach ($this->events as $event) {
            foreach ($event->answers() as $answer) {
                $ids[$answer->spondId()] = true;
            }
        }

        return count($ids);
    }

    public function matchedCount(): int
    {
        $ids = [];
        foreach ($this->people as $person) {
            if ($person->matchedSub() !== null) {
                $ids[$person->spondId()] = true;
            }
        }
        foreach ($this->events as $event) {
            foreach ($event->answers() as $answer) {
                if ($answer->matchedSub() !== null) {
                    $ids[$answer->spondId()] = true;
                }
            }
        }

        return count($ids);
    }

    public function answerCommentCount(): int
    {
        $count = 0;
        foreach ($this->events as $event) {
            $count += $event->answerCommentCount();
        }

        return $count;
    }
}
