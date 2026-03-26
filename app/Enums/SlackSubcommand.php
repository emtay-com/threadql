<?php

declare(strict_types=1);

namespace App\Enums;

enum SlackSubcommand: string
{
    case DEFINE = 'define';
    case HELP = 'help';
    case LIST = 'list';
    case SURVEY = 'survey';
    case DEBUG = 'debug';

    /**
     * Get all available subcommands
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid subcommand
     */
    public static function isValid(string $subcommand): bool
    {
        return in_array($subcommand, self::values(), true);
    }
}
