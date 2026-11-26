<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_helfer_link')]
#[ORM\UniqueConstraint(name: 'event_helfer_link_event', columns: ['event_id'])]
class EventHelferLink
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 80)]
    private string $helferEventId;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $title;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Event $event, string $helferEventId, ?string $title, ?string $url, \DateTimeImmutable $now, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->helferEventId = $helferEventId;
        $this->title = $title;
        $this->url = $url;
        $this->createdAt = $now;
    }

    public function helferEventId(): string
    {
        return $this->helferEventId;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function retarget(string $helferEventId, ?string $title, ?string $url): void
    {
        $this->helferEventId = $helferEventId;
        $this->title = $title;
        $this->url = $url;
    }
}
