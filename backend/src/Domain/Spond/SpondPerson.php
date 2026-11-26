<?php

declare(strict_types=1);

namespace App\Domain\Spond;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'spond_person')]
#[ORM\UniqueConstraint(name: 'spond_person_read', columns: ['read_id', 'spond_id'])]
class SpondPerson
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'people')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpondRead $read;

    #[ORM\Column(length: 80)]
    private string $spondId;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $matchedSub;

    public function __construct(SpondRead $read, string $spondId, ?string $matchedSub)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->read = $read;
        $this->spondId = $spondId;
        $this->matchedSub = $matchedSub;
    }

    public function spondId(): string
    {
        return $this->spondId;
    }

    public function matchedSub(): ?string
    {
        return $this->matchedSub;
    }
}
