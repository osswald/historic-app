<?php

declare(strict_types=1);

namespace App\Application\Ride;

use App\Application\Event\EventCatalog;
use App\Application\InvalidRequest;
use App\Application\ZurichClock;
use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Ride\DayOffset;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideRepository;
use App\Domain\Ride\RideWindow;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SaveRides
{
    public function __construct(
        private EventCatalog $events,
        private RideRepository $rides,
        private ClubVehicleOverlap $overlap,
        private RidePresenter $presenter,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{canEdit: bool, warnings: list<string>, rides: list<array<string, mixed>>}
     */
    public function replace(MemberUser $actor, string $eventId, array $payload): array
    {
        if (!$actor->canPlanRides()) {
            throw new AccessDeniedException('Fahrten bearbeiten dürfen Organisatoren und die Fahrtenplanung.');
        }

        $event = $this->events->visible($eventId, $actor);
        $input = $this->normalize($event, $payload);
        $existing = [];
        foreach ($this->rides->forEvent($event->id()) as $ride) {
            $existing[$ride->id()] = $ride;
        }

        $kept = [];
        $saved = [];
        /** @var list<array{ride: Ride, before: array<string, mixed>}> $updates */
        $updates = [];
        /** @var list<Ride> $creates */
        $creates = [];
        foreach ($input as $row) {
            $current = $row['id'] !== null ? ($existing[$row['id']] ?? null) : null;
            if ($current instanceof Ride) {
                $before = $current->toAudit();
                $current->revise(
                    $row['day'],
                    $row['departureTime'],
                    $row['departureOffset'],
                    $row['arrivalTime'],
                    $row['arrivalOffset'],
                    $row['vehicleLabel'],
                    $row['clubVehicle'],
                    $row['internalNote'],
                    $row['sortOrder'],
                );
                $updates[] = ['ride' => $current, 'before' => $before];
                $saved[] = $current;
                $kept[$current->id()] = true;
                continue;
            }

            $created = new Ride(
                $event,
                $row['day'],
                $row['departureTime'],
                $row['departureOffset'],
                $row['arrivalTime'],
                $row['arrivalOffset'],
                $row['vehicleLabel'],
                $row['clubVehicle'],
                $row['internalNote'],
                $row['sortOrder'],
            );
            $this->rides->add($created);
            $creates[] = $created;
            $saved[] = $created;
        }

        $warnings = $this->overlap->warnings($saved, $this->rides->clubOwnedExcept($event->id()));
        $now = $this->clock->now();
        foreach ($creates as $ride) {
            $this->audit->record($actor->getUserIdentifier(), 'ride', $ride->id(), 'create', null, $ride->toAudit(), $now);
        }
        foreach ($updates as $update) {
            $this->audit->record(
                $actor->getUserIdentifier(),
                'ride',
                $update['ride']->id(),
                'update',
                $update['before'],
                $update['ride']->toAudit(),
                $now,
            );
        }
        foreach ($existing as $id => $ride) {
            if (isset($kept[$id])) {
                continue;
            }
            $this->audit->record($actor->getUserIdentifier(), 'ride', $ride->id(), 'delete', $ride->toAudit(), null, $now);
            $this->rides->remove($ride);
        }

        $this->entityManager->flush();

        return $this->presenter->present($saved, $actor, $warnings);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{
     *     id: ?string,
     *     day: EventDay,
     *     departureTime: \DateTimeImmutable,
     *     departureOffset: DayOffset,
     *     arrivalTime: \DateTimeImmutable,
     *     arrivalOffset: DayOffset,
     *     vehicleLabel: string,
     *     clubVehicle: bool,
     *     internalNote: ?string,
     *     sortOrder: int
     * }>
     */
    private function normalize(Event $event, array $payload): array
    {
        if (!isset($payload['rides']) || !is_array($payload['rides'])) {
            throw new InvalidRequest(['rides' => 'Bitte die Fahrten als Liste senden.']);
        }

        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($payload['rides'] as $index => $ride) {
            if (!is_array($ride)) {
                $errors['rides.'.$index] = 'Die Fahrt ist ungültig.';
                continue;
            }

            $id = trim((string) ($ride['id'] ?? ''));
            if ($id !== '') {
                if (isset($seen[$id])) {
                    $errors['rides.'.$index.'.id'] = 'Eine Fahrt ist doppelt enthalten.';
                    continue;
                }
                $seen[$id] = true;
            }

            $day = $event->day(trim((string) ($ride['eventDayId'] ?? '')));
            if (!$day instanceof EventDay) {
                $errors['rides.'.$index.'.eventDayId'] = 'Bitte einen Veranstaltungstag dieser Veranstaltung wählen.';
            }

            $departureOffset = $this->offset($ride['departureOffset'] ?? null);
            $arrivalOffset = $this->offset($ride['arrivalOffset'] ?? null);
            if (!$departureOffset instanceof DayOffset) {
                $errors['rides.'.$index.'.departureOffset'] = 'Der Tagversatz der Abfahrt ist −1, 0 oder +1.';
            }
            if (!$arrivalOffset instanceof DayOffset) {
                $errors['rides.'.$index.'.arrivalOffset'] = 'Der Tagversatz der Ankunft ist −1, 0 oder +1.';
            }

            $departureRaw = trim((string) ($ride['departureTime'] ?? ''));
            $arrivalRaw = trim((string) ($ride['arrivalTime'] ?? ''));
            $departureTime = $this->time($departureRaw);
            $arrivalTime = $this->time($arrivalRaw);
            if (!$departureTime instanceof \DateTimeImmutable) {
                $errors['rides.'.$index.'.departureTime'] = 'Bitte eine gültige Abfahrtszeit angeben.';
            }
            if (!$arrivalTime instanceof \DateTimeImmutable) {
                $errors['rides.'.$index.'.arrivalTime'] = 'Bitte eine gültige Ankunftszeit angeben.';
            }

            $vehicleLabel = trim((string) ($ride['vehicleLabel'] ?? ''));
            if (mb_strlen($vehicleLabel) > 120) {
                $errors['rides.'.$index.'.vehicleLabel'] = 'Bitte höchstens 120 Zeichen für das Fahrzeug angeben.';
            }

            $internalNote = trim((string) ($ride['internalNote'] ?? ''));
            if (mb_strlen($internalNote) > 4000) {
                $errors['rides.'.$index.'.internalNote'] = 'Die interne Notiz darf höchstens 4000 Zeichen haben.';
            }

            if (
                $day instanceof EventDay
                && $departureOffset instanceof DayOffset
                && $arrivalOffset instanceof DayOffset
                && $departureTime instanceof \DateTimeImmutable
                && $arrivalTime instanceof \DateTimeImmutable
                && !RideWindow::on($day, $departureOffset, $departureTime, $arrivalOffset, $arrivalTime)->isOrdered()
            ) {
                $errors['rides.'.$index.'.arrivalTime'] = 'Die Ankunft muss nach der Abfahrt liegen.';
            }

            if (!$day instanceof EventDay || !$departureOffset instanceof DayOffset || !$arrivalOffset instanceof DayOffset || !$departureTime instanceof \DateTimeImmutable || !$arrivalTime instanceof \DateTimeImmutable) {
                continue;
            }
            if (isset($errors['rides.'.$index.'.arrivalTime']) || isset($errors['rides.'.$index.'.vehicleLabel']) || isset($errors['rides.'.$index.'.internalNote'])) {
                continue;
            }

            $rows[] = [
                'id' => $id !== '' ? $id : null,
                'day' => $day,
                'departureTime' => $departureTime,
                'departureOffset' => $departureOffset,
                'arrivalTime' => $arrivalTime,
                'arrivalOffset' => $arrivalOffset,
                'vehicleLabel' => $vehicleLabel,
                'clubVehicle' => $ride['clubVehicle'] === true,
                'internalNote' => $internalNote !== '' ? $internalNote : null,
                'sortOrder' => count($rows),
            ];
        }

        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return $rows;
    }

    private function offset(mixed $value): ?DayOffset
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            return null;
        }

        return DayOffset::tryFrom($value);
    }

    private function time(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $value = substr($value, 0, 5);
        }
        if ($value === '') {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);
        $parsed = \DateTimeImmutable::getLastErrors();
        if (!$time instanceof \DateTimeImmutable || (is_array($parsed) && ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0))) {
            return null;
        }

        return $time;
    }
}
