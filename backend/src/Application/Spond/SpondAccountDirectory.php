<?php

declare(strict_types=1);

namespace App\Application\Spond;

/** Known Auth0 accounts that already carry historic_winterthur.spond_id. */
interface SpondAccountDirectory
{
    /** @return list<array{sub: string, appMetadata: mixed}> */
    public function known(): array;
}
