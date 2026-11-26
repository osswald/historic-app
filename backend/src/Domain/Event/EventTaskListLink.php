<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_task_list')]
#[ORM\UniqueConstraint(name: 'event_task_list_event_list', columns: ['event_id', 'task_list_id'])]
class EventTaskListLink
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 80)]
    private string $taskListId;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Event $event, string $taskListId, ?string $name, \DateTimeImmutable $now, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->taskListId = $taskListId;
        $this->name = $name;
        $this->createdAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function eventId(): string
    {
        return $this->event->id();
    }

    public function taskListId(): string
    {
        return $this->taskListId;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function rememberName(?string $name): void
    {
        $this->name = $name;
    }
}
