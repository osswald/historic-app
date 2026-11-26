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

    public function responseForSpond(string $spondMemberId): ?EventResponse
    {
        foreach ($this->responses as $response) {
            if ($response->spondMemberId() === $spondMemberId) {
                return $response;
            }
        }

        return null;
    }

    public function removeResponse(EventResponse $response): void
    {
        $this->responses->removeElement($response);
    }

    /** @return list<EventResponse> */
    public function responses(): array
    {
        return array_values($this->responses->toArray());
    }

    public function answer(string $sub, string $name, Attendance $answer, ?string $comment, \DateTimeImmutable $at, ?string $spondMemberId = null): EventResponse
    {
        $existing = $this->responseFor($sub);
        if (!$existing instanceof EventResponse && $spondMemberId !== null && $spondMemberId !== '') {
            $existing = $this->responseForSpond($spondMemberId);
        }
        if ($existing instanceof EventResponse) {
            $existing->attachAccount($sub);
            if ($spondMemberId !== null) {
                $existing->rememberSpondMember($spondMemberId);
            }
            $existing->revise($answer, $comment, $name, $at);
            $existing->markEditedInApp();

            return $existing;
        }

        $response = new EventResponse($this, $sub, $name, $answer, $comment, $at, $spondMemberId, true);
        $this->responses->add($response);

        return $response;
    }

    /**
     * Übernimmt eine Spond-Antwort auf diesen Tag.
     * Der Abschluss setzt die Spond-ID erst, nachdem eine ersetzte Zeile gelöscht werden kann.
     *
     * @return \Closure(): void
     */
    public function importSpondReply(
        string $spondMemberId,
        ?string $auth0Sub,
        ?string $name,
        ?Attendance $attendance,
        ?string $comment,
        \DateTimeImmutable $at,
    ): \Closure {
        $bySpond = $this->responseForSpond($spondMemberId);
        $bySub = $auth0Sub !== null && $auth0Sub !== '' ? $this->responseFor($auth0Sub) : null;

        if (!$attendance instanceof Attendance) {
            if ($bySpond instanceof EventResponse && !$bySpond->editedInApp()) {
                $this->discard($bySpond);
            }

            return static function (): void {
            };
        }

        $drop = null;
        $keeper = $bySpond ?? $bySub;
        if ($bySpond instanceof EventResponse && $bySub instanceof EventResponse && $bySpond !== $bySub) {
            if ($bySub->editedInApp()) {
                $keeper = $bySub;
                $drop = $bySpond;
            } else {
                $keeper = $bySpond;
                $drop = $bySub;
            }
            $this->discard($drop);
        }

        $deferIdentity = $drop instanceof EventResponse;
        if (!$keeper instanceof EventResponse) {
            $keeper = new EventResponse(
                $this,
                $auth0Sub !== '' ? $auth0Sub : null,
                $name ?? 'Mitglied',
                $attendance,
                $comment,
                $at,
                $spondMemberId,
                false,
            );
            $this->responses->add($keeper);
        } elseif (!$keeper->editedInApp()) {
            $keeper->refreshImported($attendance, $comment, $name, $at);
        }

        if (!$deferIdentity) {
            $keeper->rememberSpondMember($spondMemberId);
            $keeper->attachAccount($auth0Sub);
        }

        return static function () use ($keeper, $spondMemberId, $auth0Sub): void {
            $keeper->rememberSpondMember($spondMemberId);
            $keeper->attachAccount($auth0Sub);
        };
    }

    /**
     * @return \Closure(): void
     */
    public function linkSpondAccount(string $spondMemberId, string $sub): \Closure
    {
        $imported = $this->responseForSpond($spondMemberId);
        if (!$imported instanceof EventResponse) {
            return static function (): void {
            };
        }
        $local = $this->responseFor($sub);
        if (!$local instanceof EventResponse || $local === $imported) {
            $imported->attachAccount($sub);

            return static function () use ($imported, $sub): void {
                $imported->attachAccount($sub);
            };
        }
        if ($local->editedInApp() || !$imported->editedInApp()) {
            $this->discard($imported);

            return static function () use ($local, $spondMemberId, $sub): void {
                $local->rememberSpondMember($spondMemberId);
                $local->attachAccount($sub);
            };
        }
        $this->discard($local);

        return static function () use ($imported, $spondMemberId, $sub): void {
            $imported->rememberSpondMember($spondMemberId);
            $imported->attachAccount($sub);
        };
    }

    /**
     * Entfernt die andere Spond-Zeile, bevor das Konto dieselbe ID übernimmt.
     */
    public function releaseOtherSpondRow(string $sub, string $spondMemberId): bool
    {
        $bySub = $this->responseFor($sub);
        $bySpond = $this->responseForSpond($spondMemberId);
        if (!$bySub instanceof EventResponse || !$bySpond instanceof EventResponse || $bySub === $bySpond) {
            return false;
        }
        $this->discard($bySpond);

        return true;
    }

    private function discard(EventResponse $response): void
    {
        $response->releaseSpondMemberId();
        $response->detachAccount();
        $this->responses->removeElement($response);
    }
}
