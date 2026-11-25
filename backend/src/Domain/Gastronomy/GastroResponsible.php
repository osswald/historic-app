<?php

declare(strict_types=1);

namespace App\Domain\Gastronomy;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gastro_responsible')]
class GastroResponsible
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'responsible')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GastroPlan $plan;

    #[ORM\Column(length: 190)]
    private string $memberSub;

    #[ORM\Column(length: 200)]
    private string $displayName;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(GastroPlan $plan, string $memberSub, string $displayName, int $sortOrder, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->plan = $plan;
        $this->memberSub = $memberSub;
        $this->displayName = $displayName;
        $this->sortOrder = $sortOrder;
    }

    public function memberSub(): string
    {
        return $this->memberSub;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    /** @return array{sub: string, name: string} */
    public function toAudit(): array
    {
        return [
            'sub' => $this->memberSub,
            'name' => $this->displayName,
        ];
    }
}
