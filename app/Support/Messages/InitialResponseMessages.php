<?php

declare(strict_types=1);

namespace App\Support\Messages;

final class InitialResponseMessages
{
    public const array MESSAGES = [
        'Hello %s, on it — give me a sec…',
        'Hey %s, working on it now…',
        'Got you %s — I\'ll check the numbers…',
        '%s, digging into that…',
        'On it, %s — querying the data now…',
        'Right on it, %s — give me a moment…',
        'One sec, %s — SQL engines are warming up…',
        'Let me dig into that for you, %s…',
    ];

    private static ?int $fakeIndex = null;

    public static function random(string $userHandle): string
    {
        $messages = self::MESSAGES;
        $index = self::$fakeIndex ?? self::generateStableIndex($userHandle);

        return sprintf($messages[$index % count($messages)], $userHandle);
    }

    public static function fakeIndex(int $index): void
    {
        self::$fakeIndex = $index;
    }

    public static function resetFake(): void
    {
        self::$fakeIndex = null;
    }

    private static function generateStableIndex(string $userHandle): int
    {
        return crc32($userHandle.microtime());
    }
}
