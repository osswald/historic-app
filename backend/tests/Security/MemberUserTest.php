<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\MemberUser;
use PHPUnit\Framework\TestCase;

final class MemberUserTest extends TestCase
{
    public function testAdminReceivesOrganizerAsWell(): void
    {
        $user = new MemberUser('auth0|admin', 'Admin', ['admin']);

        self::assertEqualsCanonicalizing(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_ORGANIZER'], $user->getRoles());
        self::assertTrue($user->isAdmin());
        self::assertTrue($user->isOrganizer());
        self::assertTrue($user->canPlanRides());
        self::assertTrue($user->canEditFleet());
    }

    public function testNamedGroupsMapToTheirRoles(): void
    {
        $cases = [
            'Vorstand' => ['ROLE_USER', 'ROLE_ORGANIZER'],
            'Fahrtenplanung' => ['ROLE_USER', 'ROLE_RIDE_PLANNER'],
            'Gastro-Team' => ['ROLE_USER', 'ROLE_GASTRO'],
            'Technische Kommission' => ['ROLE_USER', 'ROLE_FLEET'],
            'Mitglieder' => ['ROLE_USER'],
        ];

        foreach ($cases as $group => $roles) {
            $user = new MemberUser('auth0|'.$group, $group, [$group]);
            self::assertEqualsCanonicalizing($roles, $user->getRoles(), $group);
        }
    }

    public function testMitgliederStayOrdinaryMembers(): void
    {
        $user = new MemberUser('auth0|member', 'Mitglied', ['Mitglieder']);

        self::assertFalse($user->isOrganizer());
        self::assertFalse($user->canPlanRides());
        self::assertFalse($user->canEditFleet());
        self::assertFalse($user->isGastro());
        self::assertSame(['Mitglieder'], $user->profile()['groups']);
    }

    public function testSeveralGroupsCombine(): void
    {
        $user = new MemberUser('auth0|mix', 'Mix', ['admin', 'Gastro-Team', 'Mitglieder']);

        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_ORGANIZER', $user->getRoles());
        self::assertContains('ROLE_GASTRO', $user->getRoles());
        self::assertTrue($user->isGastro());
    }
}
