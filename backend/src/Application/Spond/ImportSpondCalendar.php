<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Application\Event\KnownEventCategories;
use App\Domain\Event\Club;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;

/** Legt gelesene Spond-Termine als Anlässe im Vereinskalender ab. */
final class ImportSpondCalendar
{
    public function __construct(
        private EventRepository $events,
        private KnownEventCategories $categories,
    ) {
    }

    /** @param array<string, mixed> $export */
    public function __invoke(array $export, \DateTimeImmutable $now): int
    {
        return count($this->import($export, $now));
    }

    /**
     * @param array<string, mixed> $export
     * @return list<Event>
     */
    public function import(array $export, \DateTimeImmutable $now): array
    {
        $rows = $export['events'] ?? null;
        if (!is_array($rows)) {
            $one = $export['event'] ?? null;
            $rows = is_array($one) ? [$one] : [];
        }

        $imported = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event = $this->upsert($row, $now);
            if ($event instanceof Event) {
                $imported[] = $event;
            }
        }

        return $imported;
    }

    /** @param array<string, mixed> $row */
    private function upsert(array $row, \DateTimeImmutable $now): ?Event
    {
        $spondId = $this->text($row, 'spondId');
        $heading = $this->text($row, 'heading');
        $start = $this->text($row, 'startTimestamp');
        if ($spondId === null || $heading === null || $start === null) {
            return null;
        }

        $when = $this->zurich($start);
        $ends = $this->text($row, 'endTimestamp');
        $location = $this->place($row['location'] ?? null);
        $description = $this->text($row, 'description');
        $existing = $this->events->bySpondEventId($spondId);
        if ($existing instanceof Event) {
            $existing->refreshFromSpond(
                $heading,
                $description,
                $location,
                $when,
                $when,
                $ends === null ? null : $this->zurich($ends),
                $now,
            );

            return $existing;
        }

        $event = new Event(
            $heading,
            [Club::Historic->value],
            $this->categories->named('Spond'),
            EventStatus::Published,
            'Aus Spond übernommen. Die Teilnahme liegt an diesem Anlass. Fahrt und Gastronomie können hier ergänzt werden.',
            $description,
            $location,
            false,
            $now,
        );
        $event->attachSpondImport($spondId);
        $event->addDay($when, $when, $ends === null ? null : $this->zurich($ends), $location, 0);
        $this->events->add($event);

        return $event;
    }

    /** @param array<string, mixed> $row */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function zurich(string $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('Europe/Zurich'));
    }

    private function place(mixed $location): ?string
    {
        if (is_string($location)) {
            $location = trim($location);

            return $location === '' ? null : $location;
        }
        if (!is_array($location)) {
            return null;
        }
        foreach (['feature', 'address', 'name'] as $key) {
            $value = $location[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
