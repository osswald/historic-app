<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventStatus;
use App\Domain\Event\EventRepository;
use App\Domain\Event\MeetingPoint;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Kopiert die Planung eines Anlasses. Teilnahmeantworten werden nicht übernommen. */
final class CopyEvent
{
    public function __construct(
        private EventCatalog $catalog,
        private EventRepository $events,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    public function __invoke(MemberUser $actor, string $eventId): Event
    {
        if (!$actor->isOrganizer()) {
            throw new AccessDeniedException('Veranstaltungen kopieren darf nur ein Organisator.');
        }
        $source = $this->catalog->visible($eventId, $actor);
        $now = $this->clock->now();
        $copy = new Event(
            $this->title($source->title()),
            array_map(static fn ($club) => $club->value, $source->clubs()),
            $source->categories(),
            EventStatus::Draft,
            $source->internalDescription(),
            $source->publicDescription(),
            $source->location(),
            $source->publishToWebsite(),
            $now,
        );
        foreach ($source->days() as $index => $day) {
            $created = $copy->addDay(
                $day->occursOn(),
                $day->startsAt(),
                $day->endsAt(),
                $day->location(),
                $index,
            );
            $created->replaceMeetingPoints(array_map(static fn (MeetingPoint $point): array => [
                'place' => $point->place(),
                'meetsAt' => $point->meetsAt(),
                'description' => $point->description(),
            ], $day->meetingPoints()));
        }
        $copy->replaceAssignedVehicles(array_map(static fn ($link): array => [
            'vehicle' => $link->vehicle(),
            'rentalStatus' => $link->rentalStatus(),
        ], $source->assignedVehicles()));
        // V-11: Gruppen sind eine Einstellung. Namentliche Ein- und Ausschlüsse sind persönliche Zuordnungen und werden nicht mitkopiert.
        $copy->replaceInvitations($source->invitedGroups(), [], []);
        $this->events->add($copy);
        $this->audit->record($actor->getUserIdentifier(), 'event', $copy->id(), 'create', null, $copy->toAudit(), $now);
        $this->entityManager->flush();

        return $copy;
    }

    private function title(string $title): string
    {
        $suffix = ' (Kopie)';
        $room = 200 - mb_strlen($suffix);
        if (mb_strlen($title) > $room) {
            $title = rtrim(mb_substr($title, 0, $room));
        }

        return $title.$suffix;
    }
}
