<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\DevTokenAuthenticator;
use App\Security\MemberUser;
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

    public function testEmptyDevSpondIdStaysUnset(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer dev');

        $empty = $this->authenticator('test', true, null)->authenticate($request)->getUser();
        self::assertInstanceOf(MemberUser::class, $empty);
        self::assertNull($empty->spondId());

        $blank = $this->authenticator('test', true, '   ')->authenticate($request)->getUser();
        self::assertInstanceOf(MemberUser::class, $blank);
        self::assertNull($blank->spondId());

        $linked = $this->authenticator('test', true, ' member-1 ')->authenticate($request)->getUser();
        self::assertInstanceOf(MemberUser::class, $linked);
        self::assertSame('member-1', $linked->spondId());
    }

    public function testOtherTokensAreIgnored(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer something-else');

        self::assertFalse($this->authenticator('test', true)->supports($request));
    }

    private function authenticator(string $environment, bool $enabled, ?string $spondId = null): DevTokenAuthenticator
    {
        return new DevTokenAuthenticator($enabled, 'Demo Mitglied', 'admin,Mitglieder', 'historic,supporter', $environment, $spondId);
    }
}
