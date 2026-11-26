<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Event\EventResponseRepository;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;

/** Hängt gespeicherte Spond-Teilnahmen an das Konto der Anmeldung. */
final class AttachSpondParticipation
{
    public function __construct(
        private EventResponseRepository $responses,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(MemberUser $user): void
    {
        $spondId = $user->spondId();
        if ($spondId === null || $spondId === '') {
            return;
        }
        $rows = $this->responses->bySpondMemberId($spondId);
        if ($rows === []) {
            return;
        }
        $finish = [];
        foreach ($rows as $response) {
            $finish[] = $response->linkAccount($user->getUserIdentifier());
        }
        $this->entityManager->flush();
        foreach ($finish as $apply) {
            $apply();
        }
        $this->entityManager->flush();
    }
}
