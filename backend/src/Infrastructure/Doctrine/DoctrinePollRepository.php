<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Poll\Poll;
use App\Domain\Poll\PollRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Poll> */
final class DoctrinePollRepository extends ServiceEntityRepository implements PollRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Poll::class);
    }

    public function add(Poll $poll): void
    {
        $this->getEntityManager()->persist($poll);
    }

    public function byId(string $id): ?Poll
    {
        return $this->find($id);
    }

    public function all(): array
    {
        return $this->findBy([], ['createdAt' => 'ASC']);
    }
}
