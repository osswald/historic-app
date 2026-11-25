<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Security\DevTokenAuthenticator;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class GastroPlanTest extends ApiTestCase
{
    public function testGastroTeamCanSaveOnePlanPerEvent(): void
    {
        $client = $this->browser();
        $eventId = $this->createEvent($client);

        $this->withGroups('Gastro-Team', function (KernelBrowser $gastro) use ($eventId): void {
            $this->request($gastro, 'GET', '/api/v1/events/'.$eventId.'/gastronomy');
            self::assertResponseIsSuccessful();
            $empty = $this->jsonBody($gastro);
            self::assertTrue($empty['canEdit']);
            self::assertNull($empty['plan']);

            $this->request($gastro, 'PUT', '/api/v1/events/'.$eventId.'/gastronomy', $this->plan('member|kitchen', 'Küche'));
            self::assertResponseIsSuccessful();
            $saved = $this->jsonBody($gastro);
            self::assertTrue($saved['canEdit']);
            self::assertIsArray($saved['plan']);
            $plan = $saved['plan'];
            self::assertSame('Buffet im Speisewagen.', $plan['notes']);
            self::assertSame('Älplermagronen', $plan['dishes'][0]['name']);
            self::assertSame(36, $plan['dishes'][0]['portions']);
            self::assertSame('member|kitchen', $plan['responsible'][0]['sub']);
            self::assertSame('Küche', $plan['responsible'][0]['name']);
            self::assertArrayNotHasKey('price', $plan['dishes'][0]);
            self::assertArrayNotHasKey('articles', $plan);
            $planId = $plan['id'];
            self::assertIsString($planId);

            $updated = $this->plan('member|kitchen', 'Küche');
            $updated['dishes'][0]['portions'] = 40;
            $updated['notes'] = 'Zusätzlich Salat.';
            $this->request($gastro, 'PUT', '/api/v1/events/'.$eventId.'/gastronomy', $updated);
            self::assertResponseIsSuccessful();
            $again = $this->jsonBody($gastro)['plan'];
            self::assertIsArray($again);
            self::assertSame($planId, $again['id']);
            self::assertSame(40, $again['dishes'][0]['portions']);
            self::assertSame('Zusätzlich Salat.', $again['notes']);
            self::assertSame(['create', 'update'], $this->auditActions());
        });
    }

    public function testNamedResponsibleCanSaveButOrganizersCannot(): void
    {
        $client = $this->browser();
        $eventId = $this->createEvent($client);
        $this->withGroups('Gastro-Team', function (KernelBrowser $gastro) use ($eventId): void {
            $this->request($gastro, 'PUT', '/api/v1/events/'.$eventId.'/gastronomy', $this->plan(DevTokenAuthenticator::SUBJECT, 'Demo Mitglied'));
            self::assertResponseIsSuccessful();
        });

        foreach (['admin', 'Vorstand'] as $group) {
            $this->withGroups($group, function (KernelBrowser $client) use ($eventId): void {
                $this->request($client, 'GET', '/api/v1/events/'.$eventId.'/gastronomy');
                self::assertResponseIsSuccessful();
                self::assertTrue($this->jsonBody($client)['canEdit']);
            });
        }

        $this->withGroups('Mitglieder', function (KernelBrowser $member) use ($eventId): void {
            $this->request($member, 'GET', '/api/v1/events/'.$eventId.'/gastronomy');
            self::assertResponseIsSuccessful();
            self::assertTrue($this->jsonBody($member)['canEdit']);

            $payload = $this->plan(DevTokenAuthenticator::SUBJECT, 'Demo Mitglied');
            $payload['notes'] = 'Vom Verantwortlichen ergänzt.';
            $this->request($member, 'PUT', '/api/v1/events/'.$eventId.'/gastronomy', $payload);
            self::assertResponseIsSuccessful();
            self::assertSame('Vom Verantwortlichen ergänzt.', $this->jsonBody($member)['plan']['notes']);
        });

        $otherEvent = $this->createEvent($this->browser());
        $this->withGroups('Gastro-Team', function (KernelBrowser $gastro) use ($otherEvent): void {
            $this->request($gastro, 'PUT', '/api/v1/events/'.$otherEvent.'/gastronomy', $this->plan('member|kitchen', 'Küche'));
            self::assertResponseIsSuccessful();
        });

        foreach (['admin', 'Vorstand', 'Mitglieder'] as $group) {
            $this->withGroups($group, function (KernelBrowser $client) use ($otherEvent): void {
                $this->request($client, 'GET', '/api/v1/events/'.$otherEvent.'/gastronomy');
                self::assertResponseIsSuccessful();
                self::assertFalse($this->jsonBody($client)['canEdit']);
                self::assertSame('Älplermagronen', $this->jsonBody($client)['plan']['dishes'][0]['name']);

                $this->request($client, 'PUT', '/api/v1/events/'.$otherEvent.'/gastronomy', $this->plan('member|kitchen', 'Küche'));
                self::assertResponseStatusCodeSame(403);
                self::assertSame(
                    'Die Gastronomie bearbeiten das Gastro-Team und die benannten Verantwortlichen.',
                    $this->jsonBody($client)['error'],
                );
            });
        }

        $organizer = $this->browser();
        $this->request($organizer, 'GET', '/api/v1/events/'.$otherEvent.'/gastronomy');
        self::assertSame('Buffet im Speisewagen.', $this->jsonBody($organizer)['plan']['notes']);
    }

    public function testInvalidDishesStayUnsaved(): void
    {
        $client = $this->browser();
        $eventId = $this->createEvent($client);
        $this->withGroups('Gastro-Team', function (KernelBrowser $gastro) use ($eventId): void {
            $payload = $this->plan('member|kitchen', 'Küche');
            $payload['dishes'] = [['name' => '', 'portions' => 0]];
            $this->request($gastro, 'PUT', '/api/v1/events/'.$eventId.'/gastronomy', $payload);
            self::assertResponseStatusCodeSame(422);
            $errors = $this->jsonBody($gastro)['errors'];
            self::assertSame('Bitte den Namen des Gerichts mit höchstens 120 Zeichen angeben.', $errors['dishes.0.name']);
            self::assertSame('Bitte die Anzahl Portionen zwischen 1 und 9999 angeben.', $errors['dishes.0.portions']);

            $this->request($gastro, 'GET', '/api/v1/events/'.$eventId.'/gastronomy');
            self::assertNull($this->jsonBody($gastro)['plan']);
        });
    }

    /** @return array<string, mixed> */
    private function plan(string $sub, string $name): array
    {
        return [
            'notes' => 'Buffet im Speisewagen.',
            'dishes' => [
                ['name' => 'Älplermagronen', 'portions' => 36],
            ],
            'responsible' => [
                ['sub' => $sub, 'name' => $name],
            ],
        ];
    }

    private function createEvent(KernelBrowser $client): string
    {
        $this->request($client, 'POST', '/api/v1/events', [
            'title' => 'Spaghettiplausch',
            'clubs' => ['supporter'],
            'categories' => ['Gastro'],
            'status' => 'published',
            'days' => [[
                'date' => '2026-12-12',
                'startsAt' => '18:00',
                'endsAt' => '22:00',
                'location' => 'Winterthur',
            ]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody($client)['id'];
        self::assertIsString($id);

        return $id;
    }

    /** @param callable(KernelBrowser): void $callback */
    private function withGroups(string $groups, callable $callback): void
    {
        $env = $_ENV['DEV_USER_GROUPS'] ?? 'admin,Mitglieder';
        $server = $_SERVER['DEV_USER_GROUPS'] ?? $env;
        $exported = getenv('DEV_USER_GROUPS');
        $_ENV['DEV_USER_GROUPS'] = $groups;
        $_SERVER['DEV_USER_GROUPS'] = $groups;
        putenv('DEV_USER_GROUPS='.$groups);

        try {
            self::ensureKernelShutdown();
            $callback($this->browser());
        } finally {
            $_ENV['DEV_USER_GROUPS'] = $env;
            $_SERVER['DEV_USER_GROUPS'] = $server;
            if ($exported === false) {
                putenv('DEV_USER_GROUPS');
            } else {
                putenv('DEV_USER_GROUPS='.$exported);
            }
            self::ensureKernelShutdown();
        }
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $connection = $doctrine->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $actions = $connection->fetchFirstColumn(
            "SELECT action FROM audit_entry WHERE entity_type = 'gastro_plan' ORDER BY occurred_at ASC, action ASC",
        );
        self::assertIsArray($actions);

        return array_map(static fn (mixed $action): string => (string) $action, $actions);
    }
}
