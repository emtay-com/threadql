<?php

declare(strict_types=1);

namespace App\Support\Messages;

final class RequestDefinitionMessages
{
    public const array MESSAGES = [
        'I\'ll need a definition for that term — check the button above.',
        'Pausing for your definition — tap the button to add it.',
        'Hit a term I don\'t know — add a definition via the button above.',
        'This one\'s new to me — drop a definition to help me out.',
        'Need a little context for that term — use the button above.',
        'Almost there — just need you to define that term first.',
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
