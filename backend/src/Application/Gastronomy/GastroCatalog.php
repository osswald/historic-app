<?php

declare(strict_types=1);

namespace App\Application\Gastronomy;

use App\Application\Event\EventCatalog;
use App\Domain\Gastronomy\GastroDish;
use App\Domain\Gastronomy\GastroPlan;
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

    /** @return array{plans: list<array<string, mixed>>} */
    public function overview(MemberUser $user): array
    {
        $byEvent = [];
        foreach ($this->plans->all() as $plan) {
            $byEvent[$plan->eventId()] = $plan;
        }
        $rows = [];
        foreach ($this->events->visibleTo($user) as $event) {
            $plan = $byEvent[$event->id()] ?? null;
            $rows[] = [
                'eventId' => $event->id(),
                'title' => $event->title(),
                'status' => $event->status()->value,
                'statusLabel' => $event->status()->label(),
                'hasPlan' => $plan instanceof GastroPlan,
                'planId' => $plan?->id(),
                'guestCount' => $plan?->guestCount(),
                'dishes' => $plan instanceof GastroPlan
                    ? array_map(static fn (GastroDish $dish): string => $dish->name(), $plan->dishes())
                    : [],
            ];
        }

        return ['plans' => $rows];
    }
}
