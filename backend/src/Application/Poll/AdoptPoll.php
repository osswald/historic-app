<?php

declare(strict_types=1);

namespace App\Application\Poll;

use App\Application\InvalidRequest;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;
use App\Domain\Poll\Daypart;
use App\Domain\Poll\PollKind;
use App\Domain\Poll\PollOption;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AdoptPoll
{
    public function __construct(
        private PollCatalog $catalog,
        private EventRepository $events,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<Event>
     */
    public function __invoke(MemberUser $actor, string $pollId, array $payload): array
    {
        if (!$actor->isOrganizer()) {
            throw new AccessDeniedException('Eine Veranstaltung aus der Umfrage erzeugen darf nur ein Organisator.');
        }
        $poll = $this->catalog->visible($pollId, $actor);
        $choices = $this->choices($poll, $payload);
        $now = $this->clock->now();
        $created = [];
        foreach ($choices as $choice) {
            $option = $choice['option'];
            $before = $poll->toAudit();
            $times = $this->times($poll->kind(), $option);
            $event = new Event(
                $this->title($poll->title(), $option),
                $poll->clubValues(),
                $poll->kind() === PollKind::Depot ? ['Depottag'] : ['Terminumfrage'],
                $choice['status'],
                'Entstanden aus der Umfrage «'.$poll->title().'». Die Antworten der Umfrage gelten hier nicht.',
                null,
                $poll->location(),
                false,
                $now,
            );
            $event->addDay(
                $option->occursOn(),
                $times['startsAt'],
                $times['endsAt'],
                $poll->location(),
                0,
            );
            $this->events->add($event);
            $option->adopt($event->id());
            $this->audit->record($actor->getUserIdentifier(), 'event', $event->id(), 'create', null, $event->toAudit(), $now, $actor->displayName());
            $this->audit->record($actor->getUserIdentifier(), 'poll', $poll->id(), 'adopt', $before, $poll->toAudit(), $now, $actor->displayName());
            $created[] = $event;
        }
        $this->entityManager->flush();

        return $created;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{option: PollOption, status: EventStatus}>
     */
    private function choices(\App\Domain\Poll\Poll $poll, array $payload): array
    {
        $rows = $payload['adoptions'] ?? null;
        if (!is_array($rows) || $rows === []) {
            throw new InvalidRequest(['adoptions' => 'Bitte mindestens einen Vorschlag auswählen.']);
        }
        $choices = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidRequest(['adoptions.'.$index => 'Der Vorschlag ist ungültig.']);
            }
            $id = trim((string) ($row['optionId'] ?? ''));
            $option = $poll->option($id);
            if (!$option instanceof PollOption) {
                throw new InvalidRequest(['adoptions.'.$index.'.optionId' => 'Bitte einen Vorschlag dieser Umfrage auswählen.']);
            }
            if (isset($seen[$id])) {
                throw new InvalidRequest(['adoptions.'.$index.'.optionId' => 'Dieser Vorschlag ist doppelt ausgewählt.']);
            }
            if ($option->isAdopted()) {
                throw new InvalidRequest(['adoptions.'.$index.'.optionId' => 'Dieser Vorschlag wurde bereits übernommen.']);
            }
            $status = EventStatus::tryFrom(trim((string) ($row['status'] ?? '')));
            if ($status !== EventStatus::Draft && $status !== EventStatus::Published) {
                throw new InvalidRequest(['adoptions.'.$index.'.status' => 'Bitte Entwurf oder Veröffentlicht wählen.']);
            }
            $seen[$id] = true;
            $choices[] = ['option' => $option, 'status' => $status];
        }

        return $choices;
    }

    /** @return array{startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable} */
    private function times(PollKind $kind, PollOption $option): array
    {
        if ($kind === PollKind::Depot) {
            $part = $option->daypart() ?? Daypart::Morning;

            return [
                'startsAt' => $this->clock($part->standardStart()),
                'endsAt' => $this->clock($part->standardEnd()),
            ];
        }

        return ['startsAt' => $option->startsAt(), 'endsAt' => $option->endsAt()];
    }

    private function clock(string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);
        if (!$time instanceof \DateTimeImmutable) {
            throw new \LogicException('Die Standardzeit ist ungültig.');
        }

        return $time;
    }

    private function title(string $title, PollOption $option): string
    {
        $suffix = ' · '.$option->occursOn()->format('d.m.Y');
        if ($option->daypart() instanceof Daypart) {
            $suffix .= ' '.$option->daypart()->label();
        }
        $room = 200 - mb_strlen($suffix);
        if (mb_strlen($title) > $room) {
            $title = rtrim(mb_substr($title, 0, max(1, $room)));
        }

        return $title.$suffix;
    }
}
