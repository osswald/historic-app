<?php

declare(strict_types=1);

namespace App\Security\Auth0;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class Auth0Tenant
{
    public function __construct(
        #[Autowire('%env(AUTH0_DOMAIN)%')]
        private string $domain,
        #[Autowire('%env(AUTH0_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(AUTH0_CLIENT_SECRET)%')]
        #[\SensitiveParameter]
        private string $clientSecret,
        #[Autowire('%env(AUTH0_AUDIENCE)%')]
        private string $audience,
        #[Autowire('%env(AUTH0_GROUPS_CLAIM)%')]
        private string $groupsClaim,
    ) {
    }

    public function configured(): bool
    {
        return $this->domain() !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->audience !== '';
    }

    public function domain(): string
    {
        $domain = trim($this->domain);
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;

        return rtrim($domain, '/');
    }

    public function issuer(): string
    {
        return 'https://'.$this->domain().'/';
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function audience(): string
    {
        return $this->audience;
    }

    public function groupsClaim(): string
    {
        return $this->groupsClaim;
    }
}
