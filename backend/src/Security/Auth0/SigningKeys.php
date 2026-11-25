<?php

declare(strict_types=1);

namespace App\Security\Auth0;

use Firebase\JWT\Key;

interface SigningKeys
{
    /** @return Key|\ArrayAccess<string, Key> */
    public function keys(): Key|\ArrayAccess;
}
