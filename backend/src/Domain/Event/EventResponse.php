<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_response')]
#[ORM\UniqueConstraint(name: 'response_day_member', columns: ['event_day_id', 'auth0_sub'])]
class EventResponse
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'responses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EventDay $eventDay;

    #[ORM\Column(length: 190)]
    private string $auth0Sub;

    #[ORM\Column(length: 200)]
    private string $respondentName;

    #[ORM\Column(enumType: Attendance::class)]
    private Attendance $answer;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        EventDay $eventDay,
        string $auth0Sub,
        string $respondentName,
        Attendance $answer,
        ?string $comment,
        \DateTimeImmutable $at,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->eventDay = $eventDay;
        $this->auth0Sub = $auth0Sub;
        $this->respondentName = $respondentName;
        $this->answer = $answer;
        $this->comment = $comment;
        $this->revision = 1;
        $this->createdAt = $at;
        $this->updatedAt = $at;
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

    public function answer(): Attendance
    {
        return $this->answer;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function revise(Attendance $answer, ?string $comment, string $name, \DateTimeImmutable $at): bool
    {
        if ($this->answer === $answer && $this->comment === $comment) {
            return false;
        }

        $this->answer = $answer;
        $this->comment = $comment;
        $this->respondentName = $name;
        $this->revision++;
        $this->updatedAt = $at;

        return true;
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'answer' => $this->answer->value,
            'comment' => $this->comment,
            'revision' => $this->revision,
            'respondentName' => $this->respondentName,
        ];
    }
}
