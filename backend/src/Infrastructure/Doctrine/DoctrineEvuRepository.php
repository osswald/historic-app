<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Ride\Evu;
use App\Domain\Ride\EvuRepository;
use App\Domain\Ride\Ride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Evu> */
final class DoctrineEvuRepository extends ServiceEntityRepository implements EvuRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evu::class);
    }

    public function add(Evu $evu): void
    {
        $this->getEntityManager()->persist($evu);
    }

    public function get(string $id): ?Evu
    {
        $evu = $this->find($id);

        return $evu instanceof Evu ? $evu : null;
    }

    public function byName(string $name): ?Evu
    {
        $evu = $this->createQueryBuilder('item')
            ->andWhere('LOWER(item.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $evu instanceof Evu ? $evu : null;
    }

    public function all(): array
    {
        /** @var list<Evu> $items */
        $items = $this->createQueryBuilder('item')
            ->orderBy('item.sortOrder', 'ASC')
            ->addOrderBy('item.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function nextSortOrder(): int
    {
        $value = $this->createQueryBuilder('item')
            ->select('COALESCE(MAX(item.sortOrder), -1)')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $value) + 1;
    }

    public function remove(Evu $evu): void
    {
        $this->getEntityManager()->remove($evu);
    }

    public function rideCount(Evu $evu): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(ride.id)')
            ->from(Ride::class, 'ride')
            ->andWhere('ride.evu = :evu')
            ->setParameter('evu', $evu)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
