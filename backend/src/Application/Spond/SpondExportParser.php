<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Spond\SpondRead;
use App\Domain\Spond\SpondReply;

final class SpondExportParser
{
    public function __construct(
        private SpondResponseMap $responses,
        private SpondIdMatcher $matcher,
    ) {
    }

    /**
     * People come only from the export. Accounts outside it are not created.
     *
     * @param array<string, mixed> $export
     * @param list<array{sub: string, appMetadata: mixed}> $accounts
     */
    public function parse(array $export, array $accounts, \DateTimeImmutable $readAt): SpondRead
    {
        $group = $this->arrayValue($export, 'group');
        $eventRows = $this->eventRows($export);
        if ($group === null || $eventRows === null) {
            throw new SpondExportException('Die Spond-Antwort enthält keine Gruppe oder keine Termine.');
        }

        $groupName = $this->text($group, 'name');
        $groupId = $this->text($group, 'spondId');
        if ($groupName === null || $groupId === null) {
            throw new SpondExportException('Die Spond-Antwort ist unvollständig.');
        }

        $parsedEvents = [];
        $seenEvents = [];
        foreach ($eventRows as $row) {
            if (!is_array($row)) {
                throw new SpondExportException('Die Spond-Antwort ist unvollständig.');
            }
            /** @var array<string, mixed> $row */
            $heading = $this->text($row, 'heading');
            $eventId = $this->text($row, 'spondId');
            $start = $this->text($row, 'startTimestamp');
            if ($heading === null || $eventId === null || $start === null) {
                throw new SpondExportException('Die Spond-Antwort ist unvollständig.');
            }
            if (isset($seenEvents[$eventId])) {
                continue;
            }
            $seenEvents[$eventId] = true;
            $parsedEvents[] = [
                'spondId' => $eventId,
                'heading' => $heading,
                'eventOn' => $this->eventOn($start),
                'description' => $this->text($row, 'description'),
                'comment' => $this->text($row, 'comment'),
                'options' => $this->options($row),
                'answers' => $this->responses->answers($this->arrayValue($row, 'responses') ?? []),
                'comments' => $this->comments($row),
            ];
        }
        if ($parsedEvents === []) {
            throw new SpondExportException('Die Spond-Antwort enthält keine Termine.');
        }
        usort($parsedEvents, static function (array $left, array $right): int {
            $byDate = $left['eventOn'] <=> $right['eventOn'];
            if ($byDate !== 0) {
                return $byDate;
            }

            return [$left['heading'], $left['spondId']] <=> [$right['heading'], $right['spondId']];
        });

        $members = $this->members($group);
        $memberIds = array_map(static fn (array $member): string => $member['spondId'], $members);
        $exportIds = $memberIds;
        foreach ($parsedEvents as $event) {
            foreach ($event['answers'] as $answer) {
                $exportIds[] = $answer['spondId'];
            }
        }
        $matched = $this->matcher->match($exportIds, $accounts);

        $read = new SpondRead($groupName, $groupId, $readAt);
        foreach ($members as $member) {
            $read->addPerson($member['spondId'], $matched[$member['spondId']] ?? null, $member['name']);
        }
        foreach ($parsedEvents as $event) {
            $stored = $read->addEvent(
                $event['spondId'],
                $event['heading'],
                $event['eventOn'],
                $event['description'],
                $event['comment'],
                $event['options'],
            );
            foreach ($event['answers'] as $answer) {
                $spondId = $answer['spondId'];
                $stored->addAnswer($spondId, $answer['answer'], $event['comments'][$spondId] ?? null, $matched[$spondId] ?? null);
            }
        }

        return $read;
    }

    /**
     * Der Kalenderimport liefert `events`. Ältere Leseabfragen und die Tests haben einen einzelnen `event`.
     *
     * @param array<string, mixed> $export
     * @return list<mixed>|null
     */
    private function eventRows(array $export): ?array
    {
        $rows = $export['events'] ?? null;
        if (is_array($rows) && array_is_list($rows)) {
            return $rows;
        }
        $one = $export['event'] ?? null;
        if (is_array($one)) {
            return [$one];
        }

        return null;
    }

    /** @param array<string, mixed> $group
     *  @return list<array{spondId: string, name: ?string}>
     */
    private function members(array $group): array
    {
        $members = $group['members'] ?? null;
        if (!is_array($members)) {
            throw new SpondExportException('Die Gruppe enthält keine Mitgliederliste.');
        }
        $rows = [];
        $seen = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $id = $this->text($member, 'spondId');
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rows[] = ['spondId' => $id, 'name' => $this->text($member, 'name')];
        }

        return $rows;
    }

    /** @param array<string, mixed> $event
     *  @return array<string, string>
     */
    private function comments(array $event): array
    {
        $rows = $event['answerComments'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $comments = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->text($row, 'spondId');
            $comment = $this->text($row, 'comment');
            if ($id === null || $comment === null || isset($comments[$id])) {
                continue;
            }
            $comments[$id] = $comment;
        }

        return $comments;
    }

    /** @param array<string, mixed> $event
     *  @return list<array{spondId: string, name: string, description: string|null, accepted: int, declined: int, unanswered: int}>
     */
    private function options(array $event): array
    {
        $rows = $event['options'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $options = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->text($row, 'spondId');
            $name = $this->text($row, 'name');
            if ($id === null || $name === null || isset($seen[$id]) || str_contains($name, '@')) {
                continue;
            }
            $seen[$id] = true;
            $options[] = [
                'spondId' => $id,
                'name' => $name,
                'description' => $this->text($row, 'description'),
                'accepted' => $this->count($row['accepted'] ?? 0),
                'declined' => $this->count($row['declined'] ?? 0),
                'unanswered' => $this->count($row['unanswered'] ?? 0),
            ];
        }

        return $options;
    }

    private function eventOn(string $start): \DateTimeImmutable
    {
        try {
            $instant = new \DateTimeImmutable(str_replace('Z', '+00:00', $start));
        } catch (\Exception) {
            throw new SpondExportException('Das Datum des Termins ist ungültig.');
        }

        return $instant->setTimezone(new \DateTimeZone('Europe/Zurich'))->setTime(0, 0);
    }

    /** @param array<string, mixed> $source */
    private function arrayValue(array $source, string $key): ?array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $source */
    private function text(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function count(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            return 0;
        }

        return $value;
    }
}
