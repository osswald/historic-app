<?php

declare(strict_types=1);

namespace App\Application\Poll;

use App\Application\InvalidRequest;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;
use App\Domain\Poll\PollKind;
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

    /** @param array<string, mixed> $payload */
    public function __invoke(MemberUser $actor, string $pollId, array $payload): Event
    {
        if (!$actor->isOrganizer()) {
            throw new AccessDeniedException('Eine Veranstaltung aus der Umfrage erzeugen darf nur ein Organisator.');
        }
        $poll = $this->catalog->visible($pollId, $actor);
        if ($poll->createdEventId() !== null) {
            throw new InvalidRequest(
                ['event' => 'Aus dieser Umfrage wurde bereits eine Veranstaltung erzeugt.'],
                'Aus dieser Umfrage wurde bereits eine Veranstaltung erzeugt.',
            );
        }

        $wanted = [];
        foreach ((array) ($payload['optionIds'] ?? []) as $value) {
            $id = trim((string) $value);
            if ($id !== '') {
                $wanted[$id] = true;
            }
        }
        $selected = [];
        foreach ($poll->options() as $option) {
            if (isset($wanted[$option->id()])) {
                $selected[] = $option;
                unset($wanted[$option->id()]);
            }
        }
        if ($selected === [] || $wanted !== []) {
            throw new InvalidRequest(['optionIds' => 'Bitte mindestens eine Option dieser Umfrage auswählen.']);
        }

        $now = $this->clock->now();
        $before = $poll->toAudit();
        $event = new Event(
            $poll->title(),
            $poll->clubValues(),
            $poll->kind() === PollKind::Depot ? ['Depottag'] : ['Terminumfrage'],
            EventStatus::Published,
            'Entstanden aus der Umfrage «'.$poll->title().'». Die Antworten der Umfrage gelten hier nicht.',
            null,
            $poll->location(),
            false,
            $now,
        );
        foreach ($selected as $index => $option) {
            $event->addDay(
                $option->occursOn(),
                $poll->kind() === PollKind::Depot ? null : $option->startsAt(),
                $poll->kind() === PollKind::Depot ? null : $option->endsAt(),
                $poll->location(),
                $option->daypart()?->label(),
                null,
                $index,
            );
        }
        $this->events->add($event);
        $poll->markAdopted($event->id(), $now);
        $this->audit->record($actor->getUserIdentifier(), 'event', $event->id(), 'create', null, $event->toAudit(), $now);
        $this->audit->record($actor->getUserIdentifier(), 'poll', $poll->id(), 'adopt', $before, $poll->toAudit(), $now);
        $this->entityManager->flush();

        return $event;
    }
}
