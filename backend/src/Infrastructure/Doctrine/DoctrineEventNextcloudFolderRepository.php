<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Event\EventNextcloudFolder;
use App\Domain\Event\EventNextcloudFolderRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventNextcloudFolder> */
final class DoctrineEventNextcloudFolderRepository extends ServiceEntityRepository implements EventNextcloudFolderRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventNextcloudFolder::class);
    }

    public function add(EventNextcloudFolder $folder): void
    {
        $this->getEntityManager()->persist($folder);
    }

    public function forEvent(string $eventId): ?EventNextcloudFolder
    {
        $folder = $this->findOneBy(['event' => $eventId]);

        return $folder instanceof EventNextcloudFolder ? $folder : null;
    }
}
