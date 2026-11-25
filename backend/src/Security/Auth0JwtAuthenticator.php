<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
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
        #[Autowire('%env(AUTH0_DOMAIN)%')]
        private string $domain,
        #[Autowire('%env(AUTH0_AUDIENCE)%')]
        private string $audience,
        #[Autowire('%env(AUTH0_GROUPS_CLAIM)%')]
        private string $groupsClaim,
        private Psr18Client $http,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if ($this->domain() === '' || $this->audience === '') {
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
        $user = $this->userFromToken($token);

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

    private function userFromToken(string $jwt): MemberUser
    {
        try {
            $parts = explode('.', $jwt);
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
            if (($header->alg ?? null) !== 'RS256') {
                throw new CustomUserMessageAuthenticationException('Nur RS256-Token sind erlaubt.');
            }
            JWT::$leeway = 60;
            $keys = new CachedKeySet(
                'https://'.$this->domain().'/.well-known/jwks.json',
                $this->http,
                $this->http,
                $this->cache,
                3600,
                true,
                'RS256',
            );
            $decoded = JWT::decode($jwt, $keys);
        } catch (AuthenticationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new CustomUserMessageAuthenticationException('Token ungültig.');
        }

        $issuer = 'https://'.$this->domain().'/';
        if (($decoded->iss ?? null) !== $issuer) {
            throw new CustomUserMessageAuthenticationException('Aussteller ungültig.');
        }
        $audiences = $decoded->aud ?? null;
        $audiences = is_array($audiences) ? $audiences : [$audiences];
        if (!in_array($this->audience, $audiences, true)) {
            throw new CustomUserMessageAuthenticationException('Zielgruppe ungültig.');
        }
        $sub = trim((string) ($decoded->sub ?? ''));
        if ($sub === '') {
            throw new CustomUserMessageAuthenticationException('Token ohne Benutzer.');
        }

        $claim = $this->groupsClaim !== '' ? ($decoded->{$this->groupsClaim} ?? null) : null;
        $groups = $claim ?? ($decoded->groups ?? []);
        if (is_string($groups)) {
            $groups = [$groups];
        }
        if (!is_array($groups)) {
            $groups = [];
        }
        $groups = array_values(array_filter($groups, 'is_string'));
        $name = trim((string) ($decoded->name ?? $decoded->nickname ?? $decoded->email ?? $sub));

        return new MemberUser($sub, $name !== '' ? $name : $sub, $groups);
    }

    private function domain(): string
    {
        $domain = trim($this->domain);
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;

        return rtrim($domain, '/');
    }
}
