<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\InvalidRequest;
use App\Application\ZurichClock;
use App\Domain\Event\Club;
use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Event\EventStatus;
use App\Domain\Event\EventRepository;
use App\Domain\Event\Place;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SaveEvent
{
    public function __construct(
        private EventRepository $events,
        private EventCatalog $catalog,
        private PlaceDirectory $places,
        private VehicleRepository $vehicles,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function create(MemberUser $actor, array $payload): Event
    {
        $this->assertOrganizer($actor);
        $input = $this->normalize($payload);
        $now = $this->clock->now();
        $event = new Event(
            $input['title'],
            $input['clubs'],
            $input['categories'],
            $input['status'],
            $input['internalDescription'],
            $input['publicDescription'],
            $input['location'],
            $input['publishToWebsite'],
            $now,
        );
        $this->syncDays($event, $input['days']);
        if ($input['vehicles'] !== null) {
            $event->replaceAssignedVehicles($input['vehicles']);
        }
        $this->events->add($event);
        $this->audit->record($actor->getUserIdentifier(), 'event', $event->id(), 'create', null, $event->toAudit(), $now);
        $this->entityManager->flush();

        return $event;
    }

    /** @param array<string, mixed> $payload */
    public function update(MemberUser $actor, string $id, array $payload): Event
    {
        $this->assertOrganizer($actor);
        $event = $this->catalog->visible($id, $actor);
        $before = $event->toAudit();
        $input = $this->normalize($payload);
        $now = $this->clock->now();
        $event->change(
            $input['title'],
            $input['clubs'],
            $input['categories'],
            $input['status'],
            $input['internalDescription'],
            $input['publicDescription'],
            $input['location'],
            $input['publishToWebsite'],
            $now,
        );
        $this->syncDays($event, $input['days']);
        if ($input['vehicles'] !== null) {
            $event->replaceAssignedVehicles($input['vehicles']);
        }
        $this->audit->record($actor->getUserIdentifier(), 'event', $event->id(), 'update', $before, $event->toAudit(), $now);
        $this->entityManager->flush();

        return $event;
    }

    private function assertOrganizer(MemberUser $actor): void
    {
        if (!$actor->isOrganizer()) {
            throw new AccessDeniedException('Veranstaltungen bearbeiten darf nur ein Organisator.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     title: string,
     *     clubs: list<string>,
     *     categories: list<string>,
     *     status: EventStatus,
     *     internalDescription: ?string,
     *     publicDescription: ?string,
     *     location: ?string,
     *     publishToWebsite: bool,
     *     days: list<array{
     *         id: ?string,
     *         date: \DateTimeImmutable,
     *         startsAt: ?\DateTimeImmutable,
     *         endsAt: ?\DateTimeImmutable,
     *         location: ?string,
     *         meetingPoints: list<array{place: Place, meetsAt: \DateTimeImmutable, description: ?string}>
     *     }>,
     *     vehicles: ?list<array{vehicle: Vehicle, rentalStatus: ?string}>
     * }
     */
    private function normalize(array $payload): array
    {
        $errors = [];
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Bitte einen Titel mit höchstens 200 Zeichen angeben.';
        }

        $clubs = [];
        foreach ((array) ($payload['clubs'] ?? []) as $value) {
            $club = Club::tryFrom((string) $value);
            if ($club instanceof Club) {
                $clubs[] = $club->value;
            }
        }
        $clubs = array_values(array_unique($clubs));
        if ($clubs === []) {
            $errors['clubs'] = 'Bitte mindestens einen Verein auswählen.';
        }

        $categories = [];
        foreach ((array) ($payload['categories'] ?? []) as $value) {
            $category = trim((string) $value);
            if ($category !== '') {
                $categories[] = $category;
            }
        }

        $status = EventStatus::tryFrom((string) ($payload['status'] ?? ''));
        if (!$status instanceof EventStatus || $status === EventStatus::Archived) {
            $errors['status'] = 'Bitte Entwurf, Veröffentlicht oder Abgesagt wählen.';
            $status = EventStatus::Draft;
        }

        $days = [];
        foreach ((array) ($payload['days'] ?? []) as $index => $day) {
            if (!is_array($day)) {
                continue;
            }
            $dateRaw = trim((string) ($day['date'] ?? ''));
            $startsRaw = trim((string) ($day['startsAt'] ?? ''));
            $endsRaw = trim((string) ($day['endsAt'] ?? ''));
            $location = $this->nullableText($day['location'] ?? null, 200);
            $meetingPoints = $this->meetingPoints($day['meetingPoints'] ?? null, $index, $errors);
            if ($dateRaw === '' && $startsRaw === '' && $endsRaw === '' && $location === null && $meetingPoints === []) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
            if (!$date instanceof \DateTimeImmutable) {
                $errors['days.'.$index.'.date'] = 'Bitte ein gültiges Datum angeben.';
                continue;
            }
            $startsAt = $this->time($startsRaw);
            $endsAt = $this->time($endsRaw);
            if ($startsRaw !== '' && !$startsAt instanceof \DateTimeImmutable) {
                $errors['days.'.$index.'.startsAt'] = 'Bitte eine gültige Uhrzeit angeben.';
            }
            if ($endsRaw !== '' && !$endsAt instanceof \DateTimeImmutable) {
                $errors['days.'.$index.'.endsAt'] = 'Bitte eine gültige Uhrzeit angeben.';
            }
            $id = trim((string) ($day['id'] ?? ''));
            $days[] = [
                'id' => $id !== '' ? $id : null,
                'date' => $date,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'location' => $location,
                'meetingPoints' => $meetingPoints,
            ];
        }
        if ($days === []) {
            $errors['days'] = 'Bitte mindestens einen Veranstaltungstag angeben.';
        }
        $vehicles = array_key_exists('vehicles', $payload) ? $this->vehicles($payload['vehicles'], $errors) : null;
        if ($location = $days[0]['location'] ?? null) {
            $this->rememberPlace($location);
        }
        foreach ($days as $day) {
            if (is_string($day['location']) && $day['location'] !== '') {
                $this->rememberPlace($day['location']);
            }
        }

        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return [
            'title' => $title,
            'clubs' => $clubs,
            'categories' => array_values(array_unique($categories)),
            'status' => $status,
            'internalDescription' => $this->nullableText($payload['internalDescription'] ?? null, 8000),
            'publicDescription' => $this->nullableText($payload['publicDescription'] ?? null, 8000),
            'location' => $days[0]['location'] ?? null,
            'publishToWebsite' => (bool) ($payload['publishToWebsite'] ?? false),
            'days' => $days,
            'vehicles' => $vehicles,
        ];
    }

    /** @param list<array<string, mixed>> $days */
    private function syncDays(Event $event, array $days): void
    {
        $kept = [];
        foreach ($days as $index => $day) {
            $points = [];
            foreach ($day['meetingPoints'] as $point) {
                $points[] = [
                    'place' => $this->places->named($point['placeName']),
                    'meetsAt' => $point['meetsAt'],
                    'description' => $point['description'],
                ];
            }
            $existing = is_string($day['id']) ? $event->day($day['id']) : null;
            if ($existing instanceof EventDay) {
                $existing->revisePlan(
                    $day['date'],
                    $day['startsAt'],
                    $day['endsAt'],
                    $day['location'],
                    $index,
                );
                $existing->replaceMeetingPoints($points);
                $kept[] = $existing->id();
                continue;
            }
            $created = $event->addDay(
                $day['date'],
                $day['startsAt'],
                $day['endsAt'],
                $day['location'],
                $index,
            );
            $created->replaceMeetingPoints($points);
            $kept[] = $created->id();
        }
        $event->retainDays($kept);
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{placeName: string, meetsAt: \DateTimeImmutable, description: ?string}>
     */
    private function meetingPoints(mixed $value, int|string $index, array &$errors): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            $errors['days.'.$index.'.meetingPoints'] = 'Bitte die Treffpunkte als Liste angeben.';

            return [];
        }
        $points = [];
        foreach ($value as $pointIndex => $point) {
            if (!is_array($point)) {
                $errors['days.'.$index.'.meetingPoints.'.$pointIndex] = 'Der Treffpunkt ist ungültig.';
                continue;
            }
            $name = trim((string) ($point['placeName'] ?? ''));
            $timeRaw = trim((string) ($point['meetsAt'] ?? ''));
            $description = $this->nullableText($point['description'] ?? null, 2000);
            if ($name === '' && $timeRaw === '' && $description === null) {
                continue;
            }
            if ($name === '' || mb_strlen($name) > 200) {
                $errors['days.'.$index.'.meetingPoints.'.$pointIndex.'.placeName'] = 'Bitte einen Ort mit höchstens 200 Zeichen angeben.';
                continue;
            }
            $meetsAt = $this->time($timeRaw);
            if (!$meetsAt instanceof \DateTimeImmutable) {
                $errors['days.'.$index.'.meetingPoints.'.$pointIndex.'.meetsAt'] = 'Bitte die Uhrzeit des Treffpunkts angeben.';
                continue;
            }
            $this->rememberPlace($name);
            $points[] = ['placeName' => $name, 'meetsAt' => $meetsAt, 'description' => $description];
        }

        return $points;
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{vehicle: Vehicle, rentalStatus: ?string}>
     */
    private function vehicles(mixed $value, array &$errors): array
    {
        if (!is_array($value)) {
            $errors['vehicles'] = 'Bitte die Fahrzeugliste als Liste angeben.';

            return [];
        }
        $fleet = [];
        foreach ($this->vehicles->all() as $vehicle) {
            $fleet[$vehicle->id()] = $vehicle;
        }
        $rows = [];
        $seen = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) {
                $errors['vehicles.'.$index] = 'Das Fahrzeug ist ungültig.';
                continue;
            }
            $id = trim((string) ($row['vehicleId'] ?? ''));
            $vehicle = $fleet[$id] ?? null;
            if (!$vehicle instanceof Vehicle) {
                $errors['vehicles.'.$index.'.vehicleId'] = 'Bitte ein Fahrzeug aus dem Fahrzeugstamm wählen.';
                continue;
            }
            if (isset($seen[$id])) {
                $errors['vehicles.'.$index.'.vehicleId'] = 'Dieses Fahrzeug ist in der Liste bereits enthalten.';
                continue;
            }
            $seen[$id] = true;
            $rental = trim((string) ($row['rentalStatus'] ?? ''));
            if (mb_strlen($rental) > 80) {
                $errors['vehicles.'.$index.'.rentalStatus'] = 'Bitte höchstens 80 Zeichen für den Mietstatus angeben.';
                continue;
            }
            $rows[] = ['vehicle' => $vehicle, 'rentalStatus' => $rental !== '' ? $rental : null];
        }

        return $rows;
    }

    private function rememberPlace(string $name): void
    {
        $this->places->named($name);
    }

    private function time(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);

        return $time instanceof \DateTimeImmutable ? $time : null;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }
}
