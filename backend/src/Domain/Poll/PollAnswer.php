<?php

declare(strict_types=1);

namespace App\Domain\Poll;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'poll_answer')]
#[ORM\UniqueConstraint(name: 'answer_poll_member', columns: ['poll_id', 'auth0_sub'])]
class PollAnswer
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Poll $poll;

    #[ORM\Column(length: 190)]
    private string $auth0Sub;

    #[ORM\Column(length: 200)]
    private string $respondentName;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $savedAt;

    public function __construct(
        Poll $poll,
        string $auth0Sub,
        string $respondentName,
        ?string $comment,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->poll = $poll;
        $this->auth0Sub = $auth0Sub;
        $this->respondentName = $respondentName;
        $this->comment = $comment;
        $this->savedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auth0Sub(): string
    {
        return $this->auth0Sub;
    }

    public function respondentName(): string
    {
        return $this->respondentName;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function savedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }

    public function revise(string $respondentName, ?string $comment, \DateTimeImmutable $now): void
    {
        $this->respondentName = $respondentName;
        $this->comment = $comment;
        $this->savedAt = $now;
    }
}
