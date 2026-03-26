<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\ParseDefinitionCommand;
use App\Command\ParseDefinitionResponse;
use App\Infrastructure\Command\DomainCommandHandler;

/**
 * Handler for parsing definition input
 */
class ParseDefinitionHandler implements DomainCommandHandler
{
    /**
     * Handle the parse definition command
     */
    public function __invoke(ParseDefinitionCommand $command): ParseDefinitionResponse
    {
        $input = trim($command->input);

        if (empty($input)) {
            return ParseDefinitionResponse::emptyInput();
        }

        $parsed = $this->parseDefinitionInput($input);
        if (! $parsed) {
            return ParseDefinitionResponse::invalidSyntax();
        }

        return ParseDefinitionResponse::success($parsed['subject'], $parsed['definition']);
    }

    /**
     * Parse definition input to extract subject and definition
     *
     * @return array{subject: string, definition: string}|null
     */
    private function parseDefinitionInput(string $input): ?array
    {
        $input = trim($input);

        // Find the first occurrence of any divider based on position in string
        $lowerInput = strtolower($input);

        // Find positions of all potential dividers
        $posEquals = strpos($lowerInput, ' = ');
        $posIsA = strpos($lowerInput, ' is a ');
        $posIs = strpos($lowerInput, ' is ');

        // Find the earliest position
        $positions = [];
        if ($posEquals !== false) {
            $positions['='] = $posEquals;
        }
        if ($posIsA !== false) {
            $positions['is_a'] = $posIsA;
        }
        if ($posIs !== false) {
            $positions['is'] = $posIs;
        }

        if (empty($positions)) {
            return null; // No divider found
        }

        // Find the earliest divider
        $earliestPos = min($positions);

        // If we have both 'is' and 'is_a' at the same position, always use 'is_a' as the divider
        if (isset($positions['is']) && isset($positions['is_a']) && $positions['is'] === $positions['is_a'] && $positions['is'] === $earliestPos) {
            $earliestDivider = 'is_a';
        } else {
            $earliestDivider = array_search($earliestPos, $positions);
        }

        // Split based on the earliest divider
        switch ($earliestDivider) {
            case '=':
                $parts = explode(' = ', $input, 2);
                break;
            case 'is_a':
                $parts = preg_split('/\bis\s+a\b/i', $input, 2);
                break;
            case 'is':
                $parts = explode(' is ', $input, 2);
                break;
            default:
                return null;
        }

        if (count($parts) !== 2) {
            return null;
        }

        return [
            'subject' => $this->normalizeSubject($parts[0]),
            'definition' => trim($parts[1]),
        ];
    }

    /**
     * Normalize subject: trim, collapse internal whitespace, lowercase
     */
    private function normalizeSubject(string $subject): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($subject)));
    }
}
