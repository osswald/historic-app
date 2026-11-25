<?php

declare(strict_types=1);

namespace App\Domain\Poll;

use App\Domain\Event\Club;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'poll')]
class Poll
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(enumType: PollKind::class)]
    private PollKind $kind;

    #[ORM\Column(enumType: PollStatus::class)]
    private PollStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $location;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $clubs;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadline;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $createdEventId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PollOption> */
    #[ORM\OneToMany(mappedBy: 'poll', targetEntity: PollOption::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'occursOn' => 'ASC'])]
    private Collection $options;

    /** @var Collection<int, PollAnswer> */
    #[ORM\OneToMany(mappedBy: 'poll', targetEntity: PollAnswer::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $answers;

    /**
     * @param list<string> $clubs
     */
    public function __construct(
        string $title,
        PollKind $kind,
        PollStatus $status,
        ?string $description,
        ?string $location,
        array $clubs,
        ?\DateTimeImmutable $deadline,
        \DateTimeImmutable $now,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->title = $title;
        $this->kind = $kind;
        $this->status = $status;
        $this->description = $description;
        $this->location = $location;
        $this->clubs = $clubs;
        $this->deadline = $deadline;
        $this->createdEventId = null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->options = new ArrayCollection();
        $this->answers = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function kind(): PollKind
    {
        return $this->kind;
    }

    public function status(): PollStatus
    {
        return $this->status;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function location(): ?string
    {
        return $this->location;
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
    public function clubValues(): array
    {
        return $this->clubs;
    }

    public function deadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function createdEventId(): ?string
    {
        return $this->createdEventId;
    }

    public function acceptsAnswers(\DateTimeImmutable $now): bool
    {
        return $this->status === PollStatus::Open;
    }

    public function deadlinePassed(\DateTimeImmutable $now): bool
    {
        return $this->deadline instanceof \DateTimeImmutable && $now > $this->deadline;
    }

    /** @return list<PollOption> */
    public function options(): array
    {
        $options = $this->options->toArray();
        usort(
            $options,
            fn (PollOption $a, PollOption $b): int => [$a->sortOrder(), $a->occursOn()->format('Y-m-d'), $a->daypart()?->order() ?? 0]
                <=> [$b->sortOrder(), $b->occursOn()->format('Y-m-d'), $b->daypart()?->order() ?? 0],
        );

        return array_values($options);
    }

    public function option(string $id): ?PollOption
    {
        foreach ($this->options as $option) {
            if ($option->id() === $id) {
                return $option;
            }
        }

        return null;
    }

    public function answerFor(string $sub): ?PollAnswer
    {
        foreach ($this->answers as $answer) {
            if ($answer->auth0Sub() === $sub) {
                return $answer;
            }
        }

        return null;
    }

    public function memberState(string $sub): string
    {
        if (!$this->answerFor($sub) instanceof PollAnswer) {
            return 'unanswered';
        }
        foreach ($this->options() as $option) {
            if (!$option->currentVote($sub) instanceof PollVote) {
                return 'supplement';
            }
        }

        return 'complete';
    }

    public function sortKey(): string
    {
        $option = $this->options()[0] ?? null;

        return $option instanceof PollOption ? $option->occursOn()->format('Y-m-d') : '9999-12-31';
    }

    /**
     * @param list<string> $clubs
     */
    public function change(
        string $title,
        PollStatus $status,
        ?string $description,
        ?string $location,
        array $clubs,
        ?\DateTimeImmutable $deadline,
        \DateTimeImmutable $now,
    ): void {
        $this->title = $title;
        $this->status = $status;
        $this->description = $description;
        $this->location = $location;
        $this->clubs = $clubs;
        $this->deadline = $deadline;
        $this->updatedAt = $now;
    }

    public function addOption(
        \DateTimeImmutable $occursOn,
        ?Daypart $daypart,
        ?\DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        int $sortOrder,
        ?string $id = null,
    ): PollOption {
        $option = new PollOption($this, $occursOn, $daypart, $startsAt, $endsAt, $sortOrder, $id);
        $this->options->add($option);

        return $option;
    }

    /** @param list<string> $idsToKeep */
    public function retainOptions(array $idsToKeep): void
    {
        foreach ($this->options->toArray() as $option) {
            if (!in_array($option->id(), $idsToKeep, true)) {
                $this->options->removeElement($option);
            }
        }
    }

    public function recordAnswer(string $sub, string $name, ?string $comment, \DateTimeImmutable $now): PollAnswer
    {
        $existing = $this->answerFor($sub);
        if ($existing instanceof PollAnswer) {
            $existing->revise($name, $comment, $now);

            return $existing;
        }

        $answer = new PollAnswer($this, $sub, $name, $comment, $now);
        $this->answers->add($answer);

        return $answer;
    }

    public function markAdopted(string $eventId, \DateTimeImmutable $now): void
    {
        $this->createdEventId = $eventId;
        $this->updatedAt = $now;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'title' => $this->title,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'description' => $this->description,
            'location' => $this->location,
            'clubs' => $this->clubs,
            'deadline' => $this->deadline?->format(DATE_ATOM),
            'createdEventId' => $this->createdEventId,
            'options' => array_map(static fn (PollOption $option): array => $option->toAudit(), $this->options()),
        ];
    }
}
