<?php

declare(strict_types=1);

namespace App\Application\Gastronomy;

use App\Application\Event\EventCatalog;
use App\Domain\Gastronomy\GastroPlanRepository;
use App\Security\MemberUser;

final class GastroCatalog
{
    public function __construct(
        private EventCatalog $events,
        private GastroPlanRepository $plans,
        private GastroPresenter $presenter,
    ) {
    }

    /** @return array{canEdit: bool, plan: ?array<string, mixed>} */
    public function show(MemberUser $user, string $eventId): array
    {
        $event = $this->events->visible($eventId, $user);

        return $this->presenter->present($user, $this->plans->byEventId($event->id()));
    }
}
