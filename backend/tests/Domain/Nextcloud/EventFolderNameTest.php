<?php

declare(strict_types=1);

namespace App\Tests\Domain\Nextcloud;

use App\Domain\Event\Event;
use App\Domain\Event\EventStatus;
use App\Domain\Nextcloud\EventFolderName;
use App\Domain\Nextcloud\EventHasNoDate;
use PHPUnit\Framework\TestCase;

final class EventFolderNameTest extends TestCase
{
    public function testOneDayUsesThatDate(): void
    {
        self::assertSame('2026-05-15 Frühlingsfahrt', EventFolderName::fromTitle('Frühlingsfahrt', ['2026-05-15']));
    }

    public function testSeveralDaysInTheSameMonthUseStartAndEndDay(): void
    {
        self::assertSame(
            '2026-05-15-17 Frühlingsfahrt',
            EventFolderName::fromTitle('Frühlingsfahrt', ['2026-05-17', '2026-05-15', '2026-05-16']),
        );
        self::assertSame('2026-05-08-09 Frühlingsfahrt', EventFolderName::fromTitle('Frühlingsfahrt', ['2026-05-09', '2026-05-08']));
    }

    public function testAMonthCrossingKeepsOnlyTheStartDate(): void
    {
        self::assertSame(
            '2026-05-30 Frühlingsfahrt',
            EventFolderName::fromTitle('Frühlingsfahrt', ['2026-06-02', '2026-05-30']),
        );
    }

    public function testAYearCrossingKeepsTheDayStillInTheOldYear(): void
    {
        self::assertSame(
            '2026-12-30 Veranstaltungsname',
            EventFolderName::fromTitle('Veranstaltungsname', ['2027-01-02', '2026-12-30']),
        );
        $event = new Event(
            'Veranstaltungsname',
            ['historic'],
            null,
            EventStatus::Published,
            null,
            null,
            null,
            false,
            new \DateTimeImmutable('2026-12-01', new \DateTimeZone('Europe/Zurich')),
        );
        $event->addDay(new \DateTimeImmutable('2027-01-02', new \DateTimeZone('Europe/Zurich')), null, null, null, 1);
        $event->addDay(new \DateTimeImmutable('2026-12-30', new \DateTimeZone('Europe/Zurich')), null, null, null, 0);

        self::assertSame('2026-12-30 Veranstaltungsname', EventFolderName::forEvent($event));
    }

    public function testASlashDoesNotCreateANestedFolder(): void
    {
        self::assertSame('2026-05-15 Fahrt Zürich', EventFolderName::fromTitle(" Fahrt/Zürich \n", ['2026-05-15']));
    }

    public function testAnEventWithoutADateHasNoFolderName(): void
    {
        $this->expectException(EventHasNoDate::class);
        EventFolderName::fromTitle('Veranstaltungsname', []);
    }
}
