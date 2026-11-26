<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Ride\RideType;
use App\Domain\Ride\RideTypeRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RideType> */
final class DoctrineRideTypeRepository extends ServiceEntityRepository implements RideTypeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RideType::class);
    }

    public function add(RideType $rideType): void
    {
        $this->getEntityManager()->persist($rideType);
    }

    public function get(string $id): ?RideType
    {
        $rideType = $this->find($id);

        return $rideType instanceof RideType ? $rideType : null;
    }

    public function byName(string $name): ?RideType
    {
        $rideType = $this->createQueryBuilder('item')
            ->andWhere('LOWER(item.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $rideType instanceof RideType ? $rideType : null;
    }

    public function all(): array
    {
        /** @var list<RideType> $items */
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
}
