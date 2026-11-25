<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\MemberSession;
use App\Security\MemberSessionAuthenticator;
use App\Security\MemberUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class MemberSessionAuthenticatorTest extends TestCase
{
    public function testBearerRequestsStayWithTheTokenAuthenticators(): void
    {
        $request = Request::create('/api/v1/me');
        $request->headers->set('Authorization', 'Bearer dev');
        $request->cookies->set('historic_session', 'present');

        self::assertFalse($this->authenticator()->supports($request));
        self::assertFalse($request->hasSession());
    }

    public function testMissingCookieDoesNotStartASession(): void
    {
        $request = Request::create('/api/v1/me');

        self::assertFalse($this->authenticator()->supports($request));
        self::assertFalse($request->hasSession());
    }

    public function testSessionRestoresNameGroupsAndRoles(): void
    {
        $request = $this->requestWithMember([
            'sub' => 'auth0|1',
            'displayName' => 'Vera Verein',
            'groups' => ['Vorstand', 'Mitglieder'],
        ]);

        $authenticator = $this->authenticator();
        self::assertTrue($authenticator->supports($request));
        $user = $authenticator->authenticate($request)->getUser();

        self::assertInstanceOf(MemberUser::class, $user);
        self::assertSame('Vera Verein', $user->displayName());
        self::assertSame(['Vorstand', 'Mitglieder'], $user->groups());
        self::assertTrue($user->isOrganizer());
        self::assertFalse($user->canPlanRides());
    }

    public function testBrokenSessionPayloadIsDropped(): void
    {
        $request = $this->requestWithMember(['sub' => '']);
        $authenticator = $this->authenticator();

        self::assertFalse($authenticator->supports($request));
        self::assertFalse($request->getSession()->has(MemberSession::KEY));
    }

    /** @param array<string, mixed> $payload */
    private function requestWithMember(array $payload): Request
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set(MemberSession::KEY, $payload);
        $request = Request::create('/api/v1/me');
        $request->setSession($session);
        $request->cookies->set('historic_session', $session->getId());

        return $request;
    }

    private function authenticator(): MemberSessionAuthenticator
    {
        return new MemberSessionAuthenticator(new MemberSession('historic_session'));
    }
}
