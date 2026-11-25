<?php

declare(strict_types=1);

namespace App\Domain\Ride;

use App\Domain\Event\EventDay;

/** Absolute Abfahrt und Ankunft einer Fahrt, ohne Zwischenhalte. */
final class RideWindow
{
    private function __construct(
        private \DateTimeImmutable $departsAt,
        private \DateTimeImmutable $arrivesAt,
    ) {
    }

    public static function on(
        EventDay $day,
        DayOffset $departureOffset,
        \DateTimeImmutable $departureTime,
        DayOffset $arrivalOffset,
        \DateTimeImmutable $arrivalTime,
    ): self {
        return new self(
            self::moment($day, $departureOffset, $departureTime),
            self::moment($day, $arrivalOffset, $arrivalTime),
        );
    }

    public function isOrdered(): bool
    {
        return $this->arrivesAt > $this->departsAt;
    }

    public function overlaps(self $other): bool
    {
        return $this->departsAt < $other->arrivesAt && $other->departsAt < $this->arrivesAt;
    }

    private static function moment(EventDay $day, DayOffset $offset, \DateTimeImmutable $time): \DateTimeImmutable
    {
        $zone = new \DateTimeZone('Europe/Zurich');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day->occursOn()->format('Y-m-d'), $zone);
        if (!$date instanceof \DateTimeImmutable) {
            throw new \LogicException('Der Veranstaltungstag hat kein Datum.');
        }
        $shifted = $date->modify(sprintf('%+d days', $offset->value));
        if (!$shifted instanceof \DateTimeImmutable) {
            throw new \LogicException('Der Tagversatz ist ungültig.');
        }

        return $shifted->setTime((int) $time->format('H'), (int) $time->format('i'));
    }
}
