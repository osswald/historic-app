<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Auth0\Auth0Claims;
use App\Security\Auth0\Auth0TokenException;
use PHPUnit\Framework\TestCase;

final class Auth0ClaimsTest extends TestCase
{
    use SignsAuth0Tokens;

    private const ISSUER = 'https://login.example.test/';
    private const AUDIENCE = 'https://app.historic-winterthur.ch/api';
    private const CLIENT = 'test-client';
    private const GROUPS = 'https://historic-winterthur.ch/groups';
    private const CLUBS = 'https://historic-winterthur.ch/clubs';

    private Auth0Claims $claims;

    protected function setUp(): void
    {
        $this->claims = new Auth0Claims();
    }

    public function testAccessTokenGroupsAndNameBecomeTheMember(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
            self::GROUPS => ['Vorstand', 'Mitglieder'],
        ]);

        $member = $this->member($access, null);

        self::assertSame('auth0|42', $member->getUserIdentifier());
        self::assertSame('Vera Verein', $member->displayName());
        self::assertSame(['Vorstand', 'Mitglieder'], $member->groups());
        self::assertTrue($member->isOrganizer());
        self::assertFalse($member->isAdmin());
    }

    public function testNameComesFromTheIdTokenWhenTheAccessTokenHasNone(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            self::GROUPS => ['Fahrtenplanung'],
        ]);
        $id = $this->token([
            'aud' => self::CLIENT,
            'sub' => 'auth0|42',
            'name' => 'Fahrten Planung',
        ]);

        $member = $this->member($access, $id);

        self::assertSame('Fahrten Planung', $member->displayName());
        self::assertTrue($member->canPlanRides());
        self::assertFalse($member->isOrganizer());
        self::assertNull($member->picture());
    }

    public function testOidcPictureIsKeptWhenItIsHttps(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            self::GROUPS => ['Mitglieder'],
        ]);
        $id = $this->token([
            'aud' => self::CLIENT,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
            'picture' => 'https://cdn.example.test/vera.jpg',
            'https://historic-winterthur.ch/picture' => 'https://cdn.example.test/other.jpg',
        ]);

        $member = $this->member($access, $id);

        self::assertSame('https://cdn.example.test/vera.jpg', $member->picture());
    }

    public function testCustomPictureClaimIsUsedWhenTheOidcClaimIsEmpty(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
        ]);
        $id = $this->token([
            'aud' => self::CLIENT,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
            'picture' => 'http://cdn.example.test/vera.jpg',
            'https://historic-winterthur.ch/picture' => 'https://cdn.example.test/fallback.jpg',
        ]);

        $member = $this->member($access, $id);

        self::assertSame('https://cdn.example.test/fallback.jpg', $member->picture());
    }

    public function testGroupsComeFromTheIdTokenWhenTheAccessTokenHasNone(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
        ]);
        $id = $this->token([
            'aud' => self::CLIENT,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
            self::GROUPS => ['Vorstand'],
        ]);

        $member = $this->member($access, $id);

        self::assertSame(['Vorstand'], $member->groups());
        self::assertTrue($member->isOrganizer());
    }

    public function testClubsComeOnlyFromTheIdToken(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
            self::CLUBS => ['supporter'],
            self::GROUPS => ['Mitglieder'],
        ]);
        $id = $this->token([
            'aud' => self::CLIENT,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
            self::CLUBS => ['historic', 'supporter', 'unbekannt'],
        ]);

        $member = $this->member($access, $id);

        self::assertSame(['historic', 'supporter'], array_map(static fn ($club) => $club->value, $member->clubs()));
    }

    public function testMissingClubClaimSeesNoClubs(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
            self::CLUBS => ['historic'],
        ]);
        $id = $this->token([
            'aud' => self::CLIENT,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
        ]);

        $member = $this->member($access, $id);

        self::assertSame([], $member->clubs());
        self::assertSame([], $member->profile()['clubs']);
    }

    public function testAdminGroupKeepsTheExistingRoleMapping(): void
    {
        $access = $this->token([
            'aud' => [self::AUDIENCE, 'another-audience'],
            'sub' => 'auth0|admin',
            'name' => 'Admin',
            self::GROUPS => 'admin',
        ]);

        $member = $this->member($access, null);

        self::assertEqualsCanonicalizing(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_ORGANIZER'], $member->getRoles());
    }

    public function testMismatchedIdTokenIsRejected(): void
    {
        $access = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            'name' => 'Vera Verein',
        ]);
        $id = $this->token([
            'aud' => self::CLIENT,
            'sub' => 'auth0|other',
            'name' => 'Jemand anders',
        ]);

        $this->expectException(Auth0TokenException::class);
        $this->member($access, $id);
    }

    public function testRejectedTokensDoNotBecomeMembers(): void
    {
        $cases = [
            'Zielgruppe ungültig.' => $this->token([
                'aud' => 'https://example.test/other',
                'sub' => 'auth0|42',
            ]),
            'Aussteller ungültig.' => $this->token([
                'iss' => 'https://other.example.test/',
                'aud' => self::AUDIENCE,
                'sub' => 'auth0|42',
            ]),
            'Token ungültig.' => $this->token([
                'aud' => self::AUDIENCE,
                'sub' => 'auth0|42',
                'exp' => time() - 120,
            ]),
            'Nur RS256-Token sind erlaubt.' => $this->unsigned('HS256'),
            'alg none' => $this->unsigned('none'),
        ];

        foreach ($cases as $message => $jwt) {
            try {
                $this->claims->decode($jwt, self::$publicKey, self::ISSUER, self::AUDIENCE);
                self::fail('Token should have been rejected.');
            } catch (Auth0TokenException $exception) {
                $expected = $message === 'alg none' ? 'Nur RS256-Token sind erlaubt.' : $message;
                self::assertSame($expected, $exception->getMessage());
            }
        }

        try {
            $this->claims->decode($this->badSignature(), self::$publicKey, self::ISSUER, self::AUDIENCE);
            self::fail('A bad signature should have been rejected.');
        } catch (Auth0TokenException $exception) {
            self::assertSame('Token ungültig.', $exception->getMessage());
        }
    }

    private function member(string $access, ?string $id): \App\Security\MemberUser
    {
        $accessClaims = $this->claims->decode($access, self::$publicKey, self::ISSUER, self::AUDIENCE);
        $idClaims = $id === null ? null : $this->claims->decode($id, self::$publicKey, self::ISSUER, self::CLIENT);

        return $this->claims->member($accessClaims, $idClaims, self::GROUPS);
    }

    private function unsigned(string $algorithm): string
    {
        $header = $this->jsonPart(['alg' => $algorithm, 'typ' => 'JWT']);
        $body = $this->jsonPart([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
            'exp' => time() + 600,
        ]);

        return $header.'.'.$body.'.signature';
    }

    private function badSignature(): string
    {
        $jwt = $this->token([
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|42',
        ]);
        $parts = explode('.', $jwt);
        $parts[2] = ($parts[2][0] === 'A' ? 'B' : 'A').substr($parts[2], 1);

        return implode('.', $parts);
    }

    /** @param array<string, mixed> $data */
    private function jsonPart(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
