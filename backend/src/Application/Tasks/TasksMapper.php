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
            if (!is_array($row)) {
                continue;
            }
            $id = self::id($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $lists[] = [
                'id' => $id,
                'name' => self::firstText($row, ['name', 'title']),
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
            'categoryId' => self::text($row['category_id'] ?? null),
            'orgGroupId' => self::text($row['org_group_id'] ?? null),
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
            if (!is_array($row)) {
                continue;
            }
            $id = self::id($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $templates[] = [
                'id' => $id,
                'name' => self::firstText($row, ['name', 'title']),
                'taskCount' => self::number($row['task_count'] ?? null),
                'addressable' => self::safeId($id),
            ];
        }

        return $templates;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function tasks(array $rows): array
    {
        $tasks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
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
