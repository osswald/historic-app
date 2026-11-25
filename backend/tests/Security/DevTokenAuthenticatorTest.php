<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\DevTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DevTokenAuthenticatorTest extends TestCase
{
    public function testEnabledDemoTokenIsRecognized(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer dev');

        self::assertTrue($this->authenticator(true)->supports($request));
    }

    public function testDisabledDemoTokenIsIgnored(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer dev');

        self::assertFalse($this->authenticator(false)->supports($request));
    }

    public function testOtherTokensAreIgnored(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer something-else');

        self::assertFalse($this->authenticator(true)->supports($request));
    }

    private function authenticator(bool $enabled): DevTokenAuthenticator
    {
        return new DevTokenAuthenticator($enabled, 'Demo Mitglied', 'admin');
    }
}
