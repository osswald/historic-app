<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class MemberSessionAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private MemberSession $sessions,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (str_starts_with($request->headers->get('Authorization', ''), 'Bearer ')) {
            return false;
        }

        return $this->sessions->read($request) instanceof MemberUser;
    }

    public function authenticate(Request $request): Passport
    {
        $user = $this->sessions->read($request);
        if (!$user instanceof MemberUser) {
            throw new CustomUserMessageAuthenticationException('Anmeldung erforderlich.');
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): MemberUser => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
