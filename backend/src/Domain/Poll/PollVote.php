<?php

declare(strict_types=1);

namespace App\Domain\Poll;

use App\Domain\Event\Attendance;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'poll_vote')]
#[ORM\UniqueConstraint(name: 'vote_option_member', columns: ['poll_option_id', 'auth0_sub'])]
class PollVote
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'votes')]
    #[ORM\JoinColumn(name: 'poll_option_id', nullable: false, onDelete: 'CASCADE')]
    private PollOption $option;

    #[ORM\Column(length: 190)]
    private string $auth0Sub;

    #[ORM\Column(enumType: Attendance::class)]
    private Attendance $answer;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PollOption $option,
        string $auth0Sub,
        Attendance $answer,
        int $revision,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->option = $option;
        $this->auth0Sub = $auth0Sub;
        $this->answer = $answer;
        $this->revision = $revision;
        $this->updatedAt = $now;
    }

    public function auth0Sub(): string
    {
        return $this->auth0Sub;
    }

    public function answer(): Attendance
    {
        return $this->answer;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function revise(Attendance $answer, int $revision, \DateTimeImmutable $now): void
    {
        $this->answer = $answer;
        $this->revision = $revision;
        $this->updatedAt = $now;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'optionId' => $this->option->id(),
            'answer' => $this->answer->value,
            'revision' => $this->revision,
        ];
    }
}
