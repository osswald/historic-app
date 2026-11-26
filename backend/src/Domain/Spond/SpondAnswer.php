<?php

declare(strict_types=1);

namespace App\Domain\Spond;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'spond_answer')]
#[ORM\UniqueConstraint(name: 'spond_answer_event', columns: ['event_id', 'spond_id'])]
class SpondAnswer
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpondEvent $event;

    #[ORM\Column(length: 80)]
    private string $spondId;

    #[ORM\Column(enumType: SpondReply::class)]
    private SpondReply $reply;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $matchedSub;

    public function __construct(SpondEvent $event, string $spondId, SpondReply $reply, ?string $comment, ?string $matchedSub)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->spondId = $spondId;
        $this->reply = $reply;
        $this->comment = $comment;
        $this->matchedSub = $matchedSub;
    }

    public function spondId(): string
    {
        return $this->spondId;
    }

    public function reply(): SpondReply
    {
        return $this->reply;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function matchedSub(): ?string
    {
        return $this->matchedSub;
    }
}
