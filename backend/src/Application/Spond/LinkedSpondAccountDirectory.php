<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Account\MemberLinkRepository;

/** Accounts that already stored historic_winterthur.spond_id at login. */
final class LinkedSpondAccountDirectory implements SpondAccountDirectory
{
    public function __construct(private MemberLinkRepository $links)
    {
    }

    public function known(): array
    {
        $accounts = [];
        foreach ($this->links->linked() as $link) {
            $accounts[] = [
                'sub' => $link['sub'],
                'appMetadata' => [
                    'historic_winterthur' => ['spond_id' => $link['spondId']],
                ],
            ];
        }

        return $accounts;
    }
}
