<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use Symfony\Component\Uid\Uuid;

/** Field differences already stored on an audit row. Missing sides stay empty. */
final class AuditChanges
{
    /** @var array<string, string|null> */
    private array $vehicleLabels = [];

    public function __construct(private ?VehicleRepository $vehicles = null)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return list<array{label: string, before: string|null, after: string|null}>
     */
    public function between(?array $before, ?array $after): array
    {
        if ($before === null && $after === null) {
            return [];
        }
        $before = $before === null ? null : $this->copy($before);
        $after = $after === null ? null : $this->copy($after);
        $known = $this->storedVehicleNames($before) + $this->storedVehicleNames($after);
        if ($before !== null) {
            $before = $this->fillVehicleNames($before, $known);
        }
        if ($after !== null) {
            $after = $this->fillVehicleNames($after, $known);
        }

        return $this->walk($before ?? [], $after ?? [], '');
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<array{label: string, before: string|null, after: string|null}>
     */
    private function walk(array $before, array $after, string $prefix): array
    {
        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $rows = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $this->skipped($key) || ($key === 'vehicleId' && $this->hasVehicleName($before, $after))) {
                continue;
            }
            $left = array_key_exists($key, $before) ? $before[$key] : null;
            $right = array_key_exists($key, $after) ? $after[$key] : null;
            if ($this->same($left, $right)) {
                continue;
            }
            $label = $prefix === '' ? $this->label($key) : $prefix.' · '.$this->label($key);
            if ($this->isBranch($left) && $this->isBranch($right)) {
                /** @var array<string, mixed> $left */
                /** @var array<string, mixed> $right */
                $nested = $this->walk($left, $right, $label);
                if ($nested !== []) {
                    array_push($rows, ...$nested);
                    continue;
                }
            }
            if ($left === null && $this->isBranch($right)) {
                /** @var array<string, mixed> $right */
                $nested = $this->walk([], $right, $label);
                if ($nested !== []) {
                    array_push($rows, ...$nested);
                    continue;
                }
            }
            if ($right === null && $this->isBranch($left)) {
                /** @var array<string, mixed> $left */
                $nested = $this->walk($left, [], $label);
                if ($nested !== []) {
                    array_push($rows, ...$nested);
                    continue;
                }
            }
            $beforeText = $this->format($key, $left);
            $afterText = $this->format($key, $right);
            if ($beforeText === null && $afterText === null) {
                continue;
            }
            $rows[] = ['label' => $label, 'before' => $beforeText, 'after' => $afterText];
        }

        return $rows;
    }

    private function skipped(string $key): bool
    {
        return in_array($key, [
            'id',
            'eventId',
            'eventDayId',
            'revision',
            'editedInApp',
            'spondMemberId',
            'sub',
            'originDidokCode',
            'destinationDidokCode',
            'rideTypeId',
            'evuId',
        ], true);
    }

    /** @param mixed $value */
    private function isBranch(mixed $value): bool
    {
        return is_array($value) && !array_is_list($value) && !$this->isPerson($value);
    }

    /** @param array<mixed> $value */
    private function isPerson(array $value): bool
    {
        if (array_is_list($value)) {
            return false;
        }

        return isset($value['name']) && (isset($value['sub']) || isset($value['memberSub']));
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $this->canonical($left) === $this->canonical($right);
    }

    private function canonical(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (!is_array($value)) {
            return '';
        }
        if (array_is_list($value)) {
            return '['.implode(',', array_map($this->canonical(...), $value)).']';
        }
        ksort($value);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = $key.':'.$this->canonical($item);
        }

        return '{'.implode(',', $parts).'}';
    }

    private function format(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return str_replace('.', ',', rtrim(rtrim(sprintf('%.2F', $value), '0'), '.'));
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $this->namedToken($field, $trimmed);
        }
        if (!is_array($value) || $value === []) {
            return null;
        }
        if ($this->isPerson($value)) {
            $name = trim((string) ($value['name'] ?? ''));

            return $name === '' ? null : $name;
        }
        if (array_is_list($value)) {
            $parts = [];
            $multiline = false;
            foreach ($value as $item) {
                $text = $this->format($field, $item);
                if ($text === null) {
                    continue;
                }
                $parts[] = $text;
                if (is_array($item) && !$this->isPerson($item)) {
                    $multiline = true;
                }
            }

            return $parts === [] ? null : implode($multiline ? "\n" : ', ', $parts);
        }
        $vehicleName = trim((string) ($value['name'] ?? ''));
        $parts = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || $this->skipped($key) || ($key === 'vehicleId' && $vehicleName !== '')) {
                continue;
            }
            $text = $this->format($key, $item);
            if ($text === null) {
                continue;
            }
            $parts[] = $this->label($key).': '.$text;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function namedToken(string $field, string $value): string
    {
        $token = match ($field) {
            'status' => match ($value) {
                'draft' => 'Entwurf',
                'published' => 'Veröffentlicht',
                'cancelled' => 'Abgesagt',
                'archived' => 'Archiviert',
                'ready' => 'Einsatzbereit',
                'limited' => 'Eingeschränkt',
                'blocked' => 'Gesperrt',
                'open' => 'Offen',
                'closed' => 'Geschlossen',
                default => null,
            },
            'kind' => match ($value) {
                'normal' => 'Terminfindung',
                'depot' => 'Depottag',
                default => null,
            },
            'answer' => match ($value) {
                'yes' => 'Ja',
                'no' => 'Nein',
                default => null,
            },
            'pathStatus' => match ($value) {
                'not_applicable' => 'nicht zutreffend',
                'requested' => 'angefragt',
                'ordered' => 'bestellt',
                'arranged' => 'angeordnet',
                default => null,
            },
            'hireStatus' => match ($value) {
                'not_applicable' => 'nicht zutreffend',
                'requested' => 'angefragt',
                'ordered' => 'bestellt',
                'confirmed' => 'bestätigt',
                default => null,
            },
            'clubs' => match ($value) {
                'historic' => 'SBB Historic-Team Winterthur',
                'supporter' => 'Supporterclub',
                default => null,
            },
            'daypart' => match ($value) {
                'morning' => 'Vormittag',
                'afternoon' => 'Nachmittag',
                'evening' => 'Abend',
                default => null,
            },
            default => null,
        };
        if ($token !== null) {
            return $token;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('d.m.Y');
            }
        }

        return $value;
    }

    private function label(string $field): string
    {
        return match ($field) {
            'title' => 'Titel',
            'clubs' => 'Vereine',
            'categories' => 'Kategorien',
            'status' => 'Status',
            'internalDescription' => 'Interne Beschreibung',
            'publicDescription' => 'Öffentliche Beschreibung',
            'location' => 'Ort',
            'publishToWebsite' => 'Auf der Website',
            'invitations' => 'Einladungen',
            'groups' => 'Gruppen',
            'people' => 'Personen',
            'excluded' => 'Ausgeschlossen',
            'days' => 'Tage',
            'date' => 'Datum',
            'startsAt' => 'Beginn',
            'endsAt' => 'Ende',
            'meetingPoints' => 'Treffpunkte',
            'place' => 'Ort',
            'meetsAt' => 'Uhrzeit',
            'description' => 'Beschreibung',
            'vehicles' => 'Fahrzeuge',
            'vehicleId' => 'Fahrzeug',
            'rentalStatus' => 'Mietstatus',
            'name' => 'Name',
            'number' => 'Nummer',
            'technicalNote' => 'Technische Notiz',
            'tracksMotorHours' => 'Motorstunden',
            'external' => 'Extern',
            'owner' => 'Eigentümerkontakt',
            'nvr' => 'NVR',
            'p1On' => 'P1',
            'p5On' => 'P5',
            'oilOn' => 'Öl',
            'nextMaintenanceOn' => 'Nächste Wartung',
            'notes' => 'Notiz',
            'guestCount' => 'Gästezahl',
            'dishes' => 'Gerichte',
            'portions' => 'Portionen',
            'responsible' => 'Verantwortliche',
            'kind' => 'Art',
            'deadline' => 'Frist',
            'options' => 'Vorschläge',
            'answer' => 'Antwort',
            'comment' => 'Kommentar',
            'respondentName' => 'Name',
            'driverReported' => 'Lokführer gemeldet',
            'pathStatus' => 'Fahrweg',
            'hireStatus' => 'Miete',
            'shunting' => 'Rangieren',
            'breaks' => 'Pausen',
            'furtherInfo' => 'Weitere Angaben',
            'departureTime' => 'Abfahrt',
            'arrivalTime' => 'Ankunft',
            'departureOffset' => 'Abfahrt, Tag',
            'arrivalOffset' => 'Ankunft, Tag',
            'trainNumber' => 'Zugnummer',
            'originName' => 'Von',
            'originAbbreviation' => 'Von, Kürzel',
            'destinationName' => 'Nach',
            'destinationAbbreviation' => 'Nach, Kürzel',
            'vias' => 'Über',
            'rideTypeName' => 'Fahrtart',
            'evuName' => 'EVU',
            'internalNote' => 'Interne Notiz',
            'createdEventId' => 'Veranstaltung',
            'file' => 'Datei',
            'folder' => 'Ordner',
            'taskList' => 'Aufgabenliste',
            'plan' => 'Helferplanung',
            default => $field,
        };
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function copy(array $data): array
    {
        $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, string>
     */
    private function storedVehicleNames(?array $data): array
    {
        $names = [];
        $this->collectVehicleNames($data, $names);

        return $names;
    }

    /**
     * @param array<string, string> $names
     */
    private function collectVehicleNames(mixed $data, array &$names): void
    {
        if (!is_array($data)) {
            return;
        }
        if ($this->isVehicleRow($data)) {
            $id = $data['vehicleId'];
            $name = trim((string) ($data['name'] ?? ''));
            if (is_string($id) && $id !== '' && $name !== '' && !isset($names[$id])) {
                $names[$id] = $name;
            }

            return;
        }
        foreach ($data as $value) {
            $this->collectVehicleNames($value, $names);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $known
     * @return array<string, mixed>
     */
    private function fillVehicleNames(array $data, array $known): array
    {
        if ($this->isVehicleRow($data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $id = $data['vehicleId'];
                $resolved = (is_string($id) ? ($known[$id] ?? $this->vehicleLabel($id)) : null);
                if (is_string($resolved) && $resolved !== '') {
                    $data['name'] = $resolved;
                }
            }

            return $data;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->fillVehicleNames($value, $known);
            }
        }

        return $data;
    }

    /** @param array<mixed> $value */
    private function isVehicleRow(array $value): bool
    {
        if (array_is_list($value)) {
            return false;
        }

        return isset($value['vehicleId']) && is_string($value['vehicleId']) && $value['vehicleId'] !== '';
    }

    /** @param array<string, mixed> $before
     *  @param array<string, mixed> $after
     */
    private function hasVehicleName(array $before, array $after): bool
    {
        foreach ([$before['name'] ?? null, $after['name'] ?? null] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return true;
            }
        }

        return false;
    }

    private function vehicleLabel(string $id): ?string
    {
        if (array_key_exists($id, $this->vehicleLabels)) {
            return $this->vehicleLabels[$id];
        }
        $label = null;
        if ($this->vehicles !== null && Uuid::isValid($id)) {
            $vehicle = $this->vehicles->get($id);
            if ($vehicle instanceof Vehicle) {
                $text = trim($vehicle->label());
                $label = $text === '' ? null : $text;
            }
        }

        return $this->vehicleLabels[$id] = $label;
    }
}
