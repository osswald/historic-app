<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\InvalidRequest;
use App\Application\NotFound;
use App\Domain\Event\EventCategory;
use App\Domain\Event\EventCategoryRepository;
use App\Security\MemberUser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ExtendEventCategoryCatalog
{
    public function __construct(
        private EventCategoryRepository $categories,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, name: string, active: bool, used: bool}
     */
    public function add(MemberUser $actor, array $payload): array
    {
        $this->assertAdmin($actor, 'Listen ergänzen dürfen nur Administratoren.');
        $name = $this->name($payload);
        if ($this->categories->byName($name) instanceof EventCategory) {
            $this->duplicate('Diese Veranstaltungskategorie gibt es bereits.');
        }

        $category = new EventCategory($name, $this->categories->nextSortOrder());
        $this->categories->add($category);
        $this->flush('Diese Veranstaltungskategorie gibt es bereits.');

        return $this->payload($category);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: string, name: string, active: bool, used: bool}
     */
    public function update(MemberUser $actor, string $id, array $payload): array
    {
        $this->assertAdmin($actor, 'Listen ändern dürfen nur Administratoren.');
        $category = $this->require($id);
        $category->setActive($this->activeFlag($payload));
        $this->entityManager->flush();

        return $this->payload($category);
    }

    public function delete(MemberUser $actor, string $id): void
    {
        $this->assertAdmin($actor, 'Listen ändern dürfen nur Administratoren.');
        $category = $this->require($id);
        if ($this->categories->eventCount($category) > 0) {
            throw new InvalidRequest(
                ['name' => 'Diese Veranstaltungskategorie wird noch verwendet und kann nicht gelöscht werden.'],
                'Diese Veranstaltungskategorie wird noch verwendet und kann nicht gelöscht werden.',
            );
        }
        $this->categories->remove($category);
        $this->entityManager->flush();
    }

    /**
     * @return array{categories: list<array{id: string, name: string, active: bool, used: bool}>}
     */
    public function show(MemberUser $actor): array
    {
        if (!$actor->isOrganizer()) {
            throw new AccessDeniedException('Die Veranstaltungskategorien sehen nur Organisatoren.');
        }

        return [
            'categories' => array_map(fn (EventCategory $category): array => $this->payload($category), $this->categories->all()),
        ];
    }

    private function assertAdmin(MemberUser $actor, string $message): void
    {
        if (!$actor->isAdmin()) {
            throw new AccessDeniedException($message);
        }
    }

    /** @param array<string, mixed> $payload */
    private function name(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidRequest(['name' => 'Bitte einen Namen angeben.']);
        }
        if (mb_strlen($name) > 80) {
            throw new InvalidRequest(['name' => 'Bitte höchstens 80 Zeichen angeben.']);
        }

        return $name;
    }

    /** @param array<string, mixed> $payload */
    private function activeFlag(array $payload): bool
    {
        if (!array_key_exists('active', $payload) || !is_bool($payload['active'])) {
            throw new InvalidRequest(['active' => 'Bitte angeben, ob der Eintrag aktiv ist.']);
        }

        return $payload['active'];
    }

    private function require(string $id): EventCategory
    {
        $category = $this->categories->get($id);
        if (!$category instanceof EventCategory) {
            throw new NotFound('Diese Veranstaltungskategorie gibt es nicht.');
        }

        return $category;
    }

    /** @return array{id: string, name: string, active: bool, used: bool} */
    private function payload(EventCategory $category): array
    {
        return [
            'id' => $category->id(),
            'name' => $category->name(),
            'active' => $category->isActive(),
            'used' => $this->categories->eventCount($category) > 0,
        ];
    }

    /** @return never */
    private function duplicate(string $message): void
    {
        throw new InvalidRequest(['name' => $message], $message);
    }

    private function flush(string $duplicate): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->duplicate($duplicate);
        }
    }
}
