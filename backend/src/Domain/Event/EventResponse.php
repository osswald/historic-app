<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_response')]
#[ORM\UniqueConstraint(name: 'response_day_member', columns: ['event_day_id', 'auth0_sub'])]
#[ORM\UniqueConstraint(name: 'response_day_spond', columns: ['event_day_id', 'spond_member_id'])]
class EventResponse
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'responses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EventDay $eventDay;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $auth0Sub;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $spondMemberId;

    #[ORM\Column(length: 200)]
    private string $respondentName;

    #[ORM\Column(enumType: Attendance::class)]
    private Attendance $answer;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private int $revision;

    /** Eine Änderung in dieser App bleibt beim erneuten Lesen aus Spond stehen. */
    #[ORM\Column]
    private bool $editedInApp;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        EventDay $eventDay,
        ?string $auth0Sub,
        string $respondentName,
        Attendance $answer,
        ?string $comment,
        \DateTimeImmutable $at,
        ?string $spondMemberId = null,
        bool $editedInApp = false,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->eventDay = $eventDay;
        $this->auth0Sub = $auth0Sub !== '' ? $auth0Sub : null;
        $this->spondMemberId = $spondMemberId !== '' ? $spondMemberId : null;
        $this->respondentName = $respondentName;
        $this->answer = $answer;
        $this->comment = $comment;
        $this->revision = 1;
        $this->editedInApp = $editedInApp;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auth0Sub(): ?string
    {
        return $this->auth0Sub;
    }

    public function spondMemberId(): ?string
    {
        return $this->spondMemberId;
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

    public function editedInApp(): bool
    {
        return $this->editedInApp;
    }

    public function revise(Attendance $answer, ?string $comment, string $name, \DateTimeImmutable $at): bool
    {
        if ($this->answer === $answer && $this->comment === $comment && $this->respondentName === $name) {
            return false;
        }

        $this->answer = $answer;
        $this->comment = $comment;
        $this->respondentName = $name;
        $this->revision++;
        $this->updatedAt = $at;

        return true;
    }

    public function markEditedInApp(): void
    {
        $this->editedInApp = true;
    }

    /** Frischt Ja oder Nein aus Spond auf, solange hier niemand selbst geändert hat. */
    public function refreshImported(Attendance $answer, ?string $comment, ?string $name, \DateTimeImmutable $at): void
    {
        if ($this->editedInApp) {
            return;
        }
        if ($this->answer !== $answer || $this->comment !== $comment) {
            $this->answer = $answer;
            $this->comment = $comment;
            $this->revision++;
            $this->updatedAt = $at;
        }
        if ($name !== null && $name !== '' && $this->respondentName !== $name) {
            $this->respondentName = $name;
            $this->updatedAt = $at;
        }
    }

    public function rememberSpondMember(string $spondMemberId): void
    {
        if ($spondMemberId === '') {
            return;
        }
        if ($this->spondMemberId === null || $this->spondMemberId === '') {
            $this->spondMemberId = $spondMemberId;
        }
    }

    public function attachAccount(?string $sub): void
    {
        if ($sub === null || $sub === '') {
            return;
        }
        if ($this->auth0Sub === null || $this->auth0Sub === '') {
            $this->auth0Sub = $sub;
        }
    }

    public function releaseSpondMemberId(): void
    {
        $this->spondMemberId = null;
    }

    public function detachAccount(): void
    {
        $this->auth0Sub = null;
    }

    /**
     * Hängt die Zeile an das Konto mit dieser Spond-ID.
     * Der Abschluss läuft nach dem Löschen einer doppelten Zeile.
     *
     * @return \Closure(): void
     */
    public function linkAccount(string $sub): \Closure
    {
        $spondId = $this->spondMemberId ?? '';
        if ($spondId === '') {
            return static function (): void {
            };
        }

        return $this->eventDay->linkSpondAccount($spondId, $sub);
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'answer' => $this->answer->value,
            'comment' => $this->comment,
            'revision' => $this->revision,
            'respondentName' => $this->respondentName,
            'editedInApp' => $this->editedInApp,
            'spondMemberId' => $this->spondMemberId,
        ];
    }
}
