<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Event\Attendance;
use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Spond\SpondRead;
use App\Domain\Spond\SpondReply;

/** Schreibt gelesene Spond-Teilnahmen als Ja oder Nein an den Anlass. Es geht nichts zurück nach Spond. */
final class ImportSpondAttendance
{
    /**
     * @param list<Event> $events
     * @return list<\Closure(): void>
     */
    public function __invoke(SpondRead $read, array $events, \DateTimeImmutable $now): array
    {
        $bySpondId = [];
        foreach ($events as $event) {
            $spondId = $event->spondEventId();
            if ($spondId !== null && $spondId !== '') {
                $bySpondId[$spondId] = $event;
            }
        }
        $names = [];
        foreach ($read->people() as $person) {
            $name = $this->name($person->displayName());
            if ($name !== null) {
                $names[$person->spondId()] = $name;
            }
        }

        $finish = [];
        foreach ($read->events() as $source) {
            $event = $bySpondId[$source->spondId()] ?? null;
            if (!$event instanceof Event) {
                continue;
            }
            $day = $event->days()[0] ?? null;
            if (!$day instanceof EventDay) {
                continue;
            }
            foreach ($source->answers() as $answer) {
                $attendance = match ($answer->reply()) {
                    SpondReply::Accepted => Attendance::Yes,
                    SpondReply::Declined => Attendance::No,
                    SpondReply::Unanswered => null,
                };
                $finish[] = $day->importSpondReply(
                    $answer->spondId(),
                    $answer->matchedSub(),
                    $names[$answer->spondId()] ?? null,
                    $attendance,
                    $this->comment($answer->comment()),
                    $now,
                );
            }
        }

        return $finish;
    }

    private function name(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return mb_strlen($name) > 200 ? mb_substr($name, 0, 200) : $name;
    }

    private function comment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }
        $comment = trim($comment);
        if ($comment === '') {
            return null;
        }

        return mb_strlen($comment) > 2000 ? mb_substr($comment, 0, 2000) : $comment;
    }
}
