<?php

declare(strict_types=1);

namespace App\Support\Messages;

final class FetchTableDdlsMessages
{
    public const array MESSAGES = [
        'Grabbing those table definitions…',
        'Pulling the blueprints for those tables…',
        'Peeking at the schema you asked for…',
        'Fetching DDLs — give me a sec…',
        'Inspecting the table structure…',
        'Schema spelunking in progress…',
        'Reading the fine print on those tables…',
    ];

    private static ?int $fakeIndex = null;

    public static function random(): string
    {
        $messages = self::MESSAGES;
        $index = self::$fakeIndex ?? self::generateStableIndex();

        return $messages[$index % count($messages)];
    }

    public static function fakeIndex(int $index): void
    {
        self::$fakeIndex = $index;
    }

    public static function resetFake(): void
    {
        self::$fakeIndex = null;
    }

    private static function generateStableIndex(): int
    {
        return crc32(microtime());
    }
}
