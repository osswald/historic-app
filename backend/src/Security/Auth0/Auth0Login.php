<?php

declare(strict_types=1);

namespace App\Security\Auth0;

use App\Security\MemberUser;

interface Auth0Login
{
    public function isConfigured(): bool;

    public function authorizeUrl(string $redirectUri, string $state): string;

    public function authenticate(#[\SensitiveParameter] string $code, string $redirectUri): MemberUser;

    public function logoutUrl(string $returnTo): string;
}
