<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Spond\SpondRead;
use App\Domain\Spond\SpondReadRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SpondRead> */
final class DoctrineSpondReadRepository extends ServiceEntityRepository implements SpondReadRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpondRead::class);
    }

    public function replace(SpondRead $read): void
    {
        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM spond_answer');
        $connection->executeStatement('DELETE FROM spond_event');
        $connection->executeStatement('DELETE FROM spond_person');
        $connection->executeStatement('DELETE FROM spond_read');
        $entityManager->clear();
        $entityManager->persist($read);
    }

    public function latest(): ?SpondRead
    {
        $read = $this->findOneBy([], ['readAt' => 'DESC', 'id' => 'DESC']);

        return $read instanceof SpondRead ? $read : null;
    }
}
