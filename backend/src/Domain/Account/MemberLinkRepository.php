<?php

declare(strict_types=1);

namespace App\Domain\Account;

interface MemberLinkRepository
{
    public function remember(string $sub, ?string $spondId): void;

    /** @return list<array{sub: string, spondId: string}> */
    public function linked(): array;
}
