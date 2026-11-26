<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Account\MemberLinkRepository;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;

/** Keeps the login claim so a later app:spond:read can match without the token. */
final class RememberMemberSpondId
{
    public function __construct(
        private MemberLinkRepository $links,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(MemberUser $user): void
    {
        $this->links->remember($user->getUserIdentifier(), $user->spondId());
        $this->entityManager->flush();
    }
}
