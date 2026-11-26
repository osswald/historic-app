<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Nextcloud\NextcloudBaseInvalid;
use App\Application\Nextcloud\NextcloudCredentials;
use App\Application\Nextcloud\NextcloudNotConfigured;
use PHPUnit\Framework\TestCase;

final class NextcloudCredentialsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nextcloud-env');
        self::assertIsString($path);
        $this->path = $path;
        file_put_contents($this->path, "OTHER_SECRET=do-not-read\nNEXTCLOUD_BASE=\"https://nextcloud.test/remote.php/dav\"\nNEXTCLOUD_USER='verein'\nNEXTCLOUD_APP_PASSWORD='file-secret'\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testDevReadsTheNextcloudKeysFromTheFile(): void
    {
        $resolved = (new NextcloudCredentials('dev', $this->path))->resolve([]);

        self::assertSame('https://nextcloud.test', $resolved['base']);
        self::assertSame('verein', $resolved['user']);
        self::assertSame('file-secret', $resolved['password']);
    }

    public function testMissingKeysAreNamedAndTheFileIsIgnoredInTests(): void
    {
        try {
            (new NextcloudCredentials('test', $this->path))->resolve([]);
            self::fail('Missing keys should stop the call.');
        } catch (NextcloudNotConfigured $exception) {
            self::assertSame(['NEXTCLOUD_BASE', 'NEXTCLOUD_USER', 'NEXTCLOUD_APP_PASSWORD'], $exception->missing);
            self::assertSame('Nextcloud ist noch nicht verbunden. Es fehlt: NEXTCLOUD_BASE, NEXTCLOUD_USER, NEXTCLOUD_APP_PASSWORD.', $exception->getMessage());
            self::assertStringNotContainsString('file-secret', $exception->getMessage());
            self::assertStringNotContainsString('do-not-read', $exception->getMessage());
        }
    }

    public function testProductionIgnoresTheFile(): void
    {
        try {
            (new NextcloudCredentials('prod', $this->path))->resolve(['NEXTCLOUD_BASE' => 'https://nextcloud.test']);
            self::fail('Production must not fill the password from the file.');
        } catch (NextcloudNotConfigured $exception) {
            self::assertSame(['NEXTCLOUD_USER', 'NEXTCLOUD_APP_PASSWORD'], $exception->missing);
            self::assertStringNotContainsString('file-secret', $exception->getMessage());
        }
    }

    public function testAPasswordInTheAddressIsRejected(): void
    {
        try {
            (new NextcloudCredentials('dev', $this->path))->resolve([
                'NEXTCLOUD_BASE' => 'https://verein:file-secret@nextcloud.test',
                'NEXTCLOUD_USER' => 'verein',
                'NEXTCLOUD_APP_PASSWORD' => 'file-secret',
            ]);
            self::fail('Credentials do not belong in the address.');
        } catch (NextcloudBaseInvalid $exception) {
            self::assertSame('Die Adresse von Nextcloud ist ungültig.', $exception->getMessage());
            self::assertStringNotContainsString('file-secret', $exception->getMessage());
        }
    }
}
