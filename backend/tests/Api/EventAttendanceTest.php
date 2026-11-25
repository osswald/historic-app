<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EventAttendanceTest extends ApiTestCase
{
    public function testOrganizerCanCreateAndUpdateAnEvent(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client, 'Fahrt nach Zürich');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Fahrt nach Zürich', $created['title']);
        self::assertSame('published', $created['status']);
        self::assertSame(['historic'], $created['clubs']);
        self::assertIsString($created['days'][0]['id'] ?? null);
        self::assertNotSame('', $created['days'][0]['id']);

        $this->request($client, 'GET', '/api/v1/events');
        self::assertResponseIsSuccessful();
        $list = $this->jsonBody($client);
        self::assertSame('Fahrt nach Zürich', $list['events'][0]['title']);
        self::assertArrayHasKey('today', $list);

        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'], $this->payload('Fahrt nach Zürich überarbeitet'));
        self::assertResponseIsSuccessful();
        self::assertSame('Fahrt nach Zürich überarbeitet', $this->jsonBody($client)['title']);
    }

    public function testMemberCanAnswerYesAndNo(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client);
        $dayId = $created['days'][0]['id'];

        $this->request($client, 'POST', '/api/v1/event-days/'.$dayId.'/responses', ['answer' => 'yes', 'comment' => 'Ich bin dabei.']);
        self::assertResponseIsSuccessful();
        self::assertSame('yes', $this->jsonBody($client)['days'][0]['myResponse']);
        self::assertSame('Ich bin dabei.', $this->jsonBody($client)['days'][0]['myComment']);
        self::assertSame(1, $this->jsonBody($client)['days'][0]['summary']['yes']);

        $this->request($client, 'POST', '/api/v1/event-days/'.$dayId.'/responses', ['answer' => 'no']);
        self::assertResponseIsSuccessful();
        self::assertSame('no', $this->jsonBody($client)['days'][0]['myResponse']);
        self::assertSame(1, $this->jsonBody($client)['days'][0]['summary']['no']);
    }

    public function testInvalidAttendanceIsRejected(): void
    {
        $client = $this->browser();
        $created = $this->createEvent($client);

        $this->request($client, 'POST', '/api/v1/event-days/'.$created['days'][0]['id'].'/responses', ['answer' => 'maybe']);

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonBody($client);
        self::assertSame('Bitte mit Ja oder Nein antworten.', $body['errors']['answer']);
    }

    public function testEventWithoutTitleIsRejected(): void
    {
        $client = $this->browser();
        $payload = $this->payload('Fahrt');
        $payload['title'] = '   ';
        $this->request($client, 'POST', '/api/v1/events', $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('title', $this->jsonBody($client)['errors']);
    }

    /** @return array<string, mixed> */
    private function createEvent(KernelBrowser $client, string $title = 'Fahrt nach Zürich'): array
    {
        $this->request($client, 'POST', '/api/v1/events', $this->payload($title));

        return $this->jsonBody($client);
    }

    /** @return array<string, mixed> */
    private function payload(string $title): array
    {
        return [
            'title' => $title,
            'clubs' => ['historic'],
            'categories' => ['Fahrt'],
            'status' => 'published',
            'days' => [[
                'date' => '2026-12-05',
                'startsAt' => '09:00',
                'endsAt' => '16:00',
                'location' => 'Winterthur',
            ]],
        ];
    }
}
