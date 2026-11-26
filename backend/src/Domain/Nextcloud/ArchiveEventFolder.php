<?php

declare(strict_types=1);

namespace App\Domain\Nextcloud;

use App\Domain\Event\Event;

/**
 * Sieben Tage nach dem letzten Anlass-Tag liegt der Ordner unter
 * Fahrten_Anlässe/_ARCHIV/YYYY. YYYY ist das Jahr des Starttags.
 */
final class ArchiveEventFolder
{
    private const ZONE = 'Europe/Zurich';

    public function directory(Event $event, \DateTimeImmutable $today): ?string
    {
        $dates = [];
        foreach ($event->days() as $day) {
            $dates[] = $day->occursOn()->format('Y-m-d');
        }
        if ($dates === []) {
            return null;
        }
        sort($dates);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $dates[array_key_last($dates)], new \DateTimeZone(self::ZONE));
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $dates[0], new \DateTimeZone(self::ZONE));
        if (!$end instanceof \DateTimeImmutable || !$start instanceof \DateTimeImmutable) {
            return null;
        }
        $due = $end->modify('+7 days')->format('Y-m-d');
        $todayKey = \DateTimeImmutable::createFromInterface($today)->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d');
        if ($todayKey < $due) {
            return null;
        }

        return 'Fahrten_Anlässe/_ARCHIV/'.$start->format('Y');
    }
}
