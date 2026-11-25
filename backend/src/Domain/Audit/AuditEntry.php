<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'audit_entry')]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $actorSub;

    #[ORM\Column(length: 80)]
    private string $entityType;

    #[ORM\Column(length: 64)]
    private string $entityId;

    #[ORM\Column(length: 40)]
    private string $action;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $beforeData;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $afterData;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string, mixed>|null $beforeData
     *  @param array<string, mixed>|null $afterData
     */
    public function __construct(
        string $actorSub,
        string $entityType,
        string $entityId,
        string $action,
        ?array $beforeData,
        ?array $afterData,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->actorSub = $actorSub;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->action = $action;
        $this->beforeData = $beforeData;
        $this->afterData = $afterData;
        $this->occurredAt = $occurredAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function actorSub(): string
    {
        return $this->actorSub;
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function action(): string
    {
        return $this->action;
    }

    /** @return array<string, mixed>|null */
    public function beforeData(): ?array
    {
        return $this->beforeData;
    }

    /** @return array<string, mixed>|null */
    public function afterData(): ?array
    {
        return $this->afterData;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
