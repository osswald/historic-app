<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\NotFound;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;
use App\Security\MemberUser;

final class EventCatalog
{
    public function __construct(
        private EventRepository $events,
        private EventAccess $access,
        private ZurichClock $clock,
    ) {
    }

    /** @return list<Event> */
    public function visibleTo(MemberUser $user): array
    {
        $today = $this->clock->today();
        $events = array_values(array_filter(
            $this->events->all(),
            fn (Event $event): bool => $this->access->canSee($event, $user, $today),
        ));
        usort($events, fn (Event $a, Event $b): int => $a->sortKey() <=> $b->sortKey());

        return $events;
    }

    public function visible(string $id, MemberUser $user): Event
    {
        $event = $this->events->byId($id);
        if (!$event instanceof Event || !$this->access->canSee($event, $user, $this->clock->today())) {
            throw new NotFound('Veranstaltung nicht gefunden.');
        }

        return $event;
    }

    /** @return list<Event> */
    public function upcoming(MemberUser $user, \DateTimeImmutable $today): array
    {
        $todayKey = $today->format('Y-m-d');

        return array_values(array_filter(
            $this->visibleTo($user),
            static function (Event $event) use ($todayKey): bool {
                if ($event->status() !== EventStatus::Published) {
                    return false;
                }
                foreach ($event->days() as $day) {
                    if ($day->occursOn()->format('Y-m-d') >= $todayKey) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
