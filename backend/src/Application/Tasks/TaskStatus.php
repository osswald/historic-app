<?php

declare(strict_types=1);

namespace App\Application\Tasks;

final class TaskStatus
{
    public const TODO = 'todo';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';

    /** @var list<string> */
    public const VALUES = [self::TODO, self::IN_PROGRESS, self::DONE];

    public static function label(string $status): ?string
    {
        return match ($status) {
            self::TODO => 'Offen',
            self::IN_PROGRESS => 'In Arbeit',
            self::DONE => 'Erledigt',
            default => null,
        };
    }

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::VALUES, true);
    }
}
