<?php

declare(strict_types=1);

namespace App\Application\Audit;

/** Formats a stored audit timestamp. Never invents a clock time. */
final class AuditWhen
{
    public static function format(\DateTimeImmutable $occurredAt): string
    {
        $local = $occurredAt->setTimezone(new \DateTimeZone('Europe/Zurich'));
        $months = [
            1 => 'Januar',
            2 => 'Februar',
            3 => 'März',
            4 => 'April',
            5 => 'Mai',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'August',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Dezember',
        ];
        $month = $months[(int) $local->format('n')];

        return sprintf('%d. %s %s, %s', (int) $local->format('j'), $month, $local->format('Y'), $local->format('H:i'));
    }
}
