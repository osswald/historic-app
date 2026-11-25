<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'place')]
#[ORM\UniqueConstraint(name: 'uniq_place_name', columns: ['name'])]
class Place
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 200)]
    private string $name;

    public function __construct(string $name, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->name = $name;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
}
