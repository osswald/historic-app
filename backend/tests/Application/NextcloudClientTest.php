<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\InvalidRequest;
use App\Application\Nextcloud\NextcloudClient;
use App\Application\Nextcloud\NextcloudNotFound;
use App\Application\Nextcloud\NextcloudRejected;
use App\Application\UpstreamUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NextcloudClientTest extends TestCase
{
    public function testThePickerSkipsTheArchiveAndTheTestPrefix(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['headers']];

            return new MockResponse($this->listing(), ['http_code' => 207]);
        });

        $folders = $client->eventFolders('https://nextcloud.test', 'verein', 'test-app-password');

        self::assertSame('PROPFIND', $seen[0]);
        self::assertContains('Depth: 1', $seen[2]);
        self::assertStringContainsString('/remote.php/dav/files/verein/Fahrten_Anl%C3%A4sse', $seen[1]);
        self::assertCount(1, $folders);
        self::assertSame('62375', $folders[0]->fileId());
        self::assertSame('2026-05-15 Fahrt', $folders[0]->name());
        self::assertStringNotContainsString('/', $folders[0]->fileId());
    }

    public function testCreateStoresTheFileIdRatherThanThePath(): void
    {
        $depths = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$depths): MockResponse {
            if ($method === 'MKCOL') {
                self::assertStringContainsString('/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname', $url);
                self::assertStringNotContainsString('test-app-password', $url);

                return new MockResponse('', ['http_code' => 201]);
            }
            $depths[] = in_array('Depth: 0', $options['headers'], true) ? '0' : null;

            return new MockResponse($this->folder('62375', '0000062375ocinstance', '2026-12-30 Veranstaltungsname', '2026-12-30%20Veranstaltungsname'), ['http_code' => 207]);
        });

        $created = $client->createEventFolder('https://nextcloud.test', 'verein', 'test-app-password', '2026-12-30 Veranstaltungsname');

        self::assertSame(['0'], $depths);
        self::assertSame('62375', $created->fileId());
        self::assertSame('0000062375ocinstance', $created->ocId());
        self::assertSame('2026-12-30 Veranstaltungsname', $created->name());
        self::assertSame('Fahrten_Anlässe/2026-12-30 Veranstaltungsname', $created->path());
        self::assertSame(
            'https://nextcloud.test/apps/files/files/62375?dir=/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname',
            $client->fileUrl('https://nextcloud.test', $created->fileId(), $created->path()),
        );
    }

    public function testAMoveKeepsTheFileId(): void
    {
        $search = 0;
        $client = $this->client(function (string $method, string $url, array $options) use (&$search): MockResponse {
            if ($method === 'SEARCH') {
                ++$search;
                self::assertIsString($options['body'] ?? null);
                self::assertStringContainsString('<d:literal>62375</d:literal>', $options['body']);
                $segment = $search === 1 ? '2026-12-30%20Veranstaltungsname' : '_ARCHIV/2026/2026-12-30%20Veranstaltungsname';
                $name = '2026-12-30 Veranstaltungsname';

                return new MockResponse($this->folder('62375', '0000062375ocinstance', $name, $segment), ['http_code' => 207]);
            }
            self::assertSame('MOVE', $method);
            self::assertContains(
                'Destination: https://nextcloud.test/remote.php/dav/files/verein/Fahrten_Anl%C3%A4sse/_ARCHIV/2026/2026-12-30%20Veranstaltungsname',
                $options['headers'],
            );
            self::assertContains('Overwrite: F', $options['headers']);
            self::assertStringNotContainsString('test-app-password', $url);

            return new MockResponse('', ['http_code' => 201]);
        });

        $before = $client->findByFileId('https://nextcloud.test', 'verein', 'test-app-password', '62375');
        self::assertNotNull($before);
        $client->move('https://nextcloud.test', 'verein', 'test-app-password', $before->path(), 'Fahrten_Anlässe/_ARCHIV/2026/'.$before->name());
        $after = $client->findByFileId('https://nextcloud.test', 'verein', 'test-app-password', '62375');

        self::assertNotNull($after);
        self::assertSame('62375', $before->fileId());
        self::assertSame('62375', $after->fileId());
        self::assertSame('Fahrten_Anlässe/2026-12-30 Veranstaltungsname', $before->path());
        self::assertSame('Fahrten_Anlässe/_ARCHIV/2026/2026-12-30 Veranstaltungsname', $after->path());
        self::assertSame(
            'https://nextcloud.test/apps/files/files/62375?dir=/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname',
            $client->fileUrl('https://nextcloud.test', $before->fileId(), $before->path()),
        );
        self::assertSame(
            'https://nextcloud.test/apps/files/files/62375?dir=/Fahrten_Anl%C3%A4sse/_ARCHIV/2026/2026-12-30%20Veranstaltungsname',
            $client->fileUrl('https://nextcloud.test', $after->fileId(), $after->path()),
        );
    }

    public function testAMissingFolderIsNotAnAuthenticationFailure(): void
    {
        $client = $this->client(fn (): MockResponse => new MockResponse('test-app-password', ['http_code' => 404]));

        self::assertNull($client->findByFileId('https://nextcloud.test', 'verein', 'test-app-password', '62375'));

        $listing = $this->client(fn (): MockResponse => new MockResponse('', ['http_code' => 404]));
        try {
            $listing->eventFolders('https://nextcloud.test', 'verein', 'test-app-password');
            self::fail('A missing parent should be reported.');
        } catch (NextcloudNotFound $exception) {
            self::assertStringNotContainsString('test-app-password', $exception->getMessage());
        }
    }

    public function testARejectedLoginDoesNotRepeatThePassword(): void
    {
        $client = $this->client(fn (): MockResponse => new MockResponse('test-app-password', ['http_code' => 401]));

        try {
            $client->eventFolders('https://nextcloud.test', 'verein', 'test-app-password');
            self::fail('A rejected login should be reported.');
        } catch (NextcloudRejected $exception) {
            self::assertSame('Nextcloud hat die Anmeldung abgelehnt.', $exception->getMessage());
            self::assertStringNotContainsString('test-app-password', $exception->getMessage());
        }
    }

    public function testTheOpenUrlUsesTheFilesAppIdAndTheCurrentDirectory(): void
    {
        $client = $this->client(function (): MockResponse {
            self::fail('Opening a file does not call Nextcloud.');
        });

        $folder = $client->fileUrl('https://nextcloud.test/remote.php/dav', '62312', 'Fahrten_Anlässe/2026-11-27 Glanzmann');
        $file = $client->fileUrl('https://nextcloud.test', '62318', 'Fahrten_Anlässe/2026-11-27 Glanzmann', true);

        self::assertSame('https://nextcloud.test/apps/files/files/62312?dir=/Fahrten_Anl%C3%A4sse/2026-11-27%20Glanzmann', $folder);
        self::assertSame('https://nextcloud.test/apps/files/files/62318?dir=/Fahrten_Anl%C3%A4sse/2026-11-27%20Glanzmann&openfile=true', $file);
        self::assertStringNotContainsString('remote.php', $folder);
        self::assertStringNotContainsString('/dav/', $folder);
        self::assertStringNotContainsString('index.php', $folder);
        self::assertStringNotContainsString('openfile', $folder);
        self::assertStringContainsString('openfile=true', $file);
    }

    public function testUploadStaysInsideTheGivenFolder(): void
    {
        $put = null;
        $client = $this->client(function (string $method, string $url, array $options) use (&$put): MockResponse {
            self::assertSame('PUT', $method);
            $put = $url;
            self::assertSame('hello', $options['body'] ?? null);
            self::assertStringNotContainsString('test-app-password', $url);

            return new MockResponse('', ['http_code' => 201]);
        });

        $client->upload(
            'https://nextcloud.test',
            'verein',
            'test-app-password',
            'Fahrten_Anlässe/2026-05-15 Fahrt',
            '../Andere/Plan.pdf',
            'hello',
        );

        self::assertIsString($put);
        self::assertStringEndsWith('/Fahrten_Anl%C3%A4sse/2026-05-15%20Fahrt/Plan.pdf', $put);
        self::assertStringNotContainsString('Andere', $put);
    }

    public function testAnEmptyFileNameIsRejectedBeforeUpload(): void
    {
        $client = $this->client(function (): MockResponse {
            self::fail('An empty selection must not be uploaded.');
        });

        try {
            $client->upload('https://nextcloud.test', 'verein', 'test-app-password', 'Fahrten_Anlässe/2026-05-15 Fahrt', '   ', 'x');
            self::fail('An empty selection should be rejected.');
        } catch (InvalidRequest $exception) {
            self::assertSame('Bitte eine Datei wählen.', $exception->getMessage());
        }
    }

    public function testTestsDoNotCallTheRealHost(): void
    {
        $client = $this->client(function (): MockResponse {
            self::fail('The real host must not be called.');
        });

        $this->expectException(UpstreamUnavailable::class);
        $client->eventFolders('https://cloud.example.com', 'verein', 'test-app-password');
    }

    private function client(callable $factory): NextcloudClient
    {
        return new NextcloudClient(new MockHttpClient($factory), 'test');
    }

    private function listing(): string
    {
        return $this->responses([
            $this->response('1', null, 'Fahrten_Anlässe', 'Fahrten_Anl%C3%A4sse', true),
            $this->response('2', null, '_ARCHIV', 'Fahrten_Anl%C3%A4sse/_ARCHIV', true),
            $this->response('3', null, '_TEST_historic-app_nicht_benutzen', 'Fahrten_Anl%C3%A4sse/_TEST_historic-app_nicht_benutzen', true),
            $this->response('4', null, 'Notiz.txt', 'Fahrten_Anl%C3%A4sse/Notiz.txt', false),
            $this->response('62375', '0000062375ocinstance', '2026-05-15 Fahrt', 'Fahrten_Anl%C3%A4sse/2026-05-15%20Fahrt', true),
            $this->response('9', null, 'Gastronomie', 'Fahrten_Anl%C3%A4sse/2026-05-15%20Fahrt/Gastronomie', true),
        ]);
    }

    private function folder(string $fileId, string $ocId, string $name, string $encodedTail): string
    {
        return $this->responses([
            $this->response($fileId, $ocId, $name, 'Fahrten_Anl%C3%A4sse/'.$encodedTail, true),
        ]);
    }

    /** @param list<string> $responses */
    private function responses(array $responses): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            .implode('', $responses)
            .'</d:multistatus>';
    }

    private function response(string $fileId, ?string $ocId, string $name, string $encodedPath, bool $collection): string
    {
        $kind = $collection ? '<d:collection/>' : '';
        $second = $ocId === null ? '' : '<oc:id>'.$ocId.'</oc:id>';

        return '<d:response>'
            .'<d:href>/remote.php/dav/files/verein/'.$encodedPath.'/</d:href>'
            .'<d:propstat><d:prop>'
            .'<d:displayname>'.$name.'</d:displayname>'
            .'<d:resourcetype>'.$kind.'</d:resourcetype>'
            .'<oc:fileid>'.$fileId.'</oc:fileid>'
            .$second
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
            .'</d:response>';
    }
}
