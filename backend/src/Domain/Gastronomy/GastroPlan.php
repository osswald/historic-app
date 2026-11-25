<?php

declare(strict_types=1);

namespace App\Domain\Gastronomy;

use App\Domain\Event\Event;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gastro_plan')]
#[ORM\UniqueConstraint(name: 'uniq_gastro_plan_event', columns: ['event_id'])]
class GastroPlan
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    #[ORM\Column(nullable: true)]
    private ?int $guestCount = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, GastroDish> */
    #[ORM\OneToMany(mappedBy: 'plan', targetEntity: GastroDish::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $dishes;

    /** @var Collection<int, GastroResponsible> */
    #[ORM\OneToMany(mappedBy: 'plan', targetEntity: GastroResponsible::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $responsible;

    public function __construct(Event $event, \DateTimeImmutable $now, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->notes = null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->dishes = new ArrayCollection();
        $this->responsible = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function eventId(): string
    {
        return $this->event->id();
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function guestCount(): ?int
    {
        return $this->guestCount;
    }

    /** @return list<GastroDish> */
    public function dishes(): array
    {
        return array_values($this->dishes->toArray());
    }

    /** @return list<GastroResponsible> */
    public function responsible(): array
    {
        return array_values($this->responsible->toArray());
    }

    /**
     * @param list<array{name: string, portions: int}> $dishes
     * @param list<array{sub: string, name: string}> $responsible
     */
    public function replace(?string $notes, ?int $guestCount, array $dishes, array $responsible, \DateTimeImmutable $now): void
    {
        $this->notes = $notes;
        $this->guestCount = $guestCount;
        $this->updatedAt = $now;
        foreach ($this->dishes->toArray() as $dish) {
            $this->dishes->removeElement($dish);
        }
        foreach ($dishes as $index => $dish) {
            $this->dishes->add(new GastroDish($this, $dish['name'], $dish['portions'], $index));
        }
        foreach ($this->responsible->toArray() as $person) {
            $this->responsible->removeElement($person);
        }
        foreach ($responsible as $index => $person) {
            $this->responsible->add(new GastroResponsible($this, $person['sub'], $person['name'], $index));
        }
    }

    /** @return array<string, mixed> */
    public function toAudit(): array
    {
        return [
            'eventId' => $this->event->id(),
            'notes' => $this->notes,
            'guestCount' => $this->guestCount,
            'dishes' => array_map(static fn (GastroDish $dish): array => $dish->toAudit(), $this->dishes()),
            'responsible' => array_map(static fn (GastroResponsible $person): array => $person->toAudit(), $this->responsible()),
        ];
    }
}
