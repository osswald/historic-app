<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Account\MemberLink;
use App\Domain\Account\MemberLinkRepository;
use App\Security\MemberUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MemberLink> */
final class DoctrineMemberLinkRepository extends ServiceEntityRepository implements MemberLinkRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberLink::class);
    }

    public function remember(string $sub, ?string $spondId): void
    {
        $sub = trim($sub);
        $spondId = MemberUser::normalizeSpondId($spondId);
        if ($sub === '' || mb_strlen($sub) > 190) {
            return;
        }

        $existing = $this->find($sub);
        if ($spondId === null) {
            if ($existing instanceof MemberLink) {
                $this->getEntityManager()->remove($existing);
            }

            return;
        }
        if ($existing instanceof MemberLink) {
            $existing->replaceSpondId($spondId);

            return;
        }

        $this->getEntityManager()->persist(new MemberLink($sub, $spondId));
    }

    public function linked(): array
    {
        $rows = [];
        foreach ($this->findBy([], ['sub' => 'ASC']) as $link) {
            if (!$link instanceof MemberLink) {
                continue;
            }
            $rows[] = ['sub' => $link->sub(), 'spondId' => $link->spondId()];
        }

        return $rows;
    }
}
