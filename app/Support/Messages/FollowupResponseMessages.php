<?php

declare(strict_types=1);

namespace App\Support\Messages;

final class FollowupResponseMessages
{
    public const array MESSAGES = [
        'Picking up where we left off, %s…',
        'Building on the last result…',
        'Refining that now…',
        'Continuing the thread — one moment…',
        'Got it, %s — updating the results…',
        'On it, %s — narrowing things down…',
        'Following up now, %s…',
        'Tweaking that for you, %s…',
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
