<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Auth0\Auth0Claims;
use App\Security\Auth0\Auth0LoginException;
use App\Security\Auth0\Auth0Tenant;
use App\Security\Auth0\Auth0TokenReader;
use App\Security\Auth0\HttpAuth0Login;
use App\Security\Auth0\SigningKeys;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpAuth0LoginTest extends TestCase
{
    use SignsAuth0Tokens;

    private const GROUPS = 'https://historic-winterthur.ch/groups';

    public function testAuthorizeUrlCarriesTheCodeFlowAndNotTheSecret(): void
    {
        $login = $this->login(new MockHttpClient());
        $url = $login->authorizeUrl('http://127.0.0.1:5173/api/v1/auth/callback', 'abc');
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);

        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertSame('login.example.test', $parts['host'] ?? null);
        self::assertSame('/authorize', $parts['path'] ?? null);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('openid profile email', $query['scope'] ?? null);
        self::assertArrayNotHasKey('audience', $query);
        self::assertSame('http://127.0.0.1:5173/api/v1/auth/callback', $query['redirect_uri'] ?? null);
        self::assertSame('abc', $query['state'] ?? null);
        self::assertSame('test-client', $query['client_id'] ?? null);
        self::assertArrayNotHasKey('client_secret', $query);
        self::assertStringNotContainsString('test-secret', $url);

        $logout = parse_url($login->logoutUrl('http://127.0.0.1:5173/'));
        parse_str((string) ($logout['query'] ?? ''), $logoutQuery);
        self::assertSame('/v2/logout', $logout['path'] ?? null);
        self::assertSame('http://127.0.0.1:5173/', $logoutQuery['returnTo'] ?? null);
        self::assertArrayNotHasKey('client_secret', $logoutQuery);
    }

    public function testCodeExchangeValidatesTheAccessToken(): void
    {
        $access = $this->token([
            'aud' => 'https://app.historic-winterthur.ch/api',
            'sub' => 'auth0|42',
            self::GROUPS => ['Technische Kommission', 'Mitglieder'],
        ]);
        $id = $this->token([
            'aud' => 'test-client',
            'sub' => 'auth0|42',
            'name' => 'Technik Team',
        ]);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($access, $id): MockResponse {
            self::assertSame('POST', $method);
            $parts = parse_url($url);
            self::assertSame('login.example.test', $parts['host'] ?? null);
            self::assertSame('/oauth/token', $parts['path'] ?? null);
            self::assertSame('', (string) ($parts['query'] ?? ''));
            $body = $options['body'] ?? null;
            if (is_string($body)) {
                parse_str($body, $parsed);
            } else {
                $parsed = $body;
            }
            self::assertIsArray($parsed);
            self::assertSame('authorization_code', $parsed['grant_type'] ?? null);
            self::assertSame('the-code', $parsed['code'] ?? null);
            self::assertSame('http://127.0.0.1:5173/api/v1/auth/callback', $parsed['redirect_uri'] ?? null);
            self::assertArrayHasKey('client_secret', $parsed);
            self::assertSame(0, $options['max_redirects'] ?? null);

            return new MockResponse(json_encode([
                'access_token' => $access,
                'id_token' => $id,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $member = $this->login($http)->authenticate('the-code', 'http://127.0.0.1:5173/api/v1/auth/callback');

        self::assertSame('Technik Team', $member->displayName());
        self::assertSame(['Technische Kommission', 'Mitglieder'], $member->groups());
        self::assertTrue($member->isFleet());
        self::assertFalse($member->isOrganizer());
    }

    public function testOpaqueAccessTokenFallsBackToTheIdToken(): void
    {
        $id = $this->token([
            'aud' => 'test-client',
            'sub' => 'auth0|42',
            'name' => 'Id Token',
            self::GROUPS => ['Mitglieder'],
        ]);
        $http = new MockHttpClient(new MockResponse(json_encode([
            'access_token' => 'opaque-userinfo-token',
            'id_token' => $id,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]));

        $member = $this->login($http)->authenticate('the-code', 'http://127.0.0.1:5173/api/v1/auth/callback');

        self::assertSame('Id Token', $member->displayName());
        self::assertSame(['Mitglieder'], $member->groups());
        self::assertFalse($member->isOrganizer());
    }

    public function testTokenEndpointErrorsStayGeneric(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"leak-marker"}', ['http_code' => 401]));

        try {
            $this->login($http)->authenticate('the-code', 'http://127.0.0.1:5173/api/v1/auth/callback');
            self::fail('Expected the exchange to fail.');
        } catch (Auth0LoginException $exception) {
            self::assertSame('Die Anmeldung hat nicht geklappt.', $exception->getMessage());
            self::assertStringNotContainsString('leak-marker', $exception->getMessage());
        }
    }

    private function login(MockHttpClient $http): HttpAuth0Login
    {
        $tenant = new Auth0Tenant(
            'login.example.test',
            'test-client',
            'test-secret',
            'https://app.historic-winterthur.ch/api',
            self::GROUPS,
        );
        $keys = new class(self::$publicKey) implements SigningKeys {
            public function __construct(private Key $key)
            {
            }

            public function keys(): Key|\ArrayAccess
            {
                return $this->key;
            }
        };

        return new HttpAuth0Login($tenant, new Auth0TokenReader($tenant, $keys, new Auth0Claims()), $http);
    }
}
