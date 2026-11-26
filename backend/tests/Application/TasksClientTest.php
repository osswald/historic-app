<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Tasks\TasksClient;
use App\Application\Tasks\TasksPersonUnknown;
use App\Application\Tasks\TasksRejected;
use App\Application\UpstreamUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TasksClientTest extends TestCase
{
    public function testReadsSendTheBearerKeyAndTheActingUser(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options['headers'], $options['body'] ?? null];

            return new MockResponse('[]');
        });

        $client->taskLists('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada');
        $client->templates('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada');
        $client->tasksForMember('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada', 'todo');

        self::assertSame('GET', $seen[0][0]);
        self::assertSame('https://tasks.test/functions/v1/api/v1/task-lists', $seen[0][1]);
        self::assertContains('Authorization: Bearer test-token', $seen[0][2]);
        self::assertContains('X-Acting-User: auth0|ada', $seen[0][2]);
        self::assertSame('https://tasks.test/functions/v1/api/v1/templates', $seen[1][1]);
        self::assertSame('https://tasks.test/functions/v1/api/v1/tasks?assignee=auth0%7Cada&status=todo', $seen[2][1]);
        self::assertNull($seen[0][3]);
    }

    public function testCreateAndStatusUseTheDocumentedBodies(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options['body'] ?? null];

            return new MockResponse('{"id":"list-1"}', ['http_code' => $method === 'POST' ? 201 : 200]);
        });

        $client->createTaskList('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada', [
            'name' => 'Depot',
            'template_id' => 'tpl-1',
        ]);
        $client->changeStatus('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada', 'task-1', 'done');

        self::assertSame('POST', $seen[0][0]);
        self::assertSame('https://tasks.test/functions/v1/api/v1/task-lists', $seen[0][1]);
        self::assertSame('{"name":"Depot","template_id":"tpl-1"}', $seen[0][2]);
        self::assertSame('PATCH', $seen[1][0]);
        self::assertSame('https://tasks.test/functions/v1/api/v1/tasks/task-1/status', $seen[1][1]);
        self::assertSame('{"status":"done"}', $seen[1][2]);
    }

    public function testAWrongKeyAndAnUnknownPersonStayInGermanWithoutTheKey(): void
    {
        $rejected = $this->client(fn (): MockResponse => new MockResponse('{"error":"test-token"}', ['http_code' => 401]));
        $unknown = $this->client(fn (): MockResponse => new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        try {
            $rejected->templates('https://tasks.test/functions/v1/api/v1', 'test-token', 'auth0|ada');
            self::fail('A wrong key should be reported.');
        } catch (TasksRejected $exception) {
            self::assertStringNotContainsString('test-token', $exception->getMessage());
        }
        try {
            $unknown->templates('https://tasks.test/functions/v1/api/v1', 'test-token', 'dev|local');
            self::fail('An unknown person should be reported.');
        } catch (TasksPersonUnknown $exception) {
            self::assertSame('Diese Person ist in der Aufgaben-App unbekannt, archiviert oder gelöscht.', $exception->getMessage());
        }
    }

    public function testTestsDoNotCallTheRealTasksHost(): void
    {
        $client = $this->client(function (): MockResponse {
            self::fail('The real host must not be called.');
        });

        $this->expectException(UpstreamUnavailable::class);
        $client->templates('https://example.test/functions/v1/api/v1', 'test-token', 'auth0|ada');
    }

    private function client(callable $factory): TasksClient
    {
        return new TasksClient(new MockHttpClient($factory), 'test');
    }
}
