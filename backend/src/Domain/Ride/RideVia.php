<?php

declare(strict_types=1);

namespace App\Domain\Ride;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ride_via')]
class RideVia
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'vias')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ride $ride;

    #[ORM\Column(length: 12)]
    private string $didokCode;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $abbreviation;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(
        Ride $ride,
        string $didokCode,
        string $name,
        ?string $abbreviation,
        int $sortOrder,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->ride = $ride;
        $this->didokCode = $didokCode;
        $this->name = $name;
        $this->abbreviation = $abbreviation;
        $this->sortOrder = $sortOrder;
    }

    public function didokCode(): string
    {
        return $this->didokCode;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function abbreviation(): ?string
    {
        return $this->abbreviation;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return array{didokCode: string, name: string, abbreviation: ?string} */
    public function toAudit(): array
    {
        return [
            'didokCode' => $this->didokCode,
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
        ];
    }
}
