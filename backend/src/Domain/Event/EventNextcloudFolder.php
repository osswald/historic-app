<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_nextcloud_folder')]
#[ORM\UniqueConstraint(name: 'uniq_event_nextcloud_folder_event', columns: ['event_id'])]
class EventNextcloudFolder
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 32)]
    private string $fileId;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $ocId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Event $event, string $fileId, ?string $ocId, string $name, \DateTimeImmutable $now, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->fileId = $fileId;
        $this->ocId = $ocId;
        $this->name = $name;
        $this->createdAt = $now;
    }

    public function fileId(): string
    {
        return $this->fileId;
    }

    public function ocId(): ?string
    {
        return $this->ocId;
    }

    public function name(): string
    {
        return $this->name;
    }
}
