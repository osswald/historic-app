<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class AuthenticationTest extends ApiTestCase
{
    public function testMissingTokenIsRejected(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/me', token: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'Anmeldung erforderlich.'], $this->jsonBody($client));
    }

    public function testEventsRequireAToken(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/events', token: null);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDevTokenSignsInTheDemoMember(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/me');

        self::assertResponseIsSuccessful();
        $profile = $this->jsonBody($client);
        self::assertSame('Demo Mitglied', $profile['displayName']);
        self::assertTrue($profile['canOrganize']);
    }

    public function testUnknownBearerIsRejected(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/me', token: 'not-the-dev-token');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'Anmeldung erforderlich.'], $this->jsonBody($client));
    }

    public function testDevTokenIsRejectedWhenDemoModeIsOff(): void
    {
        $_SERVER['AUTH_DEV_MODE'] = $_ENV['AUTH_DEV_MODE'] = '0';
        putenv('AUTH_DEV_MODE=0');

        try {
            $client = $this->browser();
            $this->request($client, 'GET', '/api/v1/me');
            self::assertResponseStatusCodeSame(401);
            self::assertSame(['error' => 'Anmeldung erforderlich.'], $this->jsonBody($client));
        } finally {
            $_SERVER['AUTH_DEV_MODE'] = $_ENV['AUTH_DEV_MODE'] = '1';
            putenv('AUTH_DEV_MODE=1');
        }
    }
}
