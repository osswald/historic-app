<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Spond\AttachSpondParticipation;
use App\Application\Spond\ImportSpondAttendance;
use App\Application\Spond\ImportSpondCalendar;
use App\Domain\Event\Attendance;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventResponse;
use App\Domain\Event\EventResponseRepository;
use App\Domain\Event\EventStatus;
use App\Domain\Fleet\Vehicle;
use App\Domain\Spond\SpondRead;
use App\Domain\Spond\SpondReply;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SpondOwnAnswerTest extends TestCase
{
    public function testSpondAnswersBecomeLocalJaAndNeinOnTheEvent(): void
    {
        $events = new AttendanceEventRepository();
        $calendar = new ImportSpondCalendar($events);
        $now = new \DateTimeImmutable('2026-11-26T08:00:00+01:00');
        $imported = $calendar->import($this->payload('Führung'), $now);
        $this->apply(new ImportSpondAttendance(), $this->read(SpondReply::Accepted, 'Ada Beispiel', 'Bea Beispiel'), $imported, $now);

        $event = $events->bySpondEventId('spond-1');
        self::assertInstanceOf(Event::class, $event);
        $rows = $this->bySpond($event);
        self::assertSame(Attendance::Yes, $rows['member-1']->answer());
        self::assertSame('Ja', $rows['member-1']->answer()->label());
        self::assertSame('Ada Beispiel', $rows['member-1']->respondentName());
        self::assertSame('auth0|ada', $rows['member-1']->auth0Sub());
        self::assertSame('member-1', $rows['member-1']->spondMemberId());
        self::assertFalse($rows['member-1']->editedInApp());

        self::assertSame(Attendance::No, $rows['member-2']->answer());
        self::assertSame('Nein', $rows['member-2']->answer()->label());
        self::assertNull($rows['member-2']->auth0Sub());
        self::assertSame('Bea Beispiel', $rows['member-2']->respondentName());
        self::assertSame('member-2', $rows['member-2']->spondMemberId());
        self::assertSame('Bin verhindert', $rows['member-2']->comment());
        self::assertArrayNotHasKey('member-3', $rows);
    }

    public function testASecondReadKeepsLocalEditsNamesAndTheRestOfTheEvent(): void
    {
        $events = new AttendanceEventRepository();
        $calendar = new ImportSpondCalendar($events);
        $attendance = new ImportSpondAttendance();
        $now = new \DateTimeImmutable('2026-11-26T08:00:00+01:00');
        $imported = $calendar->import($this->payload('Führung'), $now);
        $this->apply($attendance, $this->read(SpondReply::Accepted, 'Ada Beispiel', 'Bea Beispiel'), $imported, $now);

        $event = $events->bySpondEventId('spond-1');
        self::assertInstanceOf(Event::class, $event);
        $eventId = $event->id();
        $kept = $event->days()[0]->responseForSpond('member-1');
        self::assertInstanceOf(EventResponse::class, $kept);
        $keptId = $kept->id();
        $event->days()[0]->answer('auth0|ada', 'Ada Beispiel', Attendance::No, 'Selbst geändert', $now);
        $event->change(
            $event->title(),
            ['historic'],
            ['Spond'],
            EventStatus::Draft,
            $event->internalDescription(),
            $event->publicDescription(),
            $event->location(),
            false,
            $now,
        );
        $vehicle = $this->createStub(Vehicle::class);
        $vehicle->method('id')->willReturn('vehicle-1');
        $event->replaceAssignedVehicles([['vehicle' => $vehicle, 'rentalStatus' => null]]);
        $event->addDay(new \DateTimeImmutable('2026-11-16'), null, null, 'Zusatztag', 1);

        $again = $calendar->import($this->payload('Führung aktualisiert'), $now->modify('+1 hour'));
        $this->apply($attendance, $this->read(SpondReply::Accepted, null, null, SpondReply::Accepted), $again, $now->modify('+1 hour'));

        self::assertSame($eventId, $event->id());
        self::assertSame('Führung aktualisiert', $event->title());
        self::assertSame(EventStatus::Draft, $event->status());
        self::assertCount(1, $event->assignedVehicles());
        self::assertCount(2, $event->days());
        self::assertSame('2026-11-16', $event->days()[1]->occursOn()->format('Y-m-d'));
        self::assertSame('Zusatztag', $event->days()[1]->location());
        self::assertSame([], $event->days()[1]->responses());

        $rows = $this->bySpond($event);
        self::assertSame($keptId, $rows['member-1']->id());
        self::assertSame(Attendance::No, $rows['member-1']->answer());
        self::assertSame('Nein', $rows['member-1']->answer()->label());
        self::assertSame('Selbst geändert', $rows['member-1']->comment());
        self::assertTrue($rows['member-1']->editedInApp());
        self::assertSame('Ada Beispiel', $rows['member-1']->respondentName());
        self::assertSame(Attendance::Yes, $rows['member-2']->answer());
        self::assertSame('Ja', $rows['member-2']->answer()->label());
        self::assertSame('Bea Beispiel', $rows['member-2']->respondentName());
        self::assertNull($rows['member-2']->comment());
        self::assertArrayNotHasKey('member-3', $rows);
    }

    public function testUnansweredRemovesOnlyTheImportedAnswer(): void
    {
        $events = new AttendanceEventRepository();
        $calendar = new ImportSpondCalendar($events);
        $attendance = new ImportSpondAttendance();
        $now = new \DateTimeImmutable('2026-11-26T08:00:00+01:00');
        $imported = $calendar->import($this->payload('Führung'), $now);
        $this->apply($attendance, $this->read(SpondReply::Accepted, 'Ada Beispiel', 'Bea Beispiel'), $imported, $now);
        $event = $events->bySpondEventId('spond-1');
        self::assertInstanceOf(Event::class, $event);
        $event->days()[0]->answer('auth0|ada', 'Ada Beispiel', Attendance::No, null, $now);

        $this->apply($attendance, $this->read(SpondReply::Unanswered, 'Ada Beispiel', 'Bea Beispiel', SpondReply::Unanswered), $imported, $now->modify('+1 hour'));

        $rows = $this->bySpond($event);
        self::assertSame(Attendance::No, $rows['member-1']->answer());
        self::assertArrayNotHasKey('member-2', $rows);
        self::assertArrayNotHasKey('member-3', $rows);
    }

    public function testLoginAttachesTheStoredRowWithoutChangingJaOrTheName(): void
    {
        $events = new AttendanceEventRepository();
        $now = new \DateTimeImmutable('2026-11-26T08:00:00+01:00');
        $imported = (new ImportSpondCalendar($events))->import($this->payload('Führung'), $now);
        $this->apply(new ImportSpondAttendance(), $this->read(SpondReply::Declined, 'Ada Beispiel', 'Bea Beispiel'), $imported, $now);
        $event = $events->bySpondEventId('spond-1');
        self::assertInstanceOf(Event::class, $event);
        $row = $event->days()[0]->responseForSpond('member-2');
        self::assertInstanceOf(EventResponse::class, $row);
        $id = $row->id();

        $attach = new AttachSpondParticipation(
            new ListSpondResponses([$row]),
            $this->createStub(EntityManagerInterface::class),
        );
        $attach(new MemberUser('auth0|bea', 'Bea aus Auth0', ['Mitglieder'], null, ['historic'], 'member-2'));

        self::assertSame($id, $row->id());
        self::assertSame('auth0|bea', $row->auth0Sub());
        self::assertSame('member-2', $row->spondMemberId());
        self::assertSame(Attendance::No, $row->answer());
        self::assertSame('Nein', $row->answer()->label());
        self::assertSame('Bea Beispiel', $row->respondentName());
        self::assertFalse($row->editedInApp());
    }

    /** @param list<Event> $events */
    private function apply(ImportSpondAttendance $attendance, SpondRead $read, array $events, \DateTimeImmutable $now): void
    {
        foreach ($attendance($read, $events, $now) as $finish) {
            $finish();
        }
    }

    /** @return array<string, EventResponse> */
    private function bySpond(Event $event): array
    {
        $rows = [];
        foreach ($event->days()[0]->responses() as $response) {
            $spondId = $response->spondMemberId();
            if ($spondId !== null) {
                $rows[$spondId] = $response;
            }
        }

        return $rows;
    }

    private function read(
        SpondReply $ada,
        ?string $adaName,
        ?string $beaName,
        SpondReply $bea = SpondReply::Declined,
    ): SpondRead {
        $read = new SpondRead('Historic Team Winterthur', 'group-1', new \DateTimeImmutable('2026-11-26T08:00:00+01:00'));
        $read->addPerson('member-1', 'auth0|ada', $adaName);
        $read->addPerson('member-2', null, $beaName);
        $read->addPerson('member-3', null, 'Cleo Beispiel');
        $event = $read->addEvent('spond-1', 'Führung', new \DateTimeImmutable('2026-11-14'), null, null, []);
        $event->addAnswer('member-1', $ada, null, 'auth0|ada');
        $event->addAnswer('member-2', $bea, $bea === SpondReply::Declined ? 'Bin verhindert' : null, null);
        $event->addAnswer('member-3', SpondReply::Unanswered, null, null);

        return $read;
    }

    /** @return array<string, mixed> */
    private function payload(string $heading): array
    {
        return [
            'events' => [[
                'spondId' => 'spond-1',
                'heading' => $heading,
                'startTimestamp' => '2026-11-14T09:00:00+01:00',
                'endTimestamp' => '2026-11-14T12:00:00+01:00',
                'description' => 'Besichtigung',
            ]],
        ];
    }
}

final class AttendanceEventRepository implements EventRepository
{
    /** @var array<string, Event> */
    private array $events = [];

    public function add(Event $event): void
    {
        $id = $event->spondEventId();
        if ($id !== null) {
            $this->events[$id] = $event;
        }
    }

    public function byId(string $id): ?Event
    {
        foreach ($this->events as $event) {
            if ($event->id() === $id) {
                return $event;
            }
        }

        return null;
    }

    public function bySpondEventId(string $spondEventId): ?Event
    {
        return $this->events[$spondEventId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->events);
    }
}

final class ListSpondResponses implements EventResponseRepository
{
    /** @param list<EventResponse> $rows */
    public function __construct(private array $rows)
    {
    }

    public function bySpondMemberId(string $spondMemberId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (EventResponse $row): bool => $row->spondMemberId() === $spondMemberId,
        ));
    }
}
