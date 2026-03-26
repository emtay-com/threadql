<?php

declare(strict_types=1);

namespace App\Support\Messages;

final class RunQueryForCsvExportMessages
{
    public const MESSAGES = [
        'Preparing your CSV — this might take a moment…',
        'Running that query and building your export…',
        'Let me compose that SELECT and export it…',
        'CSV generation in progress…',
        'Hold tight — fetching rows for your export…',
        'Building the CSV pipeline…',
        'SQL + CSV magic in progress…',
    ];

    public const FOLLOWUP_MESSAGES = [
        'Preparing your CSV — this might take a moment…',
        'Refining that query for export…',
        'Running the new selection and building CSV…',
        'Composing that refined SELECT for export…',
        'Adjusting and re-exporting…',
        'One more run for the updated export…',
        'Tweaking the query and rebuilding the CSV…',
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
