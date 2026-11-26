<?php

declare(strict_types=1);

namespace App\Domain\Account;

use Doctrine\ORM\Mapping as ORM;

/** Local account link. Clubs stay on the session; this row lets a later Spond read match the id. */
#[ORM\Entity]
#[ORM\Table(name: 'member_link')]
class MemberLink
{
    #[ORM\Id]
    #[ORM\Column(length: 190)]
    private string $sub;

    #[ORM\Column(length: 80)]
    private string $spondId;

    public function __construct(string $sub, string $spondId)
    {
        $this->sub = $sub;
        $this->spondId = $spondId;
    }

    public function sub(): string
    {
        return $this->sub;
    }

    public function spondId(): string
    {
        return $this->spondId;
    }

    public function replaceSpondId(string $spondId): void
    {
        $this->spondId = $spondId;
    }
}
