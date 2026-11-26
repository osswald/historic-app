<?php

declare(strict_types=1);

namespace App\Application\Tasks;

final class TasksMapper
{
    public static function safeId(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,80}\z/', $id) === 1;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function taskLists(array $rows): array
    {
        $lists = [];
        foreach ($rows as $row) {
            $row = self::record($row);
            if ($row === null) {
                continue;
            }
            $id = self::id($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $lists[] = [
                'id' => $id,
                'name' => self::firstText($row, ['name', 'title']),
                'category' => self::category($row['category'] ?? null),
                'orgGroup' => self::named($row['org_group'] ?? null),
                'progress' => self::progress($row),
                'addressable' => self::safeId($id),
            ];
        }

        return $lists;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function taskList(array $row): array
    {
        $row = self::unwrapData($row);
        $id = self::id($row['id'] ?? null);
        $tasks = $row['tasks'] ?? [];

        return [
            'id' => $id,
            'name' => self::firstText($row, ['name', 'title']),
            'description' => self::text($row['description'] ?? null),
            'category' => self::category($row['category'] ?? null),
            'orgGroup' => self::named($row['org_group'] ?? null),
            'isPersonal' => is_bool($row['is_personal'] ?? null) ? $row['is_personal'] : null,
            'templateId' => self::text($row['template_id'] ?? null),
            'progress' => self::progress($row),
            'addressable' => is_string($id) && self::safeId($id),
            'tasks' => self::tasks(is_array($tasks) && array_is_list($tasks) ? $tasks : []),
        ];
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function templates(array $rows): array
    {
        $templates = [];
        foreach ($rows as $row) {
            $row = self::record($row);
            if ($row === null) {
                continue;
            }
            $id = self::id($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $templates[] = [
                'id' => $id,
                'name' => self::firstText($row, ['name', 'title']),
                'category' => self::category($row['category'] ?? null),
                'orgGroup' => self::named($row['org_group'] ?? null),
                'taskCount' => self::number($row['task_count'] ?? null),
                'addressable' => self::safeId($id),
            ];
        }

        return $templates;
    }

    /**
     * A template detail keeps tasks only when the tasks API actually sent them.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function template(array $row): array
    {
        $row = self::unwrapData($row);
        $id = self::id($row['id'] ?? null);
        $tasks = $row['tasks'] ?? null;
        $tasksIncluded = is_array($tasks) && array_is_list($tasks);

        return [
            'id' => $id,
            'name' => self::firstText($row, ['name', 'title']),
            'description' => self::text($row['description'] ?? null),
            'category' => self::category($row['category'] ?? null),
            'orgGroup' => self::named($row['org_group'] ?? null),
            'taskCount' => self::number($row['task_count'] ?? null),
            'addressable' => is_string($id) && self::safeId($id),
            'tasksIncluded' => $tasksIncluded,
            'tasks' => $tasksIncluded ? self::tasks($tasks) : [],
        ];
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function tasks(array $rows): array
    {
        $tasks = [];
        foreach ($rows as $row) {
            $row = self::record($row);
            if ($row === null) {
                continue;
            }
            $id = self::id($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $status = self::text($row['status'] ?? null);
            $tasks[] = [
                'id' => $id,
                'title' => self::firstText($row, ['title', 'name', 'summary']),
                'details' => self::firstText($row, ['description', 'details', 'body', 'text']),
                'status' => $status,
                'statusLabel' => is_string($status) ? TaskStatus::label($status) : null,
                'taskList' => self::named($row['task_list'] ?? null),
                'addressable' => self::safeId($id),
            ];
        }

        return $tasks;
    }

    /** @return list<mixed> */
    public static function collection(mixed $data): array
    {
        if (is_array($data)) {
            $data = self::unwrapData($data);
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new \App\Application\UpstreamUnavailable('Die Aufgaben-App hat nicht mit einer Liste geantwortet.');
        }

        return $data;
    }

    /**
     * The tasks API wraps both lists and single records in {"data": ...}.
     *
     * @return array<mixed>
     */
    private static function unwrapData(array $data): array
    {
        if (array_is_list($data) || !array_key_exists('data', $data) || !is_array($data['data'])) {
            return $data;
        }

        return $data['data'];
    }

    /**
     * A row is ignored when it is not an object. A {"data": ...} envelope is opened first.
     *
     * @return array<string, mixed>|null
     */
    private static function record(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $row = self::unwrapData($row);

        return array_is_list($row) ? null : $row;
    }

    /** @return array{id: string|null, name: string|null, color: string|null}|null */
    private static function category(mixed $value): ?array
    {
        $value = self::record($value);
        if ($value === null) {
            return null;
        }
        $mapped = [
            'id' => self::id($value['id'] ?? null),
            'name' => self::text($value['name'] ?? null),
            'color' => self::color($value['color'] ?? null),
        ];
        if ($mapped['id'] === null && $mapped['name'] === null && $mapped['color'] === null) {
            return null;
        }

        return $mapped;
    }

    /** @return array{id: string|null, name: string|null}|null */
    private static function named(mixed $value): ?array
    {
        $value = self::record($value);
        if ($value === null) {
            return null;
        }
        $mapped = [
            'id' => self::id($value['id'] ?? null),
            'name' => self::text($value['name'] ?? null),
        ];
        if ($mapped['id'] === null && $mapped['name'] === null) {
            return null;
        }

        return $mapped;
    }

    private static function color(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/\A#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function progress(array $row): ?array
    {
        $progress = $row['progress'] ?? null;
        if (!is_array($progress)) {
            return null;
        }

        return [
            'done' => self::number($progress['done'] ?? null),
            'total' => self::number($progress['total'] ?? null),
            'percent' => self::number($progress['percent'] ?? null),
        ];
    }

    private static function id(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 80) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function firstText(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $text = self::text($row[$key] ?? null);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return strlen($value) > 500 ? substr($value, 0, 500) : $value;
    }

    private static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return null;
    }
}
