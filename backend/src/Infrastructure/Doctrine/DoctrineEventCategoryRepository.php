<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\Event;
use App\Domain\Event\EventCategory;
use App\Domain\Event\EventCategoryRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventCategory> */
final class DoctrineEventCategoryRepository extends ServiceEntityRepository implements EventCategoryRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventCategory::class);
    }

    public function add(EventCategory $category): void
    {
        $this->getEntityManager()->persist($category);
    }

    public function get(string $id): ?EventCategory
    {
        $category = $this->find($id);

        return $category instanceof EventCategory ? $category : null;
    }

    public function byName(string $name): ?EventCategory
    {
        $category = $this->createQueryBuilder('item')
            ->andWhere('LOWER(item.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $category instanceof EventCategory ? $category : null;
    }

    public function all(): array
    {
        /** @var list<EventCategory> $items */
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

    public function remove(EventCategory $category): void
    {
        $this->getEntityManager()->remove($category);
    }

    public function eventCount(EventCategory $category): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(event.id)')
            ->from(Event::class, 'event')
            ->andWhere('event.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
