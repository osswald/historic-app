<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Tasks\TasksBaseInvalid;
use App\Application\Tasks\TasksCredentials;
use App\Application\Tasks\TasksNotConfigured;
use PHPUnit\Framework\TestCase;

final class TasksCredentialsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tasks-env');
        self::assertIsString($path);
        $this->path = $path;
        file_put_contents($this->path, "OTHER_SECRET=do-not-read\nTASKS_API_BASE=\"https://tasks.test/functions/v1\"\nTASKS_API_TOKEN='test-token'\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testDevReadsTheTasksKeysFromTheFileAndAppendsTheApiPrefix(): void
    {
        $resolved = (new TasksCredentials('dev', $this->path))->resolve([]);

        self::assertSame('https://tasks.test/functions/v1/api/v1', $resolved['base']);
        self::assertSame('test-token', $resolved['token']);
    }

    public function testTheSpecKeyNameWinsOverTheExampleName(): void
    {
        $resolved = (new TasksCredentials('dev', $this->path))->resolve([
            'TASKS_API_BASE' => 'https://tasks.test/functions/v1/api/v1',
            'TASKS_API_KEY' => 'spec-key',
            'TASKS_API_TOKEN' => 'example-key',
        ]);

        self::assertSame('https://tasks.test/functions/v1/api/v1', $resolved['base']);
        self::assertSame('spec-key', $resolved['token']);
    }

    public function testMissingKeysAreNamedAndTheFileIsIgnoredInTests(): void
    {
        try {
            (new TasksCredentials('test', $this->path))->resolve([]);
            self::fail('Missing keys should stop the call.');
        } catch (TasksNotConfigured $exception) {
            self::assertSame(['TASKS_API_BASE', 'TASKS_API_KEY', 'TASKS_API_TOKEN'], $exception->missing);
            self::assertStringNotContainsString('do-not-read', $exception->getMessage());
            self::assertStringNotContainsString('test-token', $exception->getMessage());
        }
    }

    public function testProductionIgnoresTheFile(): void
    {
        $this->expectException(TasksNotConfigured::class);
        (new TasksCredentials('prod', $this->path))->resolve(['TASKS_API_BASE' => 'https://tasks.test/functions/v1']);
    }

    public function testABaseOutsideTheTasksApiIsRejected(): void
    {
        $this->expectException(TasksBaseInvalid::class);
        (new TasksCredentials('dev', $this->path))->resolve([
            'TASKS_API_BASE' => 'https://tasks.test/somewhere',
            'TASKS_API_TOKEN' => 'test-token',
        ]);
    }
}
