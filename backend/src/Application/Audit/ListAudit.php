<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Event\KnownPeople;
use App\Application\Gastronomy\GastroAccess;
use App\Application\NotFound;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventResponseRepository;
use App\Domain\Fleet\VehicleRepository;
use App\Domain\Gastronomy\GastroPlanRepository;
use App\Domain\Poll\Poll;
use App\Domain\Poll\PollRepository;
use App\Domain\Ride\RideRepository;
use App\Security\MemberUser;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ListAudit
{
    public function __construct(
        private AuditEntryRepository $entries,
        private EventRepository $events,
        private EventResponseRepository $responses,
        private PollRepository $polls,
        private RideRepository $rides,
        private VehicleRepository $vehicles,
        private GastroPlanRepository $plans,
        private GastroAccess $gastroAccess,
        private KnownPeople $people,
        private AuditChanges $changes,
        private AuditActor $actors,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(MemberUser $actor, string $entityType, string $entityId): array
    {
        $type = trim($entityType);
        $id = trim($entityId);
        $this->assertCanRead($actor, $type, $id);
        $knownNames = $this->knownNames($actor);
        $rows = $type === 'event'
            ? $this->entries->forEvent($id, $this->responses->idsForEvent($id))
            : $this->entries->forResources($type, [$id]);

        return array_map(fn (AuditEntry $entry): array => [
            'id' => $entry->id(),
            'occurredAt' => AuditWhen::format($entry->occurredAt()),
            'actor' => $this->actors->label($entry->actorName(), $entry->actorSub(), $knownNames[$entry->actorSub()] ?? null) ?? '',
            'action' => $entry->action(),
            'actionLabel' => $this->actionLabel($entry->action(), $entry->entityType(), $type === 'event'),
            'summary' => $this->summary($entry),
            'changes' => $this->changes->between($entry->beforeData(), $entry->afterData()),
        ], $rows);
    }

    /** @return array<string, string> */
    private function knownNames(MemberUser $actor): array
    {
        $names = [];
        foreach ($this->people->forMember($actor) as $person) {
            $name = trim($person['name']);
            if ($person['sub'] !== '' && $name !== '') {
                $names[$person['sub']] = $name;
            }
        }
        $own = trim($actor->displayName());
        $sub = trim($actor->getUserIdentifier());
        if ($sub !== '' && $own !== '') {
            $names[$sub] = $own;
        }

        return $names;
    }

    private function assertCanRead(MemberUser $actor, string $type, string $id): void
    {
        $allowed = match ($type) {
            'event' => $this->eventReadable($actor, $id),
            'poll' => $this->pollReadable($actor, $id),
            'ride' => $this->rides->get($id) !== null && $actor->canPlanRides(),
            'vehicle' => $this->vehicles->get($id) !== null && $actor->canEditFleet(),
            'gastro_plan' => $this->canReadGastro($actor, $id),
            'battery_charge', 'meter_reading' => $actor->canEditFleet(),
            default => false,
        };
        if ($allowed) {
            return;
        }
        $known = match ($type) {
            'event' => $this->events->byId($id) !== null,
            'poll' => $this->polls->byId($id) !== null,
            'ride' => $this->rides->get($id) !== null,
            'vehicle' => $this->vehicles->get($id) !== null,
            'gastro_plan' => $this->plans->get($id) !== null,
            default => false,
        };
        if (!$known) {
            throw new NotFound('Der Änderungsverlauf wurde nicht gefunden.');
        }
        throw new AccessDeniedException('Den Änderungsverlauf sehen Administratoren und die Personen, die diese Angaben bearbeiten dürfen.');
    }

    private function eventReadable(MemberUser $actor, string $id): bool
    {
        $event = $this->events->byId($id);
        if (!$event instanceof Event) {
            return false;
        }
        if ($actor->isAdmin()) {
            return true;
        }

        return $actor->isOrganizer() && $actor->sharesClub($event->clubs());
    }

    private function pollReadable(MemberUser $actor, string $id): bool
    {
        $poll = $this->polls->byId($id);
        if (!$poll instanceof Poll) {
            return false;
        }
        if ($actor->isAdmin()) {
            return true;
        }

        return $actor->isOrganizer() && $actor->sharesClub($poll->clubs());
    }

    private function canReadGastro(MemberUser $actor, string $id): bool
    {
        $plan = $this->plans->get($id);

        return $plan !== null && $this->gastroAccess->canEdit($actor, $plan);
    }

    private function actionLabel(string $action, string $entityType, bool $inEvent): string
    {
        $specific = match ($action) {
            'file_added' => 'Datei hinzugefügt',
            'folder_linked' => 'Nextcloud-Ordner verknüpft',
            'folder_created' => 'Nextcloud-Ordner angelegt',
            'task_list_linked' => 'Aufgabenliste verknüpft',
            'helfer_linked' => 'Helferplanung verknüpft',
            'helfer_created' => 'Helferplanung angelegt',
            default => null,
        };
        if ($specific !== null) {
            return $specific;
        }
        $base = match ($action) {
            'create' => 'Angelegt',
            'update' => 'Geändert',
            'delete' => 'Gelöscht',
            'adopt' => 'Übernommen',
            default => 'Geändert',
        };
        if (!$inEvent) {
            return $base;
        }
        $context = match ($entityType) {
            'ride' => 'Fahrt',
            'event_ride_plan' => 'Fahrtenplanung',
            'event_response' => 'Teilnahme',
            'gastro_plan' => 'Gastronomie',
            default => null,
        };

        return $context === null ? $base : $context.' · '.$base;
    }

    private function summary(AuditEntry $entry): string
    {
        $after = $entry->afterData() ?? $entry->beforeData() ?? [];
        $title = $after['title'] ?? $after['name'] ?? null;
        if (is_string($title) && $title !== '') {
            return $title;
        }
        if ($entry->action() === 'delete') {
            return 'Eintrag entfernt';
        }

        return $this->actionLabel($entry->action(), $entry->entityType(), false);
    }
}
