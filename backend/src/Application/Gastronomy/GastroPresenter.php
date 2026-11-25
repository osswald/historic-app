<?php

declare(strict_types=1);

namespace App\Application\Gastronomy;

use App\Domain\Gastronomy\GastroDish;
use App\Domain\Gastronomy\GastroPlan;
use App\Domain\Gastronomy\GastroResponsible;
use App\Security\MemberUser;

final class GastroPresenter
{
    public function __construct(private GastroAccess $access)
    {
    }

    /** @return array{canEdit: bool, plan: ?array<string, mixed>} */
    public function present(MemberUser $actor, ?GastroPlan $plan): array
    {
        return [
            'canEdit' => $this->access->canEdit($actor, $plan),
            'plan' => $plan instanceof GastroPlan ? $this->plan($plan) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function plan(GastroPlan $plan): array
    {
        return [
            'id' => $plan->id(),
            'eventId' => $plan->eventId(),
            'guestCount' => $plan->guestCount(),
            'notes' => $plan->notes(),
            'dishes' => array_map(static fn (GastroDish $dish): array => [
                'id' => $dish->id(),
                'name' => $dish->name(),
                'portions' => $dish->portions(),
            ], $plan->dishes()),
            'responsible' => array_map(static fn (GastroResponsible $person): array => [
                'sub' => $person->memberSub(),
                'name' => $person->displayName(),
            ], $plan->responsible()),
        ];
    }
}
