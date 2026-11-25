<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Security\Auth0\Auth0Login;
use App\Security\Auth0\Auth0LoginException;
use App\Security\Auth0\HttpAuth0Login;
use App\Security\MemberUser;
use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Cookie;

final class Auth0LoginTest extends ApiTestCase
{
    private const HOME = 'http://127.0.0.1:5173/';

    protected function tearDown(): void
    {
        $this->clearAuth0();
        parent::tearDown();
    }

    public function testLoginIsRefusedWhenAuth0IsNotConfigured(): void
    {
        $client = $this->browser();
        $client->request('GET', '/api/v1/auth/login');

        self::assertResponseRedirects('http://localhost/?anmeldung=nicht-bereit');
    }

    public function testLoginRedirectsToTheAuthorizeEndpoint(): void
    {
        $this->configureAuth0();
        $client = $this->browser();
        $client->request('GET', '/api/v1/auth/login', server: $this->browserServer());

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $parts = parse_url($location);
        parse_str((string) ($parts['query'] ?? ''), $query);

        self::assertSame('login.example.test', $parts['host'] ?? null);
        self::assertSame('/authorize', $parts['path'] ?? null);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('openid profile email', $query['scope'] ?? null);
        self::assertSame('https://app.historic-winterthur.ch/api', $query['audience'] ?? null);
        self::assertSame('http://127.0.0.1:5173/api/v1/auth/callback', $query['redirect_uri'] ?? null);
        self::assertSame('test-client', $query['client_id'] ?? null);
        self::assertArrayNotHasKey('client_secret', $query);
        self::assertSame(64, strlen((string) ($query['state'] ?? '')));

        $cookie = $this->sessionCookie($client);
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, strtolower((string) $cookie->getSameSite()));
        self::assertSame('/', $cookie->getPath());
        self::assertFalse($cookie->isSecure());

        $client->request('GET', '/api/v1/auth/login', server: ['HTTP_HOST' => 'app.historic-winterthur.ch']);
        $production = parse_url((string) $client->getResponse()->headers->get('Location'));
        parse_str((string) ($production['query'] ?? ''), $productionQuery);
        self::assertSame('https://app.historic-winterthur.ch/api/v1/auth/callback', $productionQuery['redirect_uri'] ?? null);
    }

    public function testCallbackWithoutStateDoesNotSignIn(): void
    {
        $this->configureAuth0();
        $client = $this->browser();
        $client->request('GET', '/api/v1/auth/callback?code=abc&state=nope', server: $this->browserServer());

        self::assertResponseRedirects(self::HOME.'?anmeldung=fehlgeschlagen');
        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCallbackSessionSignsInAndKeepsTheDemoToken(): void
    {
        $client = $this->signIn(new MemberUser('auth0|vera', 'Vera Verein', ['Mitglieder']));

        $client->request('GET', '/api/v1/me');
        self::assertResponseIsSuccessful();
        $profile = $this->jsonBody($client);
        self::assertSame('Vera Verein', $profile['displayName']);
        self::assertSame(['Mitglieder'], $profile['groups']);
        self::assertFalse($profile['canOrganize']);

        $client->request('POST', '/api/v1/events', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer dev']);
        self::assertResponseIsSuccessful();
        self::assertSame('Demo Mitglied', $this->jsonBody($client)['displayName']);
    }

    public function testOrganizerSessionUsesTheSameRoleCheck(): void
    {
        $client = $this->signIn(new MemberUser('auth0|board', 'Vorstandsperson', ['Vorstand']));

        $client->request('GET', '/api/v1/me');
        self::assertTrue($this->jsonBody($client)['canOrganize']);

        $client->request('POST', '/api/v1/events', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(422);
    }

    public function testLogoutClearsTheSessionAndRedirectsToAuth0(): void
    {
        $client = $this->signIn(new MemberUser('auth0|vera', 'Vera Verein', ['Mitglieder']));
        $client->request('POST', '/api/v1/auth/logout', server: $this->browserServer());

        self::assertResponseStatusCodeSame(303);
        $location = (string) $client->getResponse()->headers->get('Location');
        $parts = parse_url($location);
        parse_str((string) ($parts['query'] ?? ''), $query);
        self::assertSame('login.example.test', $parts['host'] ?? null);
        self::assertSame('/v2/logout', $parts['path'] ?? null);
        self::assertSame(self::HOME, $query['returnTo'] ?? null);
        self::assertSame('test-client', $query['client_id'] ?? null);
        self::assertArrayNotHasKey('client_secret', $query);

        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    private function signIn(MemberUser $member): KernelBrowser
    {
        $this->configureAuth0();
        $client = $this->browser();
        $client->disableReboot();
        self::getContainer()->set(HttpAuth0Login::class, new FixedAuth0Login($member));
        $client->request('GET', '/api/v1/auth/login', server: $this->browserServer());
        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $client->request('GET', '/api/v1/auth/callback?code=ok&state='.rawurlencode((string) ($query['state'] ?? '')), server: $this->browserServer());
        self::assertResponseRedirects(self::HOME);

        return $client;
    }

    /** @return array<string, string> */
    private function browserServer(): array
    {
        return [
            'HTTP_HOST' => '127.0.0.1:5173',
        ];
    }

    private function sessionCookie(KernelBrowser $client): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'historic_session') {
                return $cookie;
            }
        }

        return null;
    }

    private function configureAuth0(): void
    {
        $values = [
            'AUTH0_DOMAIN' => 'login.example.test',
            'AUTH0_CLIENT_ID' => 'test-client',
            'AUTH0_CLIENT_SECRET' => 'test-secret',
            'AUTH0_AUDIENCE' => 'https://app.historic-winterthur.ch/api',
            'AUTH0_GROUPS_CLAIM' => 'https://historic-winterthur.ch/groups',
        ];
        foreach ($values as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    private function clearAuth0(): void
    {
        foreach (['AUTH0_DOMAIN', 'AUTH0_CLIENT_ID', 'AUTH0_CLIENT_SECRET', 'AUTH0_AUDIENCE'] as $key) {
            $_ENV[$key] = $_SERVER[$key] = '';
            putenv($key.'=');
        }
        $_ENV['AUTH0_GROUPS_CLAIM'] = $_SERVER['AUTH0_GROUPS_CLAIM'] = 'https://historic-winterthur.ch/groups';
        putenv('AUTH0_GROUPS_CLAIM=https://historic-winterthur.ch/groups');
    }
}

final class FixedAuth0Login implements Auth0Login
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
        return 'https://login.example.test/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'test-client',
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'audience' => 'https://app.historic-winterthur.ch/api',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
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
        return 'https://login.example.test/v2/logout?'.http_build_query([
            'client_id' => 'test-client',
            'returnTo' => $returnTo,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
