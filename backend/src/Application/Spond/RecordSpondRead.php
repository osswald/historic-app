<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Spond\SpondRead;
use App\Domain\Spond\SpondReadRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RecordSpondRead
{
    public function __construct(
        private SpondExportParser $parser,
        private SpondAccountDirectory $accounts,
        private SpondReadRepository $reads,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $export */
    public function __invoke(array $export): SpondRead
    {
        $read = $this->parser->parse(
            $export,
            $this->accounts->known(),
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')),
        );
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->reads->replace($read);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        return $read;
    }
}
