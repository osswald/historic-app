<?php

declare(strict_types=1);

namespace App\Tests\Domain\Nextcloud;

use App\Domain\Event\Event;
use App\Domain\Event\EventStatus;
use App\Domain\Nextcloud\ArchiveEventFolder;
use PHPUnit\Framework\TestCase;

final class ArchiveEventFolderTest extends TestCase
{
    public function testTheMoveWaitsSevenDaysAndUsesTheStartYear(): void
    {
        $event = $this->event('Veranstaltungsname', ['2026-12-30', '2027-01-02']);
        $policy = new ArchiveEventFolder();
        $zone = new \DateTimeZone('Europe/Zurich');

        self::assertNull($policy->directory($event, new \DateTimeImmutable('2027-01-08', $zone)));
        self::assertSame(
            'Fahrten_Anlässe/_ARCHIV/2026',
            $policy->directory($event, new \DateTimeImmutable('2027-01-09', $zone)),
        );
    }

    public function testAMonthCrossingStillArchivesUnderTheStartYear(): void
    {
        $event = $this->event('Frühlingsfahrt', ['2026-05-30', '2026-06-02']);
        $policy = new ArchiveEventFolder();
        $zone = new \DateTimeZone('Europe/Zurich');

        self::assertNull($policy->directory($event, new \DateTimeImmutable('2026-06-08', $zone)));
        self::assertSame(
            'Fahrten_Anlässe/_ARCHIV/2026',
            $policy->directory($event, new \DateTimeImmutable('2026-06-09', $zone)),
        );
    }

    public function testSameMonthIsDueSevenDaysAfterTheLastDay(): void
    {
        $event = $this->event('Frühlingsfahrt', ['2026-05-15', '2026-05-17']);
        $policy = new ArchiveEventFolder();
        $zone = new \DateTimeZone('Europe/Zurich');

        self::assertNull($policy->directory($event, new \DateTimeImmutable('2026-05-23', $zone)));
        self::assertSame(
            'Fahrten_Anlässe/_ARCHIV/2026',
            $policy->directory($event, new \DateTimeImmutable('2026-05-24', $zone)),
        );
    }

    /** @param list<string> $dates */
    private function event(string $title, array $dates): Event
    {
        $zone = new \DateTimeZone('Europe/Zurich');
        $event = new Event($title, ['historic'], [], EventStatus::Published, null, null, null, false, new \DateTimeImmutable('2026-01-01', $zone));
        foreach ($dates as $index => $date) {
            $event->addDay(new \DateTimeImmutable($date, $zone), null, null, null, $index);
        }

        return $event;
    }
}
