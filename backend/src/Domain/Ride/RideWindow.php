<?php

declare(strict_types=1);

namespace App\Domain\Ride;

use App\Domain\Event\EventDay;

/** Belegung eines Vereinsfahrzeugs: Zeitfenster, einzelner Zeitpunkt oder der ganze Veranstaltungstag. */
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

    public static function occupying(
        EventDay $day,
        ?DayOffset $departureOffset,
        ?\DateTimeImmutable $departureTime,
        ?DayOffset $arrivalOffset,
        ?\DateTimeImmutable $arrivalTime,
    ): self {
        $departure = self::knownMoment($day, $departureOffset, $departureTime);
        $arrival = self::knownMoment($day, $arrivalOffset, $arrivalTime);
        if ($departure instanceof \DateTimeImmutable && $arrival instanceof \DateTimeImmutable) {
            return new self($departure, $arrival);
        }
        if ($departure instanceof \DateTimeImmutable) {
            return new self($departure, $departure);
        }
        if ($arrival instanceof \DateTimeImmutable) {
            return new self($arrival, $arrival);
        }

        $start = self::dayStart($day);
        $end = $start->modify('+1 day');
        if (!$end instanceof \DateTimeImmutable) {
            throw new \LogicException('Der Veranstaltungstag hat kein Ende.');
        }

        return new self($start, $end);
    }

    public static function forEvent(\App\Domain\Event\Event $event): self
    {
        $days = $event->days();
        if ($days === []) {
            throw new \LogicException('Die Veranstaltung hat keinen Zeitraum.');
        }
        $start = self::dayStart($days[0]);
        $end = self::dayStart($days[array_key_last($days)])->modify('+1 day');
        if (!$end instanceof \DateTimeImmutable) {
            throw new \LogicException('Der Veranstaltungszeitraum hat kein Ende.');
        }

        return new self($start, $end);
    }

    public function isOrdered(): bool
    {
        return $this->arrivesAt > $this->departsAt;
    }

    public function overlaps(self $other): bool
    {
        if ($this->isInstant() && $other->isInstant()) {
            return $this->departsAt == $other->departsAt;
        }
        if ($this->isInstant()) {
            return $this->departsAt >= $other->departsAt && $this->departsAt < $other->arrivesAt;
        }
        if ($other->isInstant()) {
            return $other->departsAt >= $this->departsAt && $other->departsAt < $this->arrivesAt;
        }

        return $this->departsAt < $other->arrivesAt && $other->departsAt < $this->arrivesAt;
    }

    private function isInstant(): bool
    {
        return $this->departsAt == $this->arrivesAt;
    }

    private static function knownMoment(EventDay $day, ?DayOffset $offset, ?\DateTimeImmutable $time): ?\DateTimeImmutable
    {
        if (!$offset instanceof DayOffset || !$time instanceof \DateTimeImmutable) {
            return null;
        }

        return self::moment($day, $offset, $time);
    }

    private static function dayStart(EventDay $day): \DateTimeImmutable
    {
        $zone = new \DateTimeZone('Europe/Zurich');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day->occursOn()->format('Y-m-d'), $zone);
        if (!$date instanceof \DateTimeImmutable) {
            throw new \LogicException('Der Veranstaltungstag hat kein Datum.');
        }

        return $date;
    }

    private static function moment(EventDay $day, DayOffset $offset, \DateTimeImmutable $time): \DateTimeImmutable
    {
        $shifted = self::dayStart($day)->modify(sprintf('%+d days', $offset->value));
        if (!$shifted instanceof \DateTimeImmutable) {
            throw new \LogicException('Der Tagversatz ist ungültig.');
        }

        return $shifted->setTime((int) $time->format('H'), (int) $time->format('i'));
    }
}
