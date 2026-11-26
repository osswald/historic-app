<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Spond\SpondExportParser;
use App\Application\Spond\SpondIdMatcher;
use App\Application\Spond\SpondResponseMap;
use App\Security\Auth0\Auth0Login;
use App\Security\Auth0\Auth0LoginException;
use App\Security\Auth0\HttpAuth0Login;
use App\Security\MemberUser;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SpondReadTest extends ApiTestCase
{
    private const HOME = 'http://127.0.0.1:5173/';

    protected function tearDown(): void
    {
        foreach (['AUTH0_DOMAIN', 'AUTH0_CLIENT_ID', 'AUTH0_CLIENT_SECRET', 'AUTH0_AUDIENCE'] as $key) {
            $_ENV[$key] = $_SERVER[$key] = '';
            putenv($key.'=');
        }
        $_ENV['AUTH0_GROUPS_CLAIM'] = $_SERVER['AUTH0_GROUPS_CLAIM'] = 'https://historic-winterthur.ch/groups';
        putenv('AUTH0_GROUPS_CLAIM=https://historic-winterthur.ch/groups');
        parent::tearDown();
    }

    public function testOrganizersSeeTheStoredReadAndMembersDoNot(): void
    {
        $client = $this->browser();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $read = (new SpondExportParser(new SpondResponseMap(), new SpondIdMatcher()))->parse($this->export(), [
            ['sub' => 'auth0|vera', 'appMetadata' => ['historic_winterthur' => ['spond_id' => 'spond-a']]],
            ['sub' => 'auth0|ghost', 'appMetadata' => ['historic_winterthur' => ['spond_id' => 'not-exported']]],
        ], new \DateTimeImmutable('2026-11-26T08:00:00+01:00'));
        $em->persist($read);
        $em->flush();

        $people = $em->getConnection()->fetchAllAssociative('SELECT spond_id, matched_sub FROM spond_person ORDER BY spond_id');
        self::assertSame([
            ['spond_id' => 'spond-a', 'matched_sub' => 'auth0|vera'],
            ['spond_id' => 'spond-b', 'matched_sub' => null],
            ['spond_id' => 'spond-c', 'matched_sub' => null],
        ], $people);

        $this->request($client, 'GET', '/api/v1/spond/read');
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody($client);
        self::assertTrue($body['read']['idsMatched']);
        self::assertSame('Historic Team Winterthur', $body['read']['groupName']);
        self::assertSame('Führung inbahn', $body['read']['eventHeading']);
        self::assertSame('2026-11-14', $body['read']['eventOn']);
        self::assertSame(3, $body['read']['memberCount']);
        self::assertSame(1, $body['read']['accepted']);
        self::assertSame(1, $body['read']['declined']);
        self::assertSame(1, $body['read']['unanswered']);
        self::assertSame(1, $body['read']['matchedCount']);
        self::assertSame(3, $body['read']['storedIdCount']);
        self::assertSame(['Bin verhindert'], $body['read']['answerComments']);
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('auth0|vera', $encoded);
        self::assertStringNotContainsString('not-exported', $encoded);
        self::assertStringNotContainsString('spond-a', $encoded);
        self::assertStringNotContainsString('@', $encoded);

        $member = $this->signIn(new MemberUser('auth0|member', 'Mitglied', ['Mitglieder'], null, ['historic']));
        $this->request($member, 'GET', '/api/v1/spond/read', token: null);
        self::assertResponseStatusCodeSame(403);

        $organizer = $this->signIn(new MemberUser('auth0|board', 'Vorstand', ['Vorstand'], null, ['historic']));
        $this->request($organizer, 'GET', '/api/v1/spond/read', token: null);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->jsonBody($organizer)['read']['matchedCount']);
    }

    public function testRequiresALogin(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/spond/read', token: null);

        self::assertResponseStatusCodeSame(401);
    }

    public function testOrganizersSeeAnEmptyRead(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/spond/read');

        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody($client)['read']);
    }

    /** @return array<string, mixed> */
    private function export(): array
    {
        return [
            'group' => [
                'spondId' => 'group-1',
                'name' => 'Historic Team Winterthur',
                'members' => [
                    ['spondId' => 'spond-a'],
                    ['spondId' => 'spond-b'],
                    ['spondId' => 'spond-c'],
                ],
            ],
            'event' => [
                'spondId' => 'event-1',
                'heading' => 'Führung inbahn',
                'startTimestamp' => '2026-11-14T09:30:00Z',
                'description' => 'Depot',
                'responses' => [
                    'acceptedIds' => ['spond-a'],
                    'declinedIds' => ['spond-b'],
                    'unansweredIds' => ['spond-c'],
                ],
                'answerComments' => [
                    ['spondId' => 'spond-b', 'comment' => 'Bin verhindert'],
                ],
                'options' => [
                    ['spondId' => 'task-1', 'name' => 'Kasse', 'description' => null, 'accepted' => 0, 'declined' => 0, 'unanswered' => 0],
                ],
            ],
        ];
    }

    private function signIn(MemberUser $member): KernelBrowser
    {
        foreach ([
            'AUTH0_DOMAIN' => 'login.example.test',
            'AUTH0_CLIENT_ID' => 'test-client',
            'AUTH0_CLIENT_SECRET' => 'test-secret',
            'AUTH0_AUDIENCE' => 'https://app.historic-winterthur.ch/api',
            'AUTH0_GROUPS_CLAIM' => 'https://historic-winterthur.ch/groups',
        ] as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        self::ensureKernelShutdown();
        $client = $this->browser();
        $client->disableReboot();
        self::getContainer()->set(HttpAuth0Login::class, new SpondReadAuth0Login($member));
        $client->request('GET', '/api/v1/auth/login', server: ['HTTP_HOST' => '127.0.0.1:5173']);
        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $client->request(
            'GET',
            '/api/v1/auth/callback?code=ok&state='.rawurlencode((string) ($query['state'] ?? '')),
            server: ['HTTP_HOST' => '127.0.0.1:5173'],
        );
        self::assertResponseRedirects(self::HOME);

        return $client;
    }
}

final class SpondReadAuth0Login implements Auth0Login
{
    public function __construct(private MemberUser $member)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://login.example.test/authorize?state='.rawurlencode($state);
    }

    public function authenticate(string $code, string $redirectUri): MemberUser
    {
        if ($code !== 'ok') {
            throw new Auth0LoginException('Die Anmeldung hat nicht geklappt.');
        }

        return $this->member;
    }

    public function logoutUrl(string $returnTo): string
    {
        return 'https://login.example.test/v2/logout?returnTo='.rawurlencode($returnTo);
    }
}
