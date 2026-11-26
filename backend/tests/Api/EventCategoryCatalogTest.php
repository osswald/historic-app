<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EventCategoryCatalogTest extends ApiTestCase
{
    public function testAdminsExtendTheCatalogAndRejectDuplicates(): void
    {
        $client = $this->browser();
        $suffix = bin2hex(random_bytes(3));

        $this->request($client, 'POST', '/api/v1/event-categories', ['name' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte einen Namen angeben.', $this->jsonBody($client)['errors']['name']);

        $name = 'Sonderfahrt '.$suffix;
        $this->request($client, 'POST', '/api/v1/event-categories', ['name' => $name]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($name, $this->jsonBody($client)['name']);
        self::assertFalse($this->jsonBody($client)['used']);

        $this->request($client, 'POST', '/api/v1/event-categories', ['name' => mb_strtolower($name)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Diese Veranstaltungskategorie gibt es bereits.', $this->jsonBody($client)['error']);

        $this->request($client, 'GET', '/api/v1/event-categories');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->catalogId($this->jsonBody($client)['categories'], $name));

        $this->withGroups('Vorstand', function (KernelBrowser $organizer) use ($name): void {
            $this->request($organizer, 'GET', '/api/v1/event-categories');
            self::assertResponseIsSuccessful();
            self::assertNotNull($this->catalogId($this->jsonBody($organizer)['categories'], $name));

            $this->request($organizer, 'POST', '/api/v1/event-categories', ['name' => 'Fremde Kategorie']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Listen ergänzen dürfen nur Administratoren.', $this->jsonBody($organizer)['error']);
        });

        $this->withGroups('Mitglieder', function (KernelBrowser $member): void {
            $this->request($member, 'GET', '/api/v1/event-categories');
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Die Veranstaltungskategorien sehen nur Organisatoren.', $this->jsonBody($member)['error']);
        });
    }

    public function testEventsKeepTheCategoryNameAndInactiveValuesStayLinked(): void
    {
        $client = $this->browser();
        $suffix = bin2hex(random_bytes(3));
        $name = 'Kategorie '.$suffix;
        $unused = 'Ungenutzt '.$suffix;

        $this->request($client, 'POST', '/api/v1/event-categories', ['name' => $name]);
        self::assertResponseStatusCodeSame(201);
        $categoryId = $this->jsonBody($client)['id'];
        self::assertIsString($categoryId);
        $this->request($client, 'POST', '/api/v1/event-categories', ['name' => $unused]);
        self::assertResponseStatusCodeSame(201);
        $unusedId = $this->jsonBody($client)['id'];
        self::assertIsString($unusedId);

        $created = $this->createEvent($client, 'Katalogfahrt', $categoryId);
        self::assertSame([$name], $created['categories']);
        self::assertIsArray($created['category']);
        self::assertSame($name, $created['category']['name']);
        self::assertSame($categoryId, $created['category']['id']);
        self::assertNotSame($categoryId, $created['category']['name']);
        self::assertSame($name, $created['eyebrow']);

        $plain = $this->createEvent($client, 'Ohne Kategorie', null);
        self::assertSame([], $plain['categories']);
        self::assertNull($plain['category']);

        $this->request($client, 'POST', '/api/v1/events/'.$created['id'].'/copy');
        self::assertResponseStatusCodeSame(201);
        self::assertSame([$name], $this->jsonBody($client)['categories']);
        self::assertSame($categoryId, $this->jsonBody($client)['category']['id']);

        $this->request($client, 'DELETE', '/api/v1/event-categories/'.$categoryId);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Diese Veranstaltungskategorie wird noch verwendet und kann nicht gelöscht werden.',
            $this->jsonBody($client)['error'],
        );

        $this->request($client, 'PATCH', '/api/v1/event-categories/'.$categoryId, ['active' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->jsonBody($client)['active']);
        self::assertTrue($this->jsonBody($client)['used']);

        $this->request($client, 'GET', '/api/v1/events/'.$created['id']);
        self::assertResponseIsSuccessful();
        self::assertSame($name, $this->jsonBody($client)['category']['name']);
        self::assertFalse($this->jsonBody($client)['category']['active']);
        self::assertSame([$name], $this->jsonBody($client)['categories']);

        $kept = $this->eventPayload('Katalogfahrt', $categoryId);
        $this->request($client, 'PUT', '/api/v1/events/'.$created['id'], $kept);
        self::assertResponseIsSuccessful();
        self::assertSame($categoryId, $this->jsonBody($client)['category']['id']);
        self::assertSame($name, $this->jsonBody($client)['category']['name']);

        $fresh = $this->eventPayload('Neue Fahrt', $categoryId);
        $this->request($client, 'POST', '/api/v1/events', $fresh);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Diese Veranstaltungskategorie ist nicht mehr aktiv.', $this->jsonBody($client)['errors']['categoryId']);

        $this->request($client, 'POST', '/api/v1/events', $this->eventPayload('Unbekannt', '018f0000-0000-7000-8000-00000000ffff'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte eine Veranstaltungskategorie aus der Liste wählen.', $this->jsonBody($client)['errors']['categoryId']);

        $this->request($client, 'DELETE', '/api/v1/event-categories/'.$unusedId);
        self::assertResponseStatusCodeSame(204);

        $this->request($client, 'GET', '/api/v1/event-categories');
        self::assertResponseIsSuccessful();
        $stored = $this->catalogEntry($this->jsonBody($client)['categories'], $name);
        self::assertFalse($stored['active']);
        self::assertTrue($stored['used']);
        self::assertNull($this->catalogIdOrNull($this->jsonBody($client)['categories'], $unused));

        $this->withGroups('Vorstand', function (KernelBrowser $organizer) use ($categoryId): void {
            $this->request($organizer, 'PATCH', '/api/v1/event-categories/'.$categoryId, ['active' => true]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Listen ändern dürfen nur Administratoren.', $this->jsonBody($organizer)['error']);
        });
    }

    /** @return array<string, mixed> */
    private function createEvent(KernelBrowser $client, string $title, ?string $categoryId): array
    {
        $this->request($client, 'POST', '/api/v1/events', $this->eventPayload($title, $categoryId));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody($client);
    }

    /** @return array<string, mixed> */
    private function eventPayload(string $title, ?string $categoryId): array
    {
        return [
            'title' => $title,
            'clubs' => ['historic'],
            'categoryId' => $categoryId,
            'status' => 'published',
            'days' => [[
                'date' => '2026-12-05',
                'startsAt' => '09:00',
                'endsAt' => '16:00',
                'location' => 'Winterthur',
            ]],
        ];
    }

    /** @param list<array{id: string, name: string}> $items */
    private function catalogId(array $items, string $name): string
    {
        $id = $this->catalogIdOrNull($items, $name);
        self::assertNotNull($id);

        return $id;
    }

    /** @param list<array<string, mixed>> $items */
    private function catalogIdOrNull(array $items, string $name): ?string
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) === $name && is_string($item['id'] ?? null)) {
                return $item['id'];
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function catalogEntry(array $items, string $name): array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }
        self::fail('Der Listenwert '.$name.' fehlt.');
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
}
