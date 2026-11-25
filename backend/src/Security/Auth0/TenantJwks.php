<?php

declare(strict_types=1);

namespace App\Security\Auth0;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\Key;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;

final class TenantJwks implements SigningKeys
{
    public function __construct(
        private Auth0Tenant $tenant,
        private Psr18Client $http,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function keys(): Key|\ArrayAccess
    {
        $domain = $this->tenant->domain();
        if ($domain === '') {
            throw new Auth0TokenException('Token ungültig.');
        }

        return new CachedKeySet(
            'https://'.$domain.'/.well-known/jwks.json',
            $this->http,
            $this->http,
            $this->cache,
            3600,
            true,
            'RS256',
        );
    }
}
