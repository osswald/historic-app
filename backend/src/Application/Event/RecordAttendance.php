<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\InvalidRequest;
use App\Application\NotFound;
use App\Application\ZurichClock;
use App\Domain\Event\Attendance;
use App\Domain\Event\EventDayRepository;
use App\Domain\Event\EventStatus;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;

final class RecordAttendance
{
    public function __construct(
        private EventDayRepository $days,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    public function __invoke(MemberUser $actor, string $dayId, string $answer, ?string $comment): void
    {
        $day = $this->days->byId($dayId);
        if ($day === null || !$day->event()->isVisibleTo($actor->isOrganizer())) {
            throw new NotFound('Veranstaltungstag nicht gefunden.');
        }
        if ($day->event()->status() === EventStatus::Draft && !$actor->isOrganizer()) {
            throw new NotFound('Veranstaltungstag nicht gefunden.');
        }

        $attendance = Attendance::tryFrom($answer);
        if (!$attendance instanceof Attendance) {
            throw new InvalidRequest(['answer' => 'Bitte mit Ja oder Nein antworten.']);
        }

        $comment = trim((string) $comment);
        $comment = $comment === '' ? null : mb_substr($comment, 0, 2000);
        $before = $day->responseFor($actor->getUserIdentifier())?->toAudit();
        $now = $this->clock->now();
        $response = $day->answer($actor->getUserIdentifier(), $actor->displayName(), $attendance, $comment, $now);
        if ($before === $response->toAudit()) {
            return;
        }

        // Eine verbindliche Helferschicht sperrt den Wechsel von Ja auf Nein,
        // sobald die Helferplanung eine verlässliche Abfrage liefert.
        $this->audit->record(
            $actor->getUserIdentifier(),
            'event_response',
            $response->id(),
            $before === null ? 'create' : 'update',
            $before,
            $response->toAudit(),
            $now,
        );
        $this->entityManager->flush();
    }
}
