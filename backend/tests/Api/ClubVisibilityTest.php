<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Security\MemberUser;
use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ClubVisibilityTest extends ApiTestCase
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

    public function testMembersSeePublishedEventsAndPollsOfTheirClubsOnly(): void
    {
        self::ensureKernelShutdown();
        $admin = $this->browser();
        $historic = $this->createEvent($admin, 'Historic veröffentlicht', ['historic'], 'published', '2026-12-05');
        $this->createEvent($admin, 'Historic Entwurf', ['historic'], 'draft', '2026-12-06');
        $this->createEvent($admin, 'Supporter veröffentlicht', ['supporter'], 'published', '2026-12-07');
        $this->request($admin, 'POST', '/api/v1/polls', $this->poll('Historic Umfrage', ['historic']));
        self::assertResponseStatusCodeSame(201);
        $this->request($admin, 'POST', '/api/v1/polls', $this->poll('Supporter Umfrage', ['supporter']));
        self::assertResponseStatusCodeSame(201);

        $member = $this->signIn(new MemberUser('auth0|vera', 'Vera Verein', ['Mitglieder'], null, ['historic']));
        $this->request($member, 'GET', '/api/v1/me', token: null);
        self::assertSame(['historic'], $this->jsonBody($member)['clubs']);

        $this->request($member, 'GET', '/api/v1/events', token: null);
        self::assertSame(['Historic veröffentlicht'], $this->titles($this->jsonBody($member)['events']));
        $this->request($member, 'GET', '/api/v1/events/'.$historic['id'], token: null);
        self::assertResponseIsSuccessful();

        $this->request($member, 'GET', '/api/v1/polls', token: null);
        self::assertSame(['Historic Umfrage'], $this->titles($this->jsonBody($member)['polls']));

        $organizer = $this->signIn(new MemberUser('auth0|board', 'Vorstand', ['Vorstand'], null, ['historic']));
        $this->request($organizer, 'GET', '/api/v1/events', token: null);
        self::assertSame(['Historic Entwurf', 'Historic veröffentlicht'], $this->titles($this->jsonBody($organizer)['events']));

        $outsider = $this->signIn(new MemberUser('auth0|ohne', 'Ohne Verein', ['Mitglieder']));
        $this->request($outsider, 'GET', '/api/v1/events', token: null);
        self::assertSame([], $this->jsonBody($outsider)['events']);
        $this->request($outsider, 'GET', '/api/v1/polls', token: null);
        self::assertSame([], $this->jsonBody($outsider)['polls']);

        self::ensureKernelShutdown();
        $again = $this->browser();
        $this->request($again, 'GET', '/api/v1/events');
        self::assertSame(
            ['Historic Entwurf', 'Historic veröffentlicht', 'Supporter veröffentlicht'],
            $this->titles($this->jsonBody($again)['events']),
        );
    }

    public function testInvitationsNarrowAccessAndCountPendingPeople(): void
    {
        self::ensureKernelShutdown();
        $admin = $this->browser();
        $known = $this->createEvent($admin, 'Bekannt', ['historic'], 'published', '2026-12-05');
        $vera = $this->signIn(new MemberUser('auth0|vera', 'Vera Verein', ['Mitglieder'], null, ['historic']));
        $this->request($vera, 'POST', '/api/v1/event-days/'.$known['days'][0]['id'].'/responses', ['answer' => 'yes'], null);
        self::assertResponseIsSuccessful();

        self::ensureKernelShutdown();
        $admin = $this->browser();
        $future = $this->createEvent($admin, 'Nur Mitglieder', ['historic'], 'published', '2026-12-20');
        $this->request($admin, 'PUT', '/api/v1/events/'.$future['id'], $this->eventPayload('Nur Mitglieder', ['historic'], 'published', '2026-12-20', $future['days'][0]['id'], [
            'groups' => ['Mitglieder'],
            'people' => [['sub' => 'auth0|vera']],
            'excluded' => [],
        ]));
        self::assertResponseIsSuccessful();
        $saved = $this->jsonBody($admin);
        self::assertSame(['Mitglieder'], $saved['invitations']['groups']);
        self::assertSame('Vera Verein', $saved['invitations']['people'][0]['name']);
        self::assertSame(1, $saved['days'][0]['summary']['pending']);
        self::assertSame('Vera Verein', $saved['days'][0]['pending'][0]['name']);

        $vera = $this->signIn(new MemberUser('auth0|vera', 'Vera Verein', ['Mitglieder'], null, ['historic']));
        $this->request($vera, 'GET', '/api/v1/events/'.$future['id'], token: null);
        self::assertResponseIsSuccessful();
        $this->request($vera, 'POST', '/api/v1/event-days/'.$saved['days'][0]['id'].'/responses', ['answer' => 'no'], null);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->jsonBody($vera)['days'][0]['summary']['pending']);
        self::assertSame(1, $this->jsonBody($vera)['days'][0]['summary']['no']);

        $other = $this->signIn(new MemberUser('auth0|andere', 'Andere Person', ['Andere'], null, ['historic']));
        $this->request($other, 'GET', '/api/v1/events/'.$future['id'], token: null);
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        $admin = $this->browser();
        $this->request($admin, 'PUT', '/api/v1/events/'.$future['id'], $this->eventPayload('Nur Mitglieder', ['historic'], 'published', '2026-12-20', $saved['days'][0]['id'], [
            'groups' => ['Mitglieder'],
            'people' => [],
            'excluded' => [['sub' => 'auth0|vera']],
        ]));
        self::assertResponseIsSuccessful();
        $vera = $this->signIn(new MemberUser('auth0|vera', 'Vera Verein', ['Mitglieder'], null, ['historic']));
        $this->request($vera, 'GET', '/api/v1/events/'.$future['id'], token: null);
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        $admin = $this->browser();
        $this->request($admin, 'PUT', '/api/v1/events/'.$future['id'], $this->eventPayload('Nur Mitglieder', ['historic'], 'published', '2026-12-20', $saved['days'][0]['id'], [
            'groups' => [],
            'people' => [['sub' => 'auth0|unbekannt']],
            'excluded' => [],
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('invitations.people', $this->jsonBody($admin)['errors']);
    }

    public function testGroupAccessDoesNotOpenPastEvents(): void
    {
        self::ensureKernelShutdown();
        $admin = $this->browser();
        $past = $this->createEvent($admin, 'Vergangen', ['historic'], 'published', '2026-01-15');
        $this->request($admin, 'PUT', '/api/v1/events/'.$past['id'], $this->eventPayload('Vergangen', ['historic'], 'published', '2026-01-15', $past['days'][0]['id'], [
            'groups' => ['Mitglieder'],
            'people' => [],
            'excluded' => [],
        ]));
        self::assertResponseIsSuccessful();

        $late = $this->signIn(new MemberUser('auth0|spaet', 'Spät dabei', ['Mitglieder'], null, ['historic']));
        $this->request($late, 'GET', '/api/v1/events/'.$past['id'], token: null);
        self::assertResponseStatusCodeSame(404);

        self::ensureKernelShutdown();
        $admin = $this->browser();
        $open = $this->createEvent($admin, 'Offen geblieben', ['historic'], 'published', '2026-01-16');
        $answered = $this->signIn(new MemberUser('auth0|frueh', 'Früh dabei', ['Mitglieder'], null, ['historic']));
        $this->request($answered, 'POST', '/api/v1/event-days/'.$open['days'][0]['id'].'/responses', ['answer' => 'yes'], null);
        self::assertResponseIsSuccessful();
        self::ensureKernelShutdown();
        $admin = $this->browser();
        $this->request($admin, 'PUT', '/api/v1/events/'.$open['id'], $this->eventPayload('Offen geblieben', ['historic'], 'published', '2026-01-16', $open['days'][0]['id'], [
            'groups' => ['Mitglieder'],
            'people' => [],
            'excluded' => [],
        ]));
        self::assertResponseIsSuccessful();
        $answered = $this->signIn(new MemberUser('auth0|frueh', 'Früh dabei', ['Mitglieder'], null, ['historic']));
        $this->request($answered, 'GET', '/api/v1/events/'.$open['id'], token: null);
        self::assertResponseIsSuccessful();
    }

    public function testOrganizerCannotAssignAnotherClub(): void
    {
        $organizer = $this->signIn(new MemberUser('auth0|board', 'Vorstand', ['Vorstand'], null, ['historic']));
        $this->request($organizer, 'POST', '/api/v1/events', $this->eventPayload('Falscher Verein', ['supporter'], 'draft', '2026-12-08'), null);

        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<string> $clubs */
    private function createEvent(KernelBrowser $client, string $title, array $clubs, string $status, string $date): array
    {
        $this->request($client, 'POST', '/api/v1/events', $this->eventPayload($title, $clubs, $status, $date));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody($client);
    }

    /**
     * @param list<string> $clubs
     * @param array{groups: list<string>, people: list<array{sub: string}>, excluded: list<array{sub: string}>}|null $invitations
     * @return array<string, mixed>
     */
    private function eventPayload(string $title, array $clubs, string $status, string $date, ?string $dayId = null, ?array $invitations = null): array
    {
        $day = [
            'date' => $date,
            'startsAt' => '09:00',
            'endsAt' => '16:00',
            'location' => 'Winterthur',
        ];
        if ($dayId !== null) {
            $day['id'] = $dayId;
        }
        $payload = [
            'title' => $title,
            'clubs' => $clubs,
            'categories' => ['Fahrt'],
            'status' => $status,
            'days' => [$day],
        ];
        if ($invitations !== null) {
            $payload['invitations'] = $invitations;
        }

        return $payload;
    }

    /** @param list<string> $clubs
     *  @return array<string, mixed>
     */
    private function poll(string $title, array $clubs): array
    {
        return [
            'title' => $title,
            'kind' => 'normal',
            'status' => 'open',
            'clubs' => $clubs,
            'options' => [['date' => '2026-12-12']],
        ];
    }

    /** @param list<array<string, mixed>> $rows
     *  @return list<string>
     */
    private function titles(array $rows): array
    {
        $titles = array_map(static fn (array $row): string => (string) $row['title'], $rows);
        sort($titles);

        return $titles;
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
        self::getContainer()->set(\App\Security\Auth0\HttpAuth0Login::class, new FixedAuth0Login($member));
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
