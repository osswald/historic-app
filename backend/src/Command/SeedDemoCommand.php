<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Event\KnownEventCategories;
use App\Domain\Event\Attendance;
use App\Domain\Event\EventCategory;
use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;
use App\Domain\Event\Place;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Domain\Fleet\VehicleStatus;
use App\Domain\Poll\Daypart;
use App\Domain\Poll\Poll;
use App\Domain\Poll\PollKind;
use App\Domain\Poll\PollRepository;
use App\Domain\Poll\PollStatus;
use App\Domain\Ride\DayOffset;
use App\Domain\Ride\Ride;
use App\Domain\Ride\RideRepository;
use App\Security\DevTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed-demo', description: 'Legt die Beispielveranstaltungen, eine Beispiel-Fahrt, Beispielfahrzeuge und die Depottag-Umfrage an, falls sie noch fehlen.')]
final class SeedDemoCommand extends Command
{
    private const OBERLAND_RIDE = '6f1a9b10-3333-4333-8333-333333333333';
    private const BE46 = '6f1a9b10-5555-4555-8555-555555555551';
    private const BDE = '6f1a9b10-5555-4555-8555-555555555552';
    private const SPEISE = '6f1a9b10-5555-4555-8555-555555555553';
    private const DEPOT_POLL = '6f1a9b10-4444-4444-8444-444444444441';

    /** @var array<string, Place> */
    private array $places = [];

    public function __construct(
        private EventRepository $events,
        private KnownEventCategories $categories,
        private PollRepository $polls,
        private RideRepository $rides,
        private VehicleRepository $vehicles,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich'));
        $seeded = false;

        if ($this->vehicles->all() === []) {
            $this->seedVehicles($now);
            $output->writeln('Drei Beispielfahrzeuge aus der Designstudie wurden angelegt.');
            $seeded = true;
        }

        if ($this->events->all() === []) {
            $this->seedEvents($now);
            $output->writeln('Drei Beispielveranstaltungen wurden angelegt.');
            $seeded = true;
        } elseif ($this->backfillOberlandRide()) {
            $output->writeln('Die Beispiel-Fahrt der Vereinsfahrt Zürcher Oberland wurde angelegt.');
            $seeded = true;
        }

        if ($this->polls->all() === []) {
            $this->seedDepotPoll($now);
            $output->writeln('Die Depottage-Umfrage Januar 2027 wurde angelegt (alle Kalendertage).');
            $seeded = true;
        } elseif ($this->expandDepotPollToFullMonth()) {
            $output->writeln('Die Depottage-Umfrage Januar 2027 wurde auf alle Kalendertage ergänzt.');
            $seeded = true;
        }

        if (!$seeded) {
            $output->writeln('Beispieldaten sind bereits vorhanden. Es wurde nichts ergänzt.');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    private function seedEvents(\DateTimeImmutable $now): void
    {
        $depot = $this->make(
            '6f1a9b10-1111-4111-8111-111111111111',
            'Depottag Winterthur',
            ['historic'],
            [],
            'Lok-Depot-Ost · Winterthur',
            null,
            '6f1a9b10-1111-4111-8111-111111111112',
            '2026-11-28',
            '09:00',
            '13:00',
            'Lok-Depot-Ost · Winterthur',
            'Lok-Depot-Ost',
            'Besammlung im Depot.',
            $now,
        );
        $this->events->add($depot);

        $supper = $this->make(
            '6f1a9b10-2222-4222-8222-222222222221',
            'Spaghettiplausch',
            ['supporter'],
            ['Gastronomie'],
            'Supporterclub',
            null,
            '6f1a9b10-2222-4222-8222-222222222222',
            '2026-12-16',
            '18:00',
            '22:00',
            'Supporterclub',
            'Supporterclub',
            null,
            $now,
        );
        $supper->day('6f1a9b10-2222-4222-8222-222222222222')?->answer(
            DevTokenAuthenticator::SUBJECT,
            'Demo Mitglied',
            Attendance::Yes,
            null,
            $now,
        );
        $this->events->add($supper);

        $outing = $this->make(
            '6f1a9b10-3333-4333-8333-333333333331',
            'Vereinsfahrt Zürcher Oberland',
            ['historic'],
            ['Historische Sonderfahrt'],
            'Winterthur · Bauma · Hinwil',
            'Historischer Sonderzug durch das Zürcher Oberland. Details zur Fahrtenplanung und Besammlung siehe unten.',
            '6f1a9b10-3333-4333-8333-333333333332',
            '2027-05-15',
            '09:10',
            '17:30',
            'Winterthur · Bauma · Hinwil',
            'Winterthur HB',
            'Besammlung 08:35, Perron gemäss Einladung.',
            $now,
        );
        $this->events->add($outing);
        $day = $outing->day('6f1a9b10-3333-4333-8333-333333333332');
        $ride = $day instanceof EventDay ? $this->demoRide($outing, $day) : null;
        if ($ride instanceof Ride) {
            $this->rides->add($ride);
        }
    }

    private function backfillOberlandRide(): bool
    {
        foreach ($this->events->all() as $event) {
            if ($event->title() !== 'Vereinsfahrt Zürcher Oberland') {
                continue;
            }
            if ($this->rides->forEvent($event->id()) !== []) {
                return false;
            }
            $day = $this->oberlandDay($event);
            if (!$day instanceof EventDay) {
                return false;
            }
            $ride = $this->demoRide($event, $day);
            if (!$ride instanceof Ride) {
                return false;
            }
            $this->rides->add($ride);

            return true;
        }

        return false;
    }

    private function oberlandDay(Event $event): ?EventDay
    {
        foreach ($event->days() as $day) {
            if ($day->occursOn()->format('Y-m-d') === '2027-05-15') {
                return $day;
            }
        }

        return $event->days()[0] ?? null;
    }

    private function seedVehicles(\DateTimeImmutable $now): void
    {
        $this->vehicles->add(new Vehicle('Be 4/6', null, VehicleStatus::Ready, null, true, 0, $now, self::BE46));
        $this->vehicles->add(new Vehicle('BDe 4/4', '1643', VehicleStatus::Limited, 'Eingeschränkt bis zur nächsten Kontrolle.', true, 1, $now, self::BDE));
        $this->vehicles->add(new Vehicle('Speisewagen Dr', null, VehicleStatus::Ready, null, false, 2, $now, self::SPEISE));
    }

    private function speisewagen(): ?Vehicle
    {
        $known = $this->vehicles->get(self::SPEISE);
        if ($known instanceof Vehicle) {
            return $known;
        }
        foreach ($this->vehicles->all() as $vehicle) {
            if ($vehicle->name() === 'Speisewagen Dr') {
                return $vehicle;
            }
        }

        return null;
    }

    private function demoRide(Event $event, EventDay $day): ?Ride
    {
        $vehicle = $this->speisewagen();
        if (!$vehicle instanceof Vehicle) {
            return null;
        }
        $ride = new Ride(
            $event,
            $day,
            $this->time('09:10'),
            DayOffset::Same,
            $this->time('17:30'),
            DayOffset::Same,
            'Beispiel',
            '8506000',
            'Winterthur',
            'W',
            '8503130',
            'Hinwil',
            'HI',
            null,
            0,
            self::OBERLAND_RIDE,
        );
        $event->replaceAssignedVehicles([[
            'vehicle' => $vehicle,
            'rentalStatus' => null,
        ]]);
        $ride->replaceAssignments([$vehicle]);

        return $ride;
    }

    private function seedDepotPoll(\DateTimeImmutable $now): void
    {
        $poll = new Poll(
            'Depottage Januar 2027',
            PollKind::Depot,
            PollStatus::Open,
            'Bitte gib deine Verfügbarkeit an.',
            'Lok-Depot-Ost · Winterthur',
            ['historic'],
            null,
            $now,
            self::DEPOT_POLL,
        );
        $this->addJanuary2027Days($poll);
        $this->polls->add($poll);
    }

    private function expandDepotPollToFullMonth(): bool
    {
        $poll = $this->polls->byId(self::DEPOT_POLL);
        if (!$poll instanceof Poll || $poll->kind() !== PollKind::Depot) {
            return false;
        }

        $before = count($poll->options());
        $this->addJanuary2027Days($poll);

        return count($poll->options()) > $before;
    }

    private function addJanuary2027Days(Poll $poll): void
    {
        $existing = [];
        foreach ($poll->options() as $option) {
            $daypart = $option->daypart();
            if (!$daypart instanceof Daypart) {
                continue;
            }
            $existing[$option->occursOn()->format('Y-m-d').'|'.$daypart->value] = true;
        }

        $sort = count($poll->options());
        for ($day = 1; $day <= 31; ++$day) {
            $date = $this->date(sprintf('2027-01-%02d', $day));
            foreach ([Daypart::Morning, Daypart::Afternoon, Daypart::Evening] as $daypart) {
                $key = $date->format('Y-m-d').'|'.$daypart->value;
                if (isset($existing[$key])) {
                    continue;
                }
                $poll->addOption($date, $daypart, null, null, $sort);
                ++$sort;
            }
        }
    }

    /** @param list<string> $names */
    private function category(array $names): ?EventCategory
    {
        foreach ($names as $name) {
            $trimmed = trim($name);
            if ($trimmed !== '') {
                return $this->categories->named($trimmed);
            }
        }

        return null;
    }

    /** @param list<string> $clubs
     *  @param list<string> $categories
     */
    private function make(
        string $id,
        string $title,
        array $clubs,
        array $categories,
        string $location,
        ?string $internalDescription,
        string $dayId,
        string $date,
        string $start,
        string $end,
        string $dayLocation,
        string $meetingName,
        ?string $meetingDescription,
        \DateTimeImmutable $now,
    ): Event {
        $event = new Event(
            $title,
            $clubs,
            $this->category($categories),
            EventStatus::Published,
            $internalDescription,
            null,
            $location,
            false,
            $now,
            $id,
        );
        $day = $event->addDay(
            $this->date($date),
            $this->time($start),
            $this->time($end),
            $dayLocation,
            0,
            $dayId,
        );
        if ($meetingName !== '') {
            $day->replaceMeetingPoints([[
                'place' => $this->place($meetingName),
                'meetsAt' => $this->time($start),
                'description' => $meetingDescription,
            ]]);
        }
        if ($dayLocation !== '') {
            $this->place($dayLocation);
        }

        return $event;
    }

    private function place(string $name): Place
    {
        if (isset($this->places[$name])) {
            return $this->places[$name];
        }
        $place = new Place($name);
        $this->entityManager->persist($place);
        $this->places[$name] = $place;

        return $place;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Ungültiges Seed-Datum.');
        }

        return $date;
    }

    private function time(string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);
        if (!$time instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Ungültige Seed-Zeit.');
        }

        return $time;
    }
}
