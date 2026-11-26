<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Nextcloud\NextcloudEntry;
use App\Application\Nextcloud\NextcloudAreas;
use PHPUnit\Framework\TestCase;

final class NextcloudAreasTest extends TestCase
{
    public function testSubfoldersAppearOnlyWhenThatFunctionIsAlreadyUsed(): void
    {
        $areas = new NextcloudAreas();
        $children = [
            $this->folder('Gastronomie', '11'),
            $this->folder('Fahrtenplanung', '12'),
            $this->folder('Sonstiges', '13'),
            new NextcloudEntry('14', null, 'Anordnung.pdf', 'Fahrten_Anlässe/Anlass/Anordnung.pdf', false),
        ];

        self::assertSame([], $areas->select(false, false, $children));
        self::assertSame(['Gastronomie'], array_map(static fn (NextcloudEntry $entry): string => $entry->name(), $areas->select(true, false, $children)));
        self::assertSame(['Fahrtenplanung'], array_map(static fn (NextcloudEntry $entry): string => $entry->name(), $areas->select(false, true, $children)));
        self::assertSame(
            ['Gastronomie', 'Fahrtenplanung'],
            array_map(static fn (NextcloudEntry $entry): string => $entry->name(), $areas->select(true, true, $children)),
        );
    }

    private function folder(string $name, string $fileId): NextcloudEntry
    {
        return new NextcloudEntry($fileId, null, $name, 'Fahrten_Anlässe/Anlass/'.$name, true);
    }
}
