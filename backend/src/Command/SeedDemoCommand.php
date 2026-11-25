<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Event\Attendance;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;
use App\Security\DevTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed-demo', description: 'Legt die Beispielveranstaltungen der Designstudie an, falls noch keine vorhanden sind.')]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private EventRepository $events,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->events->all() !== []) {
            $output->writeln('Es sind bereits Veranstaltungen vorhanden. Es wurde nichts ergänzt.');

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich'));
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

        $ride = $this->make(
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
        $this->events->add($ride);
        $this->entityManager->flush();
        $output->writeln('Drei Beispielveranstaltungen wurden angelegt.');

        return Command::SUCCESS;
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
