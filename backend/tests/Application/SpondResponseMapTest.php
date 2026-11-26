<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Spond\SpondIdMatcher;
use App\Application\Spond\SpondResponseMap;
use App\Domain\Spond\SpondReply;
use PHPUnit\Framework\TestCase;

final class SpondResponseMapTest extends TestCase
{
    private SpondResponseMap $map;

    protected function setUp(): void
    {
        $this->map = new SpondResponseMap();
    }

    public function testMapsTheThreeResponseLists(): void
    {
        $answers = $this->map->answers([
            'acceptedIds' => ['spond-a', 'spond-b'],
            'declinedIds' => ['spond-c'],
            'unansweredIds' => ['spond-d'],
            'waitinglistIds' => ['spond-e'],
            'unconfirmedIds' => ['spond-f'],
        ]);

        self::assertSame([
            ['spondId' => 'spond-a', 'answer' => SpondReply::Accepted],
            ['spondId' => 'spond-b', 'answer' => SpondReply::Accepted],
            ['spondId' => 'spond-c', 'answer' => SpondReply::Declined],
            ['spondId' => 'spond-d', 'answer' => SpondReply::Unanswered],
        ], $answers);
    }

    public function testKeepsTheFirstAnswerWhenAnIdIsListedTwice(): void
    {
        $answers = $this->map->answers([
            'acceptedIds' => ['spond-a'],
            'declinedIds' => ['spond-a', ' spond-b '],
            'unansweredIds' => ['spond-b', 12, ''],
        ]);

        self::assertSame([
            ['spondId' => 'spond-a', 'answer' => SpondReply::Accepted],
            ['spondId' => 'spond-b', 'answer' => SpondReply::Declined],
        ], $answers);
    }
}
