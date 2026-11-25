<?php

declare(strict_types=1);

namespace App\Application\Gastronomy;

use App\Domain\Gastronomy\GastroPlan;
use App\Security\MemberUser;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class GastroAccess
{
    public function canEdit(MemberUser $actor, ?GastroPlan $plan): bool
    {
        if ($actor->isAdmin() || $actor->isGastro()) {
            return true;
        }
        if (!$plan instanceof GastroPlan) {
            return false;
        }
        foreach ($plan->responsible() as $person) {
            if ($person->memberSub() === $actor->getUserIdentifier()) {
                return true;
            }
        }

        return false;
    }

    public function assertCanEdit(MemberUser $actor, ?GastroPlan $plan): void
    {
        if (!$this->canEdit($actor, $plan)) {
            throw new AccessDeniedException('Die Gastronomie bearbeiten Administratoren, das Gastro-Team und die benannten Verantwortlichen.');
        }
    }
}
