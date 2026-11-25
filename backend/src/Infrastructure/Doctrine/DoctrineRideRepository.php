<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\EventStatus;
use App\Domain\Ride\AssignmentKind;
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

    public function get(string $id): ?Ride
    {
        $ride = $this->find($id);

        return $ride instanceof Ride ? $ride : null;
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

    public function withOwnVehiclesExcept(string $eventId): array
    {
        /** @var list<Ride> $rides */
        $rides = $this->createQueryBuilder('ride')
            ->distinct()
            ->innerJoin('ride.vehicles', 'assignment')
            ->innerJoin('ride.event', 'occasion')
            ->andWhere('assignment.kind = :kind')
            ->andWhere('IDENTITY(ride.event) != :eventId')
            ->andWhere('occasion.status != :cancelled')
            ->setParameter('kind', AssignmentKind::Own)
            ->setParameter('eventId', $eventId)
            ->setParameter('cancelled', EventStatus::Cancelled)
            ->getQuery()
            ->getResult();

        return $rides;
    }
}
