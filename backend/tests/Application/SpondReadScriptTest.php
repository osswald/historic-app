<?php

declare(strict_types=1);

namespace App\Tests\Application;

use PHPUnit\Framework\TestCase;

final class SpondReadScriptTest extends TestCase
{
    public function testTheScriptOnlyCallsReadMethods(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/bin/spond_read.py');
        self::assertIsString($source);
        self::assertSame(1, preg_match('/get_groups\s*\(/', $source));
        self::assertSame(1, preg_match('/get_events\s*\(/', $source));
        self::assertSame(1, preg_match('/(?<!get_)get_event\s*\(/', $source));
        self::assertSame(0, preg_match('/\b(change_response|update_event|send_message|get_person)\s*\(/', $source));
        self::assertSame(0, preg_match('/\b(email|firstName|lastName|phoneNumber)\b/', $source));
        self::assertMatchesRegularExpression('/MAX_EVENTS\s*=\s*500/', $source);
        self::assertMatchesRegularExpression('/min_start\s*=\s*min_start/', $source);
        self::assertMatchesRegularExpression('/max_start\s*=\s*max_start/', $source);
        self::assertStringContainsString('2000, 1, 1', $source);
        self::assertStringContainsString('2045, 12, 31', $source);
        self::assertStringNotContainsString('Führung inbahn', $source);
    }
}
