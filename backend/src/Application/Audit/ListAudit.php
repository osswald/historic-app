<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Gastronomy\GastroAccess;
use App\Application\NotFound;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
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
        private PollRepository $polls,
        private RideRepository $rides,
        private VehicleRepository $vehicles,
        private GastroPlanRepository $plans,
        private GastroAccess $gastroAccess,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(MemberUser $actor, string $entityType, string $entityId): array
    {
        $type = trim($entityType);
        $id = trim($entityId);
        $this->assertCanRead($actor, $type, $id);
        $zone = new \DateTimeZone('Europe/Zurich');

        return array_map(fn (AuditEntry $entry): array => [
            'id' => $entry->id(),
            'occurredAt' => $entry->occurredAt()->setTimezone($zone)->format('d.m.Y H:i'),
            'actor' => $entry->actorSub(),
            'action' => $entry->action(),
            'actionLabel' => $this->actionLabel($entry->action()),
            'summary' => $this->summary($entry),
        ], $this->entries->forResources($type, [$id]));
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

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'create' => 'Angelegt',
            'update' => 'Geändert',
            'delete' => 'Gelöscht',
            'adopt' => 'Übernommen',
            default => 'Geändert',
        };
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

        return $this->actionLabel($entry->action());
    }
}
