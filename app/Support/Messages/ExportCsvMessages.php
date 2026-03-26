<?php

declare(strict_types=1);

namespace App\Support\Messages;

final class ExportCsvMessages
{
    public const array MESSAGES = [
        'Preparing your CSV…',
        'Packaging the rows for download…',
        'Building your spreadsheet…',
        'Wrapping those rows into a CSV…',
        'Exporting your data to CSV…',
        'Almost done — packaging the data…',
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
