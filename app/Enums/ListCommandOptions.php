<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Options for the list subcommand
 */
enum ListCommandOptions: string
{
    case DEFINITIONS = 'definitions';
    case TABLES = 'tables';

    /**
     * Get all available options
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid option
     */
    public static function isValid(string $option): bool
    {
        return in_array($option, self::values(), true);
    }

    /**
     * Parse a string option (case-insensitive)
     */
    public static function fromString(string $option): ?self
    {
        $option = strtolower(trim($option));

        foreach (self::cases() as $case) {
            if ($case->value === $option) {
                return $case;
            }
        }

        return null;
    }
}
