<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Spond\SpondExportParser;
use App\Application\Spond\SpondIdMatcher;
use App\Application\Spond\SpondResponseMap;
use App\Domain\Spond\SpondReply;
use PHPUnit\Framework\TestCase;

final class SpondIdMatcherTest extends TestCase
{
    private SpondIdMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SpondIdMatcher();
    }

    public function testReadsTheSpondIdFromAppMetadata(): void
    {
        self::assertSame('spond-a', $this->matcher->spondId([
            'historic_winterthur' => ['spond_id' => ' spond-a '],
        ]));
        self::assertSame('spond-b', $this->matcher->spondId((object) [
            'historic_winterthur' => (object) ['spond_id' => 'spond-b'],
        ]));
        self::assertNull($this->matcher->spondId(['historic_winterthur' => ['spond_id' => '  ']]));
        self::assertNull($this->matcher->spondId(null));
        self::assertNull($this->matcher->spondId(['other' => ['spond_id' => 'spond-a']]));
    }

    public function testIgnoresAccountsThatAreNotInTheExport(): void
    {
        $matched = $this->matcher->match(['spond-a', 'spond-b'], [
            ['sub' => 'auth0|vera', 'appMetadata' => ['historic_winterthur' => ['spond_id' => 'spond-a']]],
            ['sub' => 'auth0|other', 'appMetadata' => ['historic_winterthur' => ['spond_id' => 'not-exported']]],
            ['sub' => ' ', 'appMetadata' => ['historic_winterthur' => ['spond_id' => 'spond-b']]],
            ['sub' => 'auth0|again', 'appMetadata' => ['historic_winterthur' => ['spond_id' => 'spond-a']]],
        ]);

        self::assertSame(['spond-a' => 'auth0|vera'], $matched);
    }

    public function testStoresTheSpondIdAndDoesNotInventPeople(): void
    {
        $read = (new SpondExportParser(new SpondResponseMap(), $this->matcher))->parse([
            'group' => [
                'spondId' => 'group-1',
                'name' => 'Historic Team Winterthur',
                'members' => [
                    ['spondId' => 'spond-a'],
                    ['spondId' => 'spond-b'],
                    ['spondId' => 'spond-a'],
                ],
            ],
            'event' => [
                'spondId' => 'event-1',
                'heading' => 'Führung inbahn',
                'startTimestamp' => '2026-11-14T09:30:00Z',
                'description' => 'Depotführung',
                'comment' => null,
                'responses' => [
                    'acceptedIds' => ['spond-a'],
                    'declinedIds' => ['spond-b', 'spond-c'],
                    'unansweredIds' => [],
                    'waitinglistIds' => ['spond-z'],
                ],
                'answerComments' => [
                    ['spondId' => 'spond-b', 'comment' => 'Bin verhindert'],
                    ['spondId' => 'not-exported', 'comment' => 'zählt nicht'],
                ],
                'options' => [
                    ['spondId' => 'task-1', 'name' => 'Kasse', 'description' => 'Abend', 'accepted' => 0, 'declined' => 0, 'unanswered' => 0],
                    ['name' => 'ohne Id'],
                ],
            ],
        ], [
            ['sub' => 'auth0|vera', 'appMetadata' => ['historic_winterthur' => ['spond_id' => 'spond-a']]],
            ['sub' => 'auth0|other', 'appMetadata' => (object) ['historic_winterthur' => (object) ['spond_id' => 'not-exported']]],
        ], new \DateTimeImmutable('2026-11-26T08:00:00+01:00'));

        self::assertSame('2026-11-14', $read->eventOn()->format('Y-m-d'));
        self::assertSame(['spond-a', 'spond-b'], array_map(static fn ($person) => $person->spondId(), $read->people()));
        self::assertSame('auth0|vera', $read->people()[0]->matchedSub());
        self::assertNull($read->people()[1]->matchedSub());
        self::assertSame([
            ['spond-a', SpondReply::Accepted, null, 'auth0|vera'],
            ['spond-b', SpondReply::Declined, 'Bin verhindert', null],
            ['spond-c', SpondReply::Declined, null, null],
        ], array_map(static fn ($answer) => [$answer->spondId(), $answer->reply(), $answer->comment(), $answer->matchedSub()], $read->answers()));
        self::assertSame(1, $read->matchedCount());
        self::assertSame(3, $read->storedIdCount());
        self::assertSame('Kasse', $read->options()[0]['name']);
        self::assertCount(1, $read->options());
        self::assertSame('Depotführung', $read->description());
    }

    public function testLateUtcTimestampUsesTheZurichDate(): void
    {
        $read = (new SpondExportParser(new SpondResponseMap(), $this->matcher))->parse([
            'group' => [
                'spondId' => 'group-1',
                'name' => 'Historic Team Winterthur',
                'members' => [],
            ],
            'event' => [
                'spondId' => 'event-1',
                'heading' => 'Spät',
                'startTimestamp' => '2026-11-14T23:30:00Z',
                'responses' => ['acceptedIds' => [], 'declinedIds' => [], 'unansweredIds' => []],
            ],
        ], [], new \DateTimeImmutable('2026-11-26T08:00:00+01:00'));

        self::assertSame('2026-11-15', $read->eventOn()->format('Y-m-d'));
        self::assertSame([], $read->people());
    }
}
