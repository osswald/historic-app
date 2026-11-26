<?php

declare(strict_types=1);

namespace App\Application\Spond;

/** No local copy of Auth0 users. Matching still runs and reports zero hits. */
final class EmptySpondAccountDirectory implements SpondAccountDirectory
{
    public function known(): array
    {
        return [];
    }
}
