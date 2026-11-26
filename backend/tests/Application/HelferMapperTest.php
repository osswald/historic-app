<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Helfer\HelferMapper;
use PHPUnit\Framework\TestCase;

final class HelferMapperTest extends TestCase
{
    public function testAShiftKeepsCountsAndAPersonalAssignmentKeepsItsStatus(): void
    {
        $shift = HelferMapper::shift([
            'id' => 'shift-1',
            'function' => 'Fahrdienst / Lokführer',
            'category' => 'Fahrpersonal',
            'startsAt' => '2027-04-12T08:30:00+02:00',
            'endsAt' => '2027-04-12T17:30:00+02:00',
            'remarks' => 'Dienstkleidung erforderlich',
            'needed' => 2,
            'assigned' => 1,
            'reported' => 1,
            'open' => 1,
            'url' => 'https://helfer.historic-winterthur.ch/s/1',
        ]);
        $mine = HelferMapper::myAssignment([
            'assigned' => true,
            'url' => 'https://helfer.historic-winterthur.ch/me',
            'shifts' => [$shift],
        ]);

        self::assertSame('ext:11111111-1111-4111-8111-111111111111', HelferMapper::externalId('11111111-1111-4111-8111-111111111111'));
        self::assertSame('Fahrdienst / Lokführer', $shift['title']);
        self::assertSame('Fahrpersonal', $shift['category']);
        self::assertSame('Dienstkleidung erforderlich', $shift['remarks']);
        self::assertSame(1, $shift['assignedCount']);
        self::assertTrue($mine['assigned']);
        self::assertSame('11111111-1111-4111-8111-111111111111', HelferMapper::reference('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'));
        self::assertSame('ext:22222222-2222-4222-8222-222222222222', HelferMapper::reference(null, '22222222-2222-4222-8222-222222222222'));
        self::assertSame('https://helfer.historic-winterthur.ch/admin/veranstaltung/1', HelferMapper::plan([
            'id' => '11111111-1111-4111-8111-111111111111',
            'title' => 'Frühlingsfahrt',
            'date' => '2027-04-12',
            'url' => 'https://helfer.historic-winterthur.ch/admin/veranstaltung/1',
        ])['url']);
        self::assertSame('Anna Meier', HelferMapper::shiftPerson([
            'status' => 'zugeteilt',
            'binding' => true,
            'helper' => ['id' => 'secret', 'firstName' => 'Anna', 'lastName' => 'Meier', 'member' => true, 'email' => 'anna@example.test'],
        ])['name']);
        self::assertSame('Zugeteilt', HelferMapper::statusLabel('zugeteilt'));
        self::assertSame('Gemeldet', HelferMapper::statusLabel('gemeldet'));
    }

    public function testAPersonalAssignmentReadsTheNestedEventAndShift(): void
    {
        $payload = [
            'assigned' => true,
            'shifts' => [[
                'assignmentId' => 'a17f2590-53bc-4299-8096-7e3f89839420',
                'status' => 'zugeteilt',
                'binding' => true,
                'event' => [
                    'id' => 'b3c58b1a-2ef4-4f80-b2cb-f14d8471c26b',
                    'eventId' => 'evt-2027-04-12',
                    'title' => 'Frühlingsfahrt',
                    'date' => '2027-04-12',
                    'startTime' => '09:00',
                    'endTime' => '17:00',
                    'location' => 'Depot Deutweg',
                    'route' => 'Winterthur – Bauma',
                    'offer' => null,
                    'occasion' => null,
                    'url' => 'https://helfer.historic-winterthur.ch/admin/veranstaltung/b3c58b1a-2ef4-4f80-b2cb-f14d8471c26b',
                ],
                'shift' => [
                    'id' => 'e44c6883-9366-4122-bdf4-f9ca799741e1',
                    'eventId' => 'b3c58b1a-2ef4-4f80-b2cb-f14d8471c26b',
                    'function' => 'Fahrdienst / Lokführer',
                    'category' => 'Fahrpersonal',
                    'color' => '#1e3a8a',
                    'startsAt' => '2027-04-12T08:30:00+02:00',
                    'endsAt' => '2027-04-12T17:30:00+02:00',
                    'remarks' => 'Dienstkleidung erforderlich',
                    'needed' => 2,
                    'assigned' => 1,
                    'reported' => 1,
                    'open' => 1,
                    'url' => 'https://helfer.historic-winterthur.ch/h/TOKEN',
                ],
            ]],
            'url' => 'http://helfer.historic-winterthur.ch/h/TOKEN',
        ];

        $mine = HelferMapper::myAssignment($payload);
        $row = HelferMapper::assignment($payload['shifts'][0]);

        self::assertTrue($mine['assigned']);
        self::assertTrue($mine['binding']);
        self::assertNull($mine['url']);
        self::assertSame('Fahrdienst / Lokführer', $mine['shifts'][0]['title']);
        self::assertSame('Frühlingsfahrt', $mine['shifts'][0]['eventTitle']);
        self::assertSame('2027-04-12', $mine['shifts'][0]['date']);
        self::assertSame('08:30–17:30', $mine['shifts'][0]['time']);
        self::assertSame('https://helfer.historic-winterthur.ch/h/TOKEN', $mine['shifts'][0]['url']);
        self::assertSame($mine['shifts'][0], $row);
        self::assertFalse(HelferMapper::myAssignment([
            'assigned' => true,
            'shifts' => [['assignmentId' => 'a', 'status' => 'zugeteilt', 'binding' => false, 'shift' => ['function' => 'Kasse']]],
        ])['binding']);
    }

    public function testAPersonalListKeepsAssignedAndReportedShifts(): void
    {
        $row = static function (string $status, string $function): array {
            return [
                'assignmentId' => $status === 'zugeteilt' ? 'a17f2590-53bc-4299-8096-7e3f89839420' : 'b17f2590-53bc-4299-8096-7e3f89839420',
                'status' => $status,
                'binding' => $status === 'zugeteilt',
                'event' => [
                    'title' => 'Frühlingsfahrt',
                    'date' => '2027-04-12',
                    'url' => 'https://helfer.historic-winterthur.ch/admin/veranstaltung/1',
                ],
                'shift' => [
                    'function' => $function,
                    'startsAt' => '2027-04-12T08:30:00+02:00',
                    'endsAt' => '2027-04-12T17:30:00+02:00',
                    'url' => 'https://helfer.historic-winterthur.ch/h/TOKEN',
                ],
            ];
        };

        $assigned = HelferMapper::assignment($row('zugeteilt', 'Fahrdienst / Lokführer'));
        $reported = HelferMapper::assignment($row('gemeldet', 'Kasse'));

        self::assertSame('Zugeteilt', $assigned['statusLabel']);
        self::assertSame('Gemeldet', $reported['statusLabel']);
        self::assertSame('Frühlingsfahrt', $assigned['eventTitle']);
        self::assertSame('Frühlingsfahrt', $reported['eventTitle']);
        self::assertSame('08:30–17:30', $reported['time']);
        self::assertSame('https://helfer.historic-winterthur.ch/h/TOKEN', $assigned['url']);
        self::assertSame('https://helfer.historic-winterthur.ch/h/TOKEN', $reported['url']);
    }
}
