<?php

declare(strict_types=1);

namespace App\Domain\Poll;

use App\Domain\Event\Attendance;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'poll_option')]
class PollOption
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'options')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Poll $poll;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $occursOn;

    #[ORM\Column(nullable: true, enumType: Daypart::class)]
    private ?Daypart $daypart;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt;

    #[ORM\Column]
    private int $sortOrder;

    #[ORM\Column]
    private int $revision = 1;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $adoptedEventId = null;

    /** @var Collection<int, PollVote> */
    #[ORM\OneToMany(mappedBy: 'option', targetEntity: PollVote::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $votes;

    public function __construct(
        Poll $poll,
        \DateTimeImmutable $occursOn,
        ?Daypart $daypart,
        ?\DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        int $sortOrder,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->poll = $poll;
        $this->occursOn = $occursOn;
        $this->daypart = $daypart;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->sortOrder = $sortOrder;
        $this->votes = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function occursOn(): \DateTimeImmutable
    {
        return $this->occursOn;
    }

    public function daypart(): ?Daypart
    {
        return $this->daypart;
    }

    public function startsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function adoptedEventId(): ?string
    {
        return $this->adoptedEventId;
    }

    public function isAdopted(): bool
    {
        return $this->adoptedEventId !== null;
    }

    public function adopt(string $eventId): void
    {
        $this->adoptedEventId = $eventId;
    }

    public function revise(
        \DateTimeImmutable $occursOn,
        ?Daypart $daypart,
        ?\DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        int $sortOrder,
    ): void {
        $changed = $this->occursOn->format('Y-m-d') !== $occursOn->format('Y-m-d')
            || $this->daypart !== $daypart
            || $this->clock($this->startsAt) !== $this->clock($startsAt)
            || $this->clock($this->endsAt) !== $this->clock($endsAt);
        $this->occursOn = $occursOn;
        $this->daypart = $daypart;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->sortOrder = $sortOrder;
        if ($changed) {
            ++$this->revision;
        }
    }

    public function voteFor(string $sub): ?PollVote
    {
        foreach ($this->votes as $vote) {
            if ($vote->auth0Sub() === $sub) {
                return $vote;
            }
        }

        return null;
    }

    public function currentVote(string $sub): ?PollVote
    {
        $vote = $this->voteFor($sub);
        if (!$vote instanceof PollVote || $vote->revision() !== $this->revision) {
            return null;
        }

        return $vote;
    }

    public function cast(string $sub, Attendance $answer, \DateTimeImmutable $now): PollVote
    {
        $existing = $this->voteFor($sub);
        if ($existing instanceof PollVote) {
            $existing->revise($answer, $this->revision, $now);

            return $existing;
        }

        $vote = new PollVote($this, $sub, $answer, $this->revision, $now);
        $this->votes->add($vote);

        return $vote;
    }

    /** @return list<PollVote> */
    public function currentVotes(): array
    {
        $votes = [];
        foreach ($this->votes as $vote) {
            if ($vote->revision() === $this->revision) {
                $votes[] = $vote;
            }
        }

        return $votes;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->occursOn->format('Y-m-d'),
            'daypart' => $this->daypart?->value,
            'startsAt' => $this->startsAt?->format('H:i'),
            'endsAt' => $this->endsAt?->format('H:i'),
            'revision' => $this->revision,
            'adoptedEventId' => $this->adoptedEventId,
        ];
    }

    private function clock(?\DateTimeImmutable $time): ?string
    {
        return $time?->format('H:i');
    }
}
