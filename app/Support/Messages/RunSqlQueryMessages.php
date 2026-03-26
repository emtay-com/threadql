<?php

declare(strict_types=1);

namespace App\Support\Messages;

final class RunSqlQueryMessages
{
    public const MESSAGES = [
        'Running that query now…',
        'Executing the query…',
        'Crunching numbers — one moment…',
        'Let me fetch those rows for you…',
        'On it — SQL engines warming up…',
        'Talking to the database…',
        'Firing up the query engine…',
    ];

    public const FOLLOWUP_MESSAGES = [
        'Running that query now…',
        'Refining the previous selection…',
        'Crunching the new filter…',
        'Adjusting the query…',
        'Running it again with your changes…',
        'One more round of crunching…',
        'Back at it — tweaking and re-running…',
    ];

    private static ?int $fakeIndex = null;

    public static function random(): string
    {
        $messages = self::MESSAGES;
        $index = self::$fakeIndex ?? self::generateStableIndex();

        return $messages[$index % count($messages)];
    }

    public static function randomFollowUp(): string
    {
        $messages = self::FOLLOWUP_MESSAGES;
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
