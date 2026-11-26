<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Spond\AnbindungenCredentials;
use App\Application\Spond\SpondCredentials;
use App\Application\Spond\SpondExportException;
use PHPUnit\Framework\TestCase;

final class SpondCredentialsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spond-env');
        self::assertIsString($path);
        $this->path = $path;
        file_put_contents($this->path, "OTHER_SECRET=should-stay-unread\nSPOND_USERNAME=\"reader@example.test\"\nSPOND_PASSWORD='test-pass'\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testLocalRunReadsOnlyTheSpondKeysFromTheFile(): void
    {
        $resolved = (new SpondCredentials('dev', $this->path, new AnbindungenCredentials()))->resolve([]);

        self::assertSame(['username' => 'reader@example.test', 'password' => 'test-pass'], $resolved);
    }

    public function testEnvironmentWinsOverTheFile(): void
    {
        $resolved = (new SpondCredentials('dev', $this->path, new AnbindungenCredentials()))->resolve([
            'SPOND_USERNAME' => 'from-env@example.test',
            'SPOND_PASSWORD' => 'env-pass',
        ]);

        self::assertSame('from-env@example.test', $resolved['username']);
        self::assertSame('env-pass', $resolved['password']);
    }

    public function testProductionIgnoresTheFile(): void
    {
        $credentials = new SpondCredentials('prod', $this->path, new AnbindungenCredentials());

        $this->expectException(SpondExportException::class);
        $credentials->resolve(['SPOND_USERNAME' => 'from-env@example.test']);
    }
}
