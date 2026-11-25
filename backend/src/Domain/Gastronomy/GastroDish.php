<?php

declare(strict_types=1);

namespace App\Domain\Gastronomy;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gastro_dish')]
class GastroDish
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'dishes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GastroPlan $plan;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private int $portions;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(GastroPlan $plan, string $name, int $portions, int $sortOrder, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->plan = $plan;
        $this->name = $name;
        $this->portions = $portions;
        $this->sortOrder = $sortOrder;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function portions(): int
    {
        return $this->portions;
    }

    /** @return array{name: string, portions: int} */
    public function toAudit(): array
    {
        return [
            'name' => $this->name,
            'portions' => $this->portions,
        ];
    }
}
