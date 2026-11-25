<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class DashboardTest extends ApiTestCase
{
    public function testDashboardRequiresAToken(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/dashboard', token: null);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDashboardListsTheOpenEventAndTheComingWeek(): void
    {
        $client = $this->browser();
        $this->request($client, 'POST', '/api/v1/events', [
            'title' => 'Offene Fahrt',
            'clubs' => ['historic'],
            'categories' => ['Fahrt'],
            'status' => 'published',
            'days' => [[
                'date' => '2026-12-05',
                'startsAt' => '10:00',
                'endsAt' => '15:00',
                'location' => 'Winterthur',
            ]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->request($client, 'GET', '/api/v1/dashboard');
        self::assertResponseIsSuccessful();
        $dashboard = $this->jsonBody($client);

        self::assertSame(1, $dashboard['counts']['openResponses']);
        self::assertSame(0, $dashboard['counts']['polls']);
        self::assertSame('Offene Fahrt', $dashboard['upcoming'][0]['title']);
        self::assertCount(7, $dashboard['nextSevenDays']);
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Zurich')))->format('Y-m-d');
        self::assertSame($today, $dashboard['nextSevenDays'][0]['date']);
        self::assertNull($dashboard['openPoll']);
    }
}
