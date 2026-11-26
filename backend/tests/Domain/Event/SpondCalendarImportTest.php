<?php

declare(strict_types=1);

namespace App\Tests\Domain\Event;

use App\Application\Spond\ImportSpondCalendar;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventStatus;
use App\Domain\Fleet\Vehicle;
use PHPUnit\Framework\TestCase;

final class SpondCalendarImportTest extends TestCase
{
    public function testASecondReadKeepsStatusAndVehicles(): void
    {
        $events = new MemoryEventRepository();
        $import = new ImportSpondCalendar($events);
        $now = new \DateTimeImmutable('2026-11-26T08:00:00+01:00');

        $import($this->payload('Führung'), $now);
        $event = $events->bySpondEventId('spond-1');
        self::assertInstanceOf(Event::class, $event);
        self::assertSame(EventStatus::Published, $event->status());
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
        $vehicle = $this->createMock(Vehicle::class);
        $vehicle->method('id')->willReturn('vehicle-1');
        $event->replaceAssignedVehicles([['vehicle' => $vehicle, 'rentalStatus' => null]]);

        $import($this->payload('Führung aktualisiert'), $now->modify('+1 hour'));

        self::assertSame('Führung aktualisiert', $event->title());
        self::assertSame(EventStatus::Draft, $event->status());
        self::assertCount(1, $event->assignedVehicles());
        self::assertSame('2026-11-14', $event->days()[0]->occursOn()->format('Y-m-d'));
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

final class MemoryEventRepository implements EventRepository
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
