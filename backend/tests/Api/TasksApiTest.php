<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class TasksApiTest extends ApiTestCase
{
    public function testTasksRequireASession(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/aufgaben/tasks', token: null);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMissingKeyStopsTheCallAndNamesTheKeys(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/aufgaben/tasks?assignee=someone-else');
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody($client);

        self::assertFalse($body['configured']);
        self::assertContains('TASKS_API_BASE', $body['missing']);
        self::assertContains('TASKS_API_TOKEN', $body['missing']);
        self::assertSame([], $body['tasks']);
        $encoded = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('someone-else', $encoded);
        self::assertStringNotContainsString('Bearer', $encoded);
    }

    public function testCreatingAListWithoutANameIsRejectedBeforeAnyUpstreamCall(): void
    {
        $client = $this->browser();
        $this->request($client, 'POST', '/api/v1/aufgaben/task-lists', ['description' => 'ohne Name']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheDashboardStaysAvailableWhenTasksAreNotConfigured(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/dashboard');
        self::assertResponseIsSuccessful();
        $dashboard = $this->jsonBody($client);

        self::assertNull($dashboard['counts']['tasks']);
        self::assertSame([], $dashboard['tasks']);
        self::assertIsString($dashboard['tasksNotice']);
        self::assertStringContainsString('TASKS_API_TOKEN', $dashboard['tasksNotice']);
    }
}
