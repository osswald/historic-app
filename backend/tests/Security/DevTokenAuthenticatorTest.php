<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\DevTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DevTokenAuthenticatorTest extends TestCase
{
    public function testEnabledDemoTokenIsRecognizedOutsideProduction(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer dev');

        self::assertTrue($this->authenticator('test', true)->supports($request));
    }

    public function testProductionRefusesTheDemoTokenEvenWhenTheFlagIsOn(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer dev');

        self::assertFalse($this->authenticator('prod', true)->supports($request));
    }

    public function testDisabledDemoTokenIsIgnored(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer dev');

        self::assertFalse($this->authenticator('test', false)->supports($request));
    }

    public function testOtherTokensAreIgnored(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer something-else');

        self::assertFalse($this->authenticator('test', true)->supports($request));
    }

    private function authenticator(string $environment, bool $enabled): DevTokenAuthenticator
    {
        return new DevTokenAuthenticator($enabled, 'Demo Mitglied', 'admin,Mitglieder', 'historic,supporter', $environment);
    }
}
