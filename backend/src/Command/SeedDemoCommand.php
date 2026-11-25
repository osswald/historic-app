<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Event\Attendance;
use App\Domain\Event\Event;
use App\Domain\Event\EventDay;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleRepository;
use App\Domain\Fleet\VehicleStatus;
use App\Domain\Poll\Daypart;
use App\Domain\Poll\Poll;
use App\Domain\Poll\PollKind;
use App\Domain\Poll\PollRepository;
use App\Domain\Poll\PollStatus;
use App\Domain\Ride\AssignmentKind;
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

    public function __construct(
        private EventRepository $events,
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
            $output->writeln('Die Depottage-Umfrage Januar 2027 wurde angelegt.');
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
        $this->vehicles->add(new Vehicle('BDe 4/4', '1643', VehicleStatus::Limited, null, true, 1, $now, self::BDE));
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
            '8503130',
            'Hinwil',
            null,
            0,
            self::OBERLAND_RIDE,
        );
        $ride->replaceAssignments([[
            'kind' => AssignmentKind::Own,
            'vehicle' => $vehicle,
            'externalName' => null,
        ]]);

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
            '6f1a9b10-4444-4444-8444-444444444441',
        );
        $sort = 0;
        foreach (['2027-01-02', '2027-01-03', '2027-01-09', '2027-01-10', '2027-01-16', '2027-01-17', '2027-01-23', '2027-01-24', '2027-01-30', '2027-01-31'] as $date) {
            foreach ([Daypart::Morning, Daypart::Afternoon, Daypart::Evening] as $daypart) {
                $poll->addOption($this->date($date), $daypart, null, null, $sort);
                ++$sort;
            }
        }
        $this->polls->add($poll);
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
            $categories,
            EventStatus::Published,
            $internalDescription,
            null,
            $location,
            false,
            $now,
            $id,
        );
        $event->addDay(
            $this->date($date),
            $this->time($start),
            $this->time($end),
            $dayLocation,
            $meetingName,
            $meetingDescription,
            0,
            $dayId,
        );

        return $event;
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
