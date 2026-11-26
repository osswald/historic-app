<?php

declare(strict_types=1);

namespace App\Domain\Nextcloud;

use App\Domain\Event\Event;

/**
 * Ordner unter Fahrten_Anlässe: ein Tag als YYYY-MM-DD, mehrere Tage im selben
 * Monat als YYYY-MM-DD-DD. Über Monats- oder Jahresgrenzen zählt nur der Starttag.
 */
final class EventFolderName
{
    public static function forEvent(Event $event): string
    {
        $dates = [];
        foreach ($event->days() as $day) {
            $dates[] = $day->occursOn()->format('Y-m-d');
        }

        return self::fromTitle($event->title(), $dates);
    }

    /** @param list<string> $dates */
    public static function fromTitle(string $title, array $dates): string
    {
        if ($dates === []) {
            throw new EventHasNoDate();
        }
        foreach ($dates as $date) {
            self::assertDate($date);
        }
        sort($dates);
        $start = $dates[0];
        $end = $dates[array_key_last($dates)];
        $prefix = $start;
        if ($start !== $end && substr($start, 0, 7) === substr($end, 0, 7)) {
            $prefix .= '-'.substr($end, 8, 2);
        }
        $clean = self::cleanTitle($title);
        $room = 250 - strlen($prefix) - 1;
        if (mb_strlen($clean) > $room) {
            $clean = rtrim(mb_substr($clean, 0, max(1, $room)));
        }

        return $prefix.' '.$clean;
    }

    private static function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Das Datum ist ungültig.');
        }
    }

    private static function cleanTitle(string $title): string
    {
        $title = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $title);
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B.");
        if ($title === '' || $title === '.' || $title === '..') {
            return 'Anlass';
        }

        return $title;
    }
}
