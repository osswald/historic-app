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
        self::assertSame([], $user->clubs());
        self::assertNull($user->picture());
        self::assertTrue($user->isOrganizer());
        self::assertFalse($user->canPlanRides());
    }

    public function testSessionKeepsAnHttpsPictureAndDropsOtherValues(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = Request::create('/api/v1/auth/callback');
        $request->setSession($session);
        $store = new MemberSession('historic_session');
        $store->store($request, new MemberUser('auth0|1', 'Vera Verein', ['Mitglieder'], 'https://cdn.example.test/vera.jpg', ['historic', 'fremd']));
        $saved = $request->getSession()->get(MemberSession::KEY);
        self::assertIsArray($saved);
        self::assertSame('https://cdn.example.test/vera.jpg', $saved['picture']);
        self::assertSame(['historic'], $saved['clubs']);

        $request->cookies->set('historic_session', $request->getSession()->getId());
        $user = $store->read($request);
        self::assertInstanceOf(MemberUser::class, $user);
        self::assertSame('https://cdn.example.test/vera.jpg', $user->picture());

        $request->getSession()->set(MemberSession::KEY, [
            'sub' => 'auth0|1',
            'displayName' => 'Vera Verein',
            'groups' => ['Mitglieder'],
            'picture' => 'http://cdn.example.test/vera.jpg',
        ]);
        $user = $store->read($request);
        self::assertInstanceOf(MemberUser::class, $user);
        self::assertNull($user->picture());
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
