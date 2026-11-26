<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Nextcloud\ArchiveLinkedFolder;
use App\Application\Nextcloud\NextcloudClient;
use App\Domain\Event\Event;
use App\Domain\Event\EventNextcloudFolder;
use App\Domain\Event\EventStatus;
use App\Domain\Nextcloud\ArchiveEventFolder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArchiveLinkedFolderTest extends TestCase
{
    public function testAFolderThatIsNotDueIsLeftAlone(): void
    {
        $client = new NextcloudClient(new MockHttpClient(function (): MockResponse {
            self::fail('Nextcloud must not be called before the folder is due.');
        }), 'test');
        $archive = new ArchiveLinkedFolder(new ArchiveEventFolder(), $client);
        $event = $this->event();
        $link = new EventNextcloudFolder($event, '62375', '0000062375ocinstance', '2026-12-30 Veranstaltungsname', new \DateTimeImmutable('2026-12-01', new \DateTimeZone('Europe/Zurich')));

        self::assertFalse($archive->moveIfDue(
            $event,
            $link,
            new \DateTimeImmutable('2027-01-08', new \DateTimeZone('Europe/Zurich')),
            'https://nextcloud.test',
            'verein',
            'test-app-password',
        ));
        self::assertSame('62375', $link->fileId());
    }

    public function testADueFolderMovesUnderTheStartYearAndKeepsTheFileId(): void
    {
        $methods = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$methods): MockResponse {
            $methods[] = $method.' '.$url;
            self::assertStringStartsWith('https://nextcloud.test/', $url);
            self::assertStringNotContainsString('test-app-password', $url);
            if ($method === 'SEARCH') {
                return new MockResponse($this->folder('Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname'), ['http_code' => 207]);
            }
            if ($method === 'MKCOL') {
                self::assertStringContainsString('/_ARCHIV/2026', $url);

                return new MockResponse('', ['http_code' => 201]);
            }
            self::assertContains(
                'Destination: https://nextcloud.test/remote.php/dav/files/verein/Fahrten_Anl%C3%A4sse/_ARCHIV/2026/2026-12-30%20Veranstaltungsname',
                $options['headers'],
            );

            return new MockResponse('', ['http_code' => 201]);
        });
        $archive = new ArchiveLinkedFolder(new ArchiveEventFolder(), new NextcloudClient($http, 'test'));
        $event = $this->event();
        $link = new EventNextcloudFolder($event, '62375', '0000062375ocinstance', '2026-12-30 Veranstaltungsname', new \DateTimeImmutable('2026-12-01', new \DateTimeZone('Europe/Zurich')));

        self::assertTrue($archive->moveIfDue(
            $event,
            $link,
            new \DateTimeImmutable('2027-01-09', new \DateTimeZone('Europe/Zurich')),
            'https://nextcloud.test',
            'verein',
            'test-app-password',
        ));
        self::assertSame('62375', $link->fileId());
        self::assertSame('0000062375ocinstance', $link->ocId());
        self::assertCount(3, $methods);
    }

    private function event(): Event
    {
        $zone = new \DateTimeZone('Europe/Zurich');
        $event = new Event('Veranstaltungsname', ['historic'], [], EventStatus::Published, null, null, null, false, new \DateTimeImmutable('2026-12-01', $zone));
        $event->addDay(new \DateTimeImmutable('2026-12-30', $zone), null, null, null, 0);
        $event->addDay(new \DateTimeImmutable('2027-01-02', $zone), null, null, null, 1);

        return $event;
    }

    private function folder(string $encodedTail): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response>'
            .'<d:href>/remote.php/dav/files/verein/'.$encodedTail.'/</d:href>'
            .'<d:propstat><d:prop>'
            .'<d:displayname>2026-12-30 Veranstaltungsname</d:displayname>'
            .'<d:resourcetype><d:collection/></d:resourcetype>'
            .'<oc:fileid>62375</oc:fileid>'
            .'<oc:id>0000062375ocinstance</oc:id>'
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
            .'</d:response></d:multistatus>';
    }
}
