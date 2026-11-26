<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Event\EventCatalog;
use App\Application\Tasks\EventTaskLists;
use App\Application\Tasks\ShowTasks;
use App\Application\Tasks\TasksClient;
use App\Infrastructure\Audit\AuditRecorder;
use App\Application\ZurichClock;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventTaskListLink;
use App\Domain\Event\EventTaskListRepository;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EventTaskListLinkTest extends ApiTestCase
{
    public function testTheSameListStaysOnTwoEventsWhenTheRemoteListIsMissing(): void
    {
        $client = $this->browser();
        $first = $this->createEvent($client, 'Erste Fahrt');
        $second = $this->createEvent($client, 'Zweite Fahrt');
        $events = static::getContainer()->get(EventRepository::class);
        $links = static::getContainer()->get(EventTaskListRepository::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $clock = static::getContainer()->get(ZurichClock::class);
        self::assertInstanceOf(EventRepository::class, $events);
        self::assertInstanceOf(EventTaskListRepository::class, $links);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ZurichClock::class, $clock);

        $firstEvent = $events->byId($first);
        $secondEvent = $events->byId($second);
        self::assertNotNull($firstEvent);
        self::assertNotNull($secondEvent);
        $links->add(new EventTaskListLink($firstEvent, 'list-shared', 'Depot', $clock->now()));
        $links->add(new EventTaskListLink($secondEvent, 'list-shared', 'Depot', $clock->now()));
        $entityManager->flush();

        $this->request($client, 'GET', '/api/v1/events/'.$first.'/task-lists');
        self::assertResponseIsSuccessful();
        $firstBody = $this->jsonBody($client);
        $this->request($client, 'GET', '/api/v1/events/'.$second.'/task-lists');
        self::assertResponseIsSuccessful();
        $secondBody = $this->jsonBody($client);
        self::assertSame('list-shared', $firstBody['lists'][0]['id']);
        self::assertSame('Depot', $firstBody['lists'][0]['name']);
        self::assertSame('list-shared', $secondBody['lists'][0]['id']);
        self::assertSame('Depot', $secondBody['lists'][0]['name']);
        self::assertArrayNotHasKey('error', $firstBody);
        self::assertCount(1, $links->forEvent($first));
        self::assertCount(1, $links->forEvent($second));

        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $method.' '.$url;

            return new MockResponse('{"error":"missing"}', ['http_code' => 404]);
        });
        $catalog = static::getContainer()->get(EventCatalog::class);
        $tasks = static::getContainer()->get(ShowTasks::class);
        self::assertInstanceOf(EventCatalog::class, $catalog);
        self::assertInstanceOf(ShowTasks::class, $tasks);
        $audit = static::getContainer()->get(AuditRecorder::class);
        self::assertInstanceOf(AuditRecorder::class, $audit);
        $reader = new EventTaskLists(
            $catalog,
            $links,
            $tasks,
            new TasksClient($http, 'test'),
            $audit,
            $entityManager,
            $clock,
        );
        $quiet = $reader->resolve('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada', $first);

        self::assertSame([], $quiet);
        self::assertStringContainsString('/task-lists/list-shared', $seen[0]);
        self::assertCount(1, $links->forEvent($first));
        self::assertCount(1, $links->forEvent($second));
        self::assertSame('list-shared', $links->forEvent($first)[0]->taskListId());
        self::assertSame('Depot', $links->forEvent($first)[0]->name());
        self::assertSame('list-shared', $links->forEvent($second)[0]->taskListId());
        self::assertSame('Depot', $links->forEvent($second)[0]->name());
    }

    public function testARemoteFailureOtherThanNotFoundKeepsTheStoredTitle(): void
    {
        $client = $this->browser();
        $eventId = $this->createEvent($client, 'Gemischte Listen');
        $events = static::getContainer()->get(EventRepository::class);
        $links = static::getContainer()->get(EventTaskListRepository::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $clock = static::getContainer()->get(ZurichClock::class);
        self::assertInstanceOf(EventRepository::class, $events);
        self::assertInstanceOf(EventTaskListRepository::class, $links);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ZurichClock::class, $clock);
        $event = $events->byId($eventId);
        self::assertNotNull($event);
        $links->add(new EventTaskListLink($event, 'list-gone', 'Weg', $clock->now()));
        $links->add(new EventTaskListLink($event, 'list-down', 'Depot', $clock->now()));
        $links->add(new EventTaskListLink($event, 'list-bare', null, $clock->now()));
        $links->add(new EventTaskListLink($event, 'list-live', 'Alt', $clock->now()));
        $entityManager->flush();

        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/task-lists/list-gone')) {
                return new MockResponse('{"error":"missing"}', ['http_code' => 404]);
            }
            if (str_contains($url, '/task-lists/list-down')) {
                return new MockResponse('{"error":"down"}', ['http_code' => 500]);
            }
            if (str_contains($url, '/task-lists/list-bare')) {
                throw new TransportException('connection refused');
            }

            return new MockResponse('{"id":"list-live","name":"Aktueller Titel"}', ['http_code' => 200]);
        });
        $catalog = static::getContainer()->get(EventCatalog::class);
        $tasks = static::getContainer()->get(ShowTasks::class);
        self::assertInstanceOf(EventCatalog::class, $catalog);
        self::assertInstanceOf(ShowTasks::class, $tasks);
        $audit = static::getContainer()->get(AuditRecorder::class);
        self::assertInstanceOf(AuditRecorder::class, $audit);
        $reader = new EventTaskLists($catalog, $links, $tasks, new TasksClient($http, 'test'), $audit, $entityManager, $clock);
        $items = $reader->resolve('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada', $eventId);
        $byId = [];
        foreach ($items as $item) {
            self::assertIsString($item['id']);
            $byId[$item['id']] = $item;
        }

        self::assertArrayNotHasKey('list-gone', $byId);
        self::assertSame('Depot', $byId['list-down']['name']);
        self::assertSame('list-bare', $byId['list-bare']['id']);
        self::assertNull($byId['list-bare']['name']);
        self::assertFalse($byId['list-down']['unavailable']);
        self::assertFalse($byId['list-bare']['unavailable']);
        self::assertSame('Aktueller Titel', $byId['list-live']['name']);
        self::assertFalse($byId['list-live']['unavailable']);
        self::assertCount(4, $links->forEvent($eventId));
        $stored = [];
        foreach ($links->forEvent($eventId) as $link) {
            $stored[$link->taskListId()] = $link->name();
        }
        self::assertSame('Weg', $stored['list-gone']);
        self::assertSame('Depot', $stored['list-down']);
        self::assertNull($stored['list-bare']);
        self::assertSame('Aktueller Titel', $stored['list-live']);
    }

    public function testAMemberWhoCannotEditTheEventCannotCreateAList(): void
    {
        $client = $this->browser();
        $eventId = $this->createEvent($client, 'Offene Fahrt');
        $this->withGroups('Mitglieder', function (KernelBrowser $member) use ($eventId): void {
            $this->request($member, 'GET', '/api/v1/events/'.$eventId.'/task-lists');
            self::assertResponseIsSuccessful();
            self::assertFalse($this->jsonBody($member)['canEdit']);

            $this->request($member, 'POST', '/api/v1/events/'.$eventId.'/task-lists', ['name' => 'Nicht erlaubt']);
            self::assertResponseStatusCodeSame(403);
        });

        $links = static::getContainer()->get(EventTaskListRepository::class);
        self::assertInstanceOf(EventTaskListRepository::class, $links);
        self::assertSame([], $links->forEvent($eventId));
    }

    private function createEvent(KernelBrowser $client, string $title): string
    {
        $this->request($client, 'POST', '/api/v1/events', [
            'title' => $title,
            'clubs' => ['historic'],
            'categories' => ['Fahrt'],
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
}
