<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Helfer\ShowHelfer;
use App\Application\InvalidRequest;
use App\Application\NotFound;
use App\Application\ZurichClock;
use App\Domain\Event\Attendance;
use App\Domain\Event\EventDayRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;

final class RecordAttendance
{
    public function __construct(
        private EventDayRepository $days,
        private EventAccess $access,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
        private ShowHelfer $helfer,
    ) {
    }

    public function __invoke(MemberUser $actor, string $dayId, string $answer, ?string $comment): void
    {
        $day = $this->days->byId($dayId);
        if ($day === null || !$this->access->canSee($day->event(), $actor, $this->clock->today())) {
            throw new NotFound('Veranstaltungstag nicht gefunden.');
        }

        $attendance = Attendance::tryFrom($answer);
        if (!$attendance instanceof Attendance) {
            throw new InvalidRequest(['answer' => 'Bitte mit Ja oder Nein antworten.']);
        }

        $comment = trim((string) $comment);
        $comment = $comment === '' ? null : mb_substr($comment, 0, 2000);
        $sub = $actor->getUserIdentifier();
        $spondId = $actor->spondId();
        if ($spondId !== null && $day->releaseOtherSpondRow($sub, $spondId)) {
            $this->entityManager->flush();
        }
        $before = $day->responseFor($sub)?->toAudit();
        if ($before === null && $spondId !== null) {
            $before = $day->responseForSpond($spondId)?->toAudit();
        }
        if ($attendance === Attendance::No && $before !== null && ($before['answer'] ?? null) !== 'no' && $this->helfer->blocksDecline($actor, $day->event()->id())) {
            throw new InvalidRequest(['answer' => 'Eine verbindliche Helferschicht ist eingeteilt. Die Teilnahme bleibt auf Ja.']);
        }

        $now = $this->clock->now();
        $response = $day->answer($sub, $actor->displayName(), $attendance, $comment, $now, $spondId);
        if ($before === $response->toAudit()) {
            return;
        }

        $this->audit->record(
            $actor->getUserIdentifier(),
            'event_response',
            $response->id(),
            $before === null ? 'create' : 'update',
            $before,
            $response->toAudit(),
            $now,
            $actor->displayName(),
        );
        $this->entityManager->flush();
    }
}
