<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Event\EventCatalog;
use App\Application\InvalidRequest;
use App\Application\Nextcloud\NextcloudAreas;
use App\Application\Nextcloud\NextcloudClient;
use App\Infrastructure\Audit\AuditRecorder;
use App\Application\Nextcloud\NextcloudCredentials;
use App\Application\Nextcloud\ShowNextcloud;
use App\Application\ZurichClock;
use App\Domain\Event\EventNextcloudFolderRepository;
use App\Domain\Event\EventRepository;
use App\Domain\Gastronomy\GastroPlanRepository;
use App\Domain\Ride\RideRepository;
use App\Security\MemberUser;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NextcloudFolderTest extends ApiTestCase
{
    /** @var array<string, string|false> */
    private array $previous = [];

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                continue;
            }
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
        parent::tearDown();
    }

    public function testOpeningAnEventDoesNotCallNextcloudUntilAFolderIsLinked(): void
    {
        $this->useTestCloud();
        $client = $this->browser();
        $created = $this->createEvent($client);
        $called = false;
        $show = $this->show(new MockHttpClient(function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('', ['http_code' => 500]);
        }));

        $view = $show->forEvent($this->organizer(), $created['id']);

        self::assertFalse($called);
        self::assertTrue($view['configured']);
        self::assertFalse($view['linked']);
        self::assertSame('2026-12-30 Veranstaltungsname', $view['proposedName']);
        self::assertNull($view['notice']);
    }

    public function testCreatingAFolderStoresTheFileId(): void
    {
        $this->useTestCloud();
        $client = $this->browser();
        $created = $this->createEvent($client);
        $urls = [];
        $show = $this->show(new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $method.' '.$url;
            self::assertStringStartsWith('https://nextcloud.test/', $url);
            self::assertStringNotContainsString('test-app-password', $url);
            if ($method === 'MKCOL') {
                self::assertStringContainsString('/2026-12-30%20Veranstaltungsname', $url);

                return new MockResponse('', ['http_code' => 201]);
            }

            return new MockResponse($this->folderXml(), ['http_code' => 207]);
        }));

        $view = $show->create($this->organizer(), $created['id']);
        $folders = static::getContainer()->get(EventNextcloudFolderRepository::class);
        self::assertInstanceOf(EventNextcloudFolderRepository::class, $folders);
        $stored = $folders->forEvent($created['id']);

        self::assertNotNull($stored);
        self::assertSame('62375', $stored->fileId());
        self::assertSame('0000062375ocinstance', $stored->ocId());
        self::assertSame('2026-12-30 Veranstaltungsname', $stored->name());
        self::assertStringNotContainsString('/', $stored->fileId());
        self::assertSame('62375', $view['fileId']);
        self::assertSame('https://nextcloud.test/apps/files/files/62375?dir=/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname', $view['url']);
        self::assertStringNotContainsString('index.php', $view['url']);
        self::assertStringNotContainsString('/remote.php/', $view['url']);
        self::assertStringNotContainsString('test-app-password', json_encode($view, JSON_THROW_ON_ERROR));
        self::assertNotSame([], $urls);
    }

    public function testAMissingStoredFolderKeepsTheNameWithoutAnError(): void
    {
        $this->useTestCloud();
        $client = $this->browser();
        $created = $this->createEvent($client);
        $events = static::getContainer()->get(EventRepository::class);
        $folders = static::getContainer()->get(EventNextcloudFolderRepository::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $clock = static::getContainer()->get(ZurichClock::class);
        self::assertInstanceOf(EventRepository::class, $events);
        self::assertInstanceOf(EventNextcloudFolderRepository::class, $folders);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ZurichClock::class, $clock);
        $event = $events->byId($created['id']);
        self::assertNotNull($event);
        $folders->add(new \App\Domain\Event\EventNextcloudFolder(
            $event,
            '62375',
            '0000062375ocinstance',
            '2026-12-30 Veranstaltungsname',
            $clock->now(),
        ));
        $entityManager->flush();

        $show = $this->show(new MockHttpClient(fn (): MockResponse => new MockResponse('missing', ['http_code' => 404])));
        $view = $show->forEvent($this->organizer(), $created['id']);

        self::assertTrue($view['linked']);
        self::assertFalse($view['reachable']);
        self::assertNull($view['notice']);
        self::assertSame('2026-12-30 Veranstaltungsname', $view['name']);
        self::assertSame('62375', $view['fileId']);
        self::assertSame([], $view['documents']);
        self::assertNull($view['url']);
        self::assertSame('62375', $folders->forEvent($created['id'])?->fileId());
    }

    public function testAVisibleMemberUploadsOnlyIntoTheLinkedFolder(): void
    {
        $this->useTestCloud();
        $client = $this->browser();
        $created = $this->createEvent($client);
        $this->rememberFolder($created['id']);
        $puts = [];
        $show = $this->show(new MockHttpClient(function (string $method, string $url, array $options) use (&$puts): MockResponse {
            self::assertStringStartsWith('https://nextcloud.test/', $url);
            self::assertStringNotContainsString('test-app-password', $url);
            self::assertNotContains($method, ['MKCOL', 'MOVE', 'DELETE']);
            if ($method === 'PUT') {
                $puts[] = $url;
                self::assertSame('%PDF-1.4', $options['body'] ?? null);

                return new MockResponse('', ['http_code' => 201]);
            }
            if ($method === 'SEARCH') {
                return new MockResponse($this->folderXml(), ['http_code' => 207]);
            }
            if ($method === 'PROPFIND') {
                return new MockResponse($this->folderWithFileXml(), ['http_code' => 207]);
            }
            self::fail('Unexpected Nextcloud call.');
        }));
        $member = new MemberUser('dev|member', 'Mitglied', ['Mitglieder'], null, ['historic']);

        self::assertFalse($member->isOrganizer());
        $view = $show->addFile($member, $created['id'], '../Andere/Plan.pdf', '%PDF-1.4');

        self::assertSame([
            'https://nextcloud.test/remote.php/dav/files/verein/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname/Plan.pdf',
        ], $puts);
        self::assertSame('https://nextcloud.test/apps/files/files/62375?dir=/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname', $view['url']);
        self::assertSame('Plan.pdf', $view['documents'][0]['name']);
        self::assertSame('70001', $view['documents'][0]['fileId']);
        self::assertSame('https://nextcloud.test/apps/files/files/70001?dir=/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname&openfile=true', $view['documents'][0]['url']);
        self::assertStringNotContainsString('/remote.php/', $view['documents'][0]['url']);
        self::assertStringNotContainsString('index.php', $view['documents'][0]['url']);
        self::assertStringNotContainsString('test-app-password', json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyUploadIsRejectedInGerman(): void
    {
        $this->useTestCloud();
        $client = $this->browser();
        $created = $this->createEvent($client);
        $this->rememberFolder($created['id']);
        $show = $this->show(new MockHttpClient(function (): MockResponse {
            self::fail('An empty selection must not be uploaded.');
        }));

        try {
            $show->addFile($this->organizer(), $created['id'], '   ', '');
            self::fail('An empty selection should be rejected.');
        } catch (InvalidRequest $exception) {
            self::assertSame('Bitte eine Datei wählen.', $exception->getMessage());
        }
    }

    private function show(MockHttpClient $http): ShowNextcloud
    {
        $catalog = static::getContainer()->get(EventCatalog::class);
        $folders = static::getContainer()->get(EventNextcloudFolderRepository::class);
        $plans = static::getContainer()->get(GastroPlanRepository::class);
        $rides = static::getContainer()->get(RideRepository::class);
        $credentials = static::getContainer()->get(NextcloudCredentials::class);
        $audit = static::getContainer()->get(AuditRecorder::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $clock = static::getContainer()->get(ZurichClock::class);
        self::assertInstanceOf(EventCatalog::class, $catalog);
        self::assertInstanceOf(EventNextcloudFolderRepository::class, $folders);
        self::assertInstanceOf(GastroPlanRepository::class, $plans);
        self::assertInstanceOf(RideRepository::class, $rides);
        self::assertInstanceOf(NextcloudCredentials::class, $credentials);
        self::assertInstanceOf(AuditRecorder::class, $audit);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ZurichClock::class, $clock);

        return new ShowNextcloud(
            $catalog,
            $folders,
            $plans,
            $rides,
            new NextcloudClient($http, 'test'),
            new NextcloudAreas(),
            $credentials,
            $audit,
            $entityManager,
            $clock,
        );
    }

    private function organizer(): MemberUser
    {
        return new MemberUser('dev|local', 'Demo Mitglied', ['admin', 'Mitglieder'], null, ['historic', 'supporter']);
    }

    private function useTestCloud(): void
    {
        foreach ([
            'NEXTCLOUD_BASE' => 'https://nextcloud.test',
            'NEXTCLOUD_USER' => 'verein',
            'NEXTCLOUD_APP_PASSWORD' => 'test-app-password',
        ] as $key => $value) {
            if (!array_key_exists($key, $this->previous)) {
                $current = $_ENV[$key] ?? false;
                $this->previous[$key] = is_string($current) ? $current : false;
            }
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    /** @return array<string, mixed> */
    private function createEvent(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $this->request($client, 'POST', '/api/v1/events', [
            'title' => 'Veranstaltungsname',
            'clubs' => ['historic'],
            'categories' => ['Fahrt'],
            'status' => 'published',
            'days' => [
                ['date' => '2026-12-30', 'startsAt' => '09:00', 'endsAt' => '16:00', 'location' => 'Winterthur'],
                ['date' => '2027-01-02', 'startsAt' => '09:00', 'endsAt' => '16:00', 'location' => 'Winterthur'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody($client);
    }

    private function rememberFolder(string $eventId): void
    {
        $events = static::getContainer()->get(EventRepository::class);
        $folders = static::getContainer()->get(EventNextcloudFolderRepository::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $clock = static::getContainer()->get(ZurichClock::class);
        self::assertInstanceOf(EventRepository::class, $events);
        self::assertInstanceOf(EventNextcloudFolderRepository::class, $folders);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ZurichClock::class, $clock);
        $event = $events->byId($eventId);
        self::assertNotNull($event);
        $folders->add(new \App\Domain\Event\EventNextcloudFolder(
            $event,
            '62375',
            '0000062375ocinstance',
            '2026-12-30 Veranstaltungsname',
            $clock->now(),
        ));
        $entityManager->flush();
    }

    private function folderXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response>'
            .'<d:href>/remote.php/dav/files/verein/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname/</d:href>'
            .'<d:propstat><d:prop>'
            .'<d:displayname>2026-12-30 Veranstaltungsname</d:displayname>'
            .'<d:resourcetype><d:collection/></d:resourcetype>'
            .'<oc:fileid>62375</oc:fileid>'
            .'<oc:id>0000062375ocinstance</oc:id>'
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
            .'</d:response></d:multistatus>';
    }

    private function folderWithFileXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            .'<d:response>'
            .'<d:href>/remote.php/dav/files/verein/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname/</d:href>'
            .'<d:propstat><d:prop>'
            .'<d:displayname>2026-12-30 Veranstaltungsname</d:displayname>'
            .'<d:resourcetype><d:collection/></d:resourcetype>'
            .'<oc:fileid>62375</oc:fileid>'
            .'<oc:id>0000062375ocinstance</oc:id>'
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
            .'</d:response>'
            .'<d:response>'
            .'<d:href>/remote.php/dav/files/verein/Fahrten_Anl%C3%A4sse/2026-12-30%20Veranstaltungsname/Plan.pdf</d:href>'
            .'<d:propstat><d:prop>'
            .'<d:displayname>Plan.pdf</d:displayname>'
            .'<d:resourcetype></d:resourcetype>'
            .'<oc:fileid>70001</oc:fileid>'
            .'<oc:id>0000070001ocinstance</oc:id>'
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
            .'</d:response>'
            .'</d:multistatus>';
    }
}
