<?php

declare(strict_types=1);

namespace App\Security\Auth0;

use App\Security\MemberUser;

final class Auth0TokenReader
{
    public function __construct(
        private Auth0Tenant $tenant,
        private SigningKeys $keys,
        private Auth0Claims $claims,
    ) {
    }

    public function memberFromTokens(#[\SensitiveParameter] string $accessToken, #[\SensitiveParameter] ?string $idToken = null): MemberUser
    {
        $keys = $this->keys->keys();
        $access = $this->claims->decode($accessToken, $keys, $this->tenant->issuer(), $this->tenant->audience());
        $id = null;
        if ($idToken !== null && $idToken !== '') {
            $id = $this->claims->decode($idToken, $keys, $this->tenant->issuer(), $this->tenant->clientId());
        }

        return $this->claims->member($access, $id, $this->tenant->groupsClaim());
    }

    public function memberFromIdToken(#[\SensitiveParameter] string $idToken): MemberUser
    {
        $id = $this->claims->decode($idToken, $this->keys->keys(), $this->tenant->issuer(), $this->tenant->clientId());

        return $this->claims->member($id, null, $this->tenant->groupsClaim());
    }
}
