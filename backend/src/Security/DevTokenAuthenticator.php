<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DevTokenAuthenticator extends AbstractAuthenticator
{
    public const TOKEN = 'dev';
    public const SUBJECT = 'dev|local';

    public function __construct(
        #[Autowire('%env(bool:AUTH_DEV_MODE)%')]
        private bool $enabled,
        #[Autowire('%env(DEV_USER_NAME)%')]
        private string $displayName,
        #[Autowire('%env(DEV_USER_GROUPS)%')]
        private string $groups,
        #[Autowire('%env(default::DEV_USER_CLUBS)%')]
        private ?string $clubs,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(default::DEV_USER_SPOND_ID)%')]
        private ?string $spondId = null,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Production never accepts the demo token, even if AUTH_DEV_MODE is left on.
        if ($this->environment === 'prod' || !$this->enabled) {
            return false;
        }

        return $request->headers->get('Authorization') === 'Bearer '.self::TOKEN;
    }

    public function authenticate(Request $request): Passport
    {
        $groups = array_values(array_filter(array_map('trim', explode(',', $this->groups))));
        $clubs = array_values(array_filter(array_map('trim', explode(',', $this->clubs ?? ''))));

        return new SelfValidatingPassport(new UserBadge(self::SUBJECT, fn (): MemberUser => new MemberUser(
            self::SUBJECT,
            $this->displayName,
            $groups,
            null,
            $clubs,
            $this->spondId,
        )));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Anmeldung ungültig.'], Response::HTTP_UNAUTHORIZED);
    }
}
