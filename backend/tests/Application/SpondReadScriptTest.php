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
    }
}
