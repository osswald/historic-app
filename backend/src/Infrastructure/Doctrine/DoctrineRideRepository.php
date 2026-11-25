<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Ride\Ride;
use App\Domain\Ride\RideRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Ride> */
final class DoctrineRideRepository extends ServiceEntityRepository implements RideRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ride::class);
    }

    public function add(Ride $ride): void
    {
        $this->getEntityManager()->persist($ride);
    }

    public function remove(Ride $ride): void
    {
        $this->getEntityManager()->remove($ride);
    }

    public function forEvent(string $eventId): array
    {
        /** @var list<Ride> $rides */
        $rides = $this->createQueryBuilder('ride')
            ->andWhere('IDENTITY(ride.event) = :eventId')
            ->setParameter('eventId', $eventId)
            ->orderBy('ride.sortOrder', 'ASC')
            ->addOrderBy('ride.departureTime', 'ASC')
            ->getQuery()
            ->getResult();

        return $rides;
    }

    public function clubOwnedExcept(string $eventId): array
    {
        /** @var list<Ride> $rides */
        $rides = $this->createQueryBuilder('ride')
            ->andWhere('ride.clubVehicle = true')
            ->andWhere('IDENTITY(ride.event) != :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->getResult();

        return $rides;
    }
}
