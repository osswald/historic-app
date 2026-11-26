<?php

declare(strict_types=1);

namespace App\Application\Helfer;

final class HelferMapper
{
    public static function externalId(string $eventId): string
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $eventId) !== 1) {
            throw new \InvalidArgumentException('Die Veranstaltungskennung ist ungültig.');
        }

        return 'ext:'.$eventId;
    }

    public static function reference(?string $helferEventId, string $clubEventId): string
    {
        if (is_string($helferEventId) && self::isUuid($helferEventId)) {
            return $helferEventId;
        }

        return self::externalId($clubEventId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, title: string, date: string|null, url: string|null}
     */
    public static function plan(array $row): array
    {
        return [
            'id' => self::text($row, ['id']),
            'title' => self::text($row, ['title', 'name']) ?: self::text($row, ['id']),
            'date' => self::optionalText($row, ['date']),
            'url' => self::httpsUrl(self::optionalText($row, ['url'])),
        ];
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }

    public static function httpsUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            ? $url
            : null;
    }

    /** @param mixed $payload */
    public static function collection(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach (['data', 'shifts', 'assignments', 'events', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_is_list($payload[$key]) ? $payload[$key] : [$payload[$key]];
            }
        }

        return [$payload];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, title: string, category: string|null, startsAt: string|null, endsAt: string|null, remarks: string|null, needed: int, assignedCount: int, reported: int, open: int, url: string|null, people: list<array{name: string, status: string, statusLabel: string}>}
     */
    public static function shift(array $row): array
    {
        $id = self::text($row, ['id', 'shiftId']);

        return [
            'id' => $id,
            'title' => self::text($row, ['function', 'title', 'name', 'label', 'role']) ?: $id,
            'category' => self::optionalText($row, ['category']),
            'startsAt' => self::optionalText($row, ['startsAt']),
            'endsAt' => self::optionalText($row, ['endsAt']),
            'remarks' => self::optionalText($row, ['remarks']),
            'needed' => self::number($row, ['needed']),
            'assignedCount' => self::number($row, ['assigned']),
            'reported' => self::number($row, ['reported']),
            'open' => self::number($row, ['open']),
            'url' => self::httpsUrl(self::optionalText($row, ['url'])),
            'people' => [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{name: string, status: string, statusLabel: string}
     */
    public static function shiftPerson(array $row): array
    {
        $helper = is_array($row['helper'] ?? null) ? $row['helper'] : $row;
        $name = trim((self::optionalText($helper, ['firstName']) ?? '').' '.(self::optionalText($helper, ['lastName']) ?? ''));
        if ($name === '') {
            $name = self::text($helper, ['name']) ?: 'Helfer';
        }
        $status = self::text($row, ['status']);
        if ($status === '' && ($row['binding'] ?? false) === true) {
            $status = 'zugeteilt';
        }

        return [
            'name' => $name,
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, title: string, eventTitle: string|null, date: string|null, time: string|null, status: string, statusLabel: string, binding: bool, url: string|null}
     */
    public static function assignment(array $row): array
    {
        return self::personalShift($row, null);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{assigned: bool, binding: bool, url: string|null, shifts: list<array{id: string, title: string, eventTitle: string|null, date: string|null, time: string|null, status: string, statusLabel: string, binding: bool, url: string|null}>}
     */
    public static function myAssignment(array $row): array
    {
        $url = self::httpsUrl(self::optionalText($row, ['url']));
        $shifts = [];
        $binding = false;
        foreach (self::collection($row['shifts'] ?? []) as $shift) {
            if (!is_array($shift)) {
                continue;
            }
            $mapped = self::personalShift($shift, $url);
            $binding = $binding || $mapped['binding'];
            $shifts[] = $mapped;
        }
        $assigned = ($row['assigned'] ?? false) === true;

        return [
            'assigned' => $assigned,
            'binding' => $assigned && $binding,
            'url' => $url,
            'shifts' => $shifts,
        ];
    }

    /**
     * A personal assignment nests the occasion under event and the shift under shift.
     * The personal link is shift.url or the top-level url (/h/…), never the admin event url.
     *
     * @param array<string, mixed> $row
     * @return array{id: string, title: string, eventTitle: string|null, date: string|null, time: string|null, status: string, statusLabel: string, binding: bool, url: string|null}
     */
    private static function personalShift(array $row, ?string $fallbackUrl): array
    {
        $event = is_array($row['event'] ?? null) ? $row['event'] : [];
        $shift = is_array($row['shift'] ?? null) ? $row['shift'] : [];
        $source = $shift !== [] ? $shift : $row;
        $status = self::text($row, ['status']);
        if ($status === '' && ($row['binding'] ?? false) === true) {
            $status = 'zugeteilt';
        }
        $id = self::text($row, ['assignmentId']);
        if ($id === '') {
            $id = self::text($source, ['id', 'shiftId', 'assignmentId']);
        }
        $startsAt = self::optionalText($source, ['startsAt']);
        $endsAt = self::optionalText($source, ['endsAt']);
        $url = self::httpsUrl(self::optionalText($source, ['url']));
        if ($url === null && $source !== $row) {
            $url = self::httpsUrl(self::optionalText($row, ['url']));
        }

        return [
            'id' => $id,
            'title' => self::text($source, ['function', 'title', 'name', 'label', 'role']) ?: $id,
            'eventTitle' => self::optionalText($event, ['title']),
            'date' => self::optionalText($event, ['date']) ?? self::zurichDate($startsAt),
            'time' => self::zurichSpan($startsAt, $endsAt),
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
            'binding' => ($row['binding'] ?? false) === true,
            'url' => $url ?? $fallbackUrl,
        ];
    }

    private static function zurichSpan(?string $startsAt, ?string $endsAt): ?string
    {
        $start = self::zurichClock($startsAt);
        $end = self::zurichClock($endsAt);
        if ($start !== null && $end !== null) {
            return $start.'–'.$end;
        }

        return $start ?? $end;
    }

    private static function zurichClock(?string $value): ?string
    {
        $instant = self::instant($value);

        return $instant?->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('H:i');
    }

    private static function zurichDate(?string $value): ?string
    {
        $instant = self::instant($value);

        return $instant?->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('Y-m-d');
    }

    private static function instant(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'zugeteilt' => 'Zugeteilt',
            'gemeldet' => 'Gemeldet',
            default => $status,
        };
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    private static function text(array $row, array $keys): string
    {
        return self::optionalText($row, $keys) ?? '';
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    private static function optionalText(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    private static function number(array $row, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        return 0;
    }
}
