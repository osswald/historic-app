<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Audit\AuditActor;
use App\Application\Audit\AuditChanges;
use App\Application\Audit\AuditWhen;
use PHPUnit\Framework\TestCase;

final class AuditHistoryTest extends TestCase
{
    public function testTimestampUsesZurichAndGermanMonthNames(): void
    {
        $winter = new \DateTimeImmutable('2026-11-26 19:38:00', new \DateTimeZone('UTC'));
        self::assertSame('26. November 2026, 20:38', AuditWhen::format($winter));

        $summer = new \DateTimeImmutable('2026-07-26 18:38:00', new \DateTimeZone('UTC'));
        self::assertSame('26. Juli 2026, 20:38', AuditWhen::format($summer));
    }

    public function testMissingSnapshotsDoNotInventValues(): void
    {
        self::assertSame([], (new AuditChanges())->between(null, null));
    }

    public function testChangedFieldKeepsOldAndNew(): void
    {
        $changes = (new AuditChanges())->between(
            ['title' => 'Frühlingsfahrt', 'status' => 'draft'],
            ['title' => 'Herbstfahrt', 'status' => 'draft'],
        );

        self::assertSame([
            ['label' => 'Titel', 'before' => 'Frühlingsfahrt', 'after' => 'Herbstfahrt'],
        ], $changes);
    }

    public function testCreateShowsTheNewValueWithoutAnOldOne(): void
    {
        $changes = (new AuditChanges())->between(null, ['title' => 'Neue Fahrt']);

        self::assertSame([
            ['label' => 'Titel', 'before' => null, 'after' => 'Neue Fahrt'],
        ], $changes);
    }

    public function testActorUsesStoredNameThenKnownNameThenIdentifier(): void
    {
        $actors = new AuditActor();

        self::assertSame('Vera Verein', $actors->label('Vera Verein', 'auth0|1', 'Andere'));
        self::assertSame('Bekannte Person', $actors->label(null, 'auth0|2', 'Bekannte Person'));
        self::assertSame('auth0|3', $actors->label(null, 'auth0|3', null));
        self::assertNull($actors->label('  ', '  ', null));
    }
}
