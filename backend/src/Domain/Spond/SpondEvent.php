<?php

declare(strict_types=1);

namespace App\Domain\Spond;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'spond_event')]
#[ORM\UniqueConstraint(name: 'spond_event_read', columns: ['read_id', 'spond_id'])]
class SpondEvent
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpondRead $read;

    #[ORM\Column(length: 80)]
    private string $spondId;

    #[ORM\Column(length: 300)]
    private string $heading;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $eventOn;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    /** @var list<array{spondId: string, name: string, description: string|null, accepted: int, declined: int, unanswered: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $options;

    /** @var Collection<int, SpondAnswer> */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: SpondAnswer::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $answers;

    /**
     * @param list<array{spondId: string, name: string, description: string|null, accepted: int, declined: int, unanswered: int}> $options
     */
    public function __construct(
        SpondRead $read,
        string $spondId,
        string $heading,
        \DateTimeImmutable $eventOn,
        ?string $description,
        ?string $comment,
        array $options,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->read = $read;
        $this->spondId = $spondId;
        $this->heading = $heading;
        $this->eventOn = $eventOn;
        $this->description = $description;
        $this->comment = $comment;
        $this->options = $options;
        $this->answers = new ArrayCollection();
    }

    public function spondId(): string
    {
        return $this->spondId;
    }

    public function heading(): string
    {
        return $this->heading;
    }

    public function eventOn(): \DateTimeImmutable
    {
        return $this->eventOn;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    /** @return list<array{spondId: string, name: string, description: string|null, accepted: int, declined: int, unanswered: int}> */
    public function options(): array
    {
        return $this->options;
    }

    public function addAnswer(string $spondId, SpondReply $reply, ?string $comment, ?string $matchedSub): void
    {
        $this->answers->add(new SpondAnswer($this, $spondId, $reply, $comment, $matchedSub));
    }

    /** @return list<SpondAnswer> */
    public function answers(): array
    {
        return $this->answers->getValues();
    }

    public function replyCount(SpondReply $reply): int
    {
        $count = 0;
        foreach ($this->answers as $answer) {
            if ($answer->reply() === $reply) {
                ++$count;
            }
        }

        return $count;
    }

    public function answerCommentCount(): int
    {
        $count = 0;
        foreach ($this->answers as $answer) {
            if ($answer->comment() !== null) {
                ++$count;
            }
        }

        return $count;
    }
}
