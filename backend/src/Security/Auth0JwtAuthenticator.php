<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Auth0\Auth0Tenant;
use App\Security\Auth0\Auth0TokenException;
use App\Security\Auth0\Auth0TokenReader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class Auth0JwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private Auth0Tenant $tenant,
        private Auth0TokenReader $tokens,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if ($this->tenant->domain() === '' || $this->tenant->audience() === '') {
            return false;
        }
        $header = $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }
        $token = trim(substr($header, 7));

        return substr_count($token, '.') === 2;
    }

    public function authenticate(Request $request): Passport
    {
        $token = trim(substr((string) $request->headers->get('Authorization'), 7));
        try {
            $user = $this->tokens->memberFromTokens($token);
        } catch (Auth0TokenException $exception) {
            throw new CustomUserMessageAuthenticationException($exception->getMessage());
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): MemberUser => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessage()
            : 'Anmeldung ungültig.';

        return new JsonResponse(['error' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
