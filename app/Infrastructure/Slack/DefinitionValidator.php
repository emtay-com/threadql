<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Validates definition submission data from Slack modals
 */
class DefinitionValidator
{
    /**
     * Validate definition submission state
     *
     * @param array $state The Slack view state values
     * @return array{subject: string, definition: string, errors: array}
     */
    public function validate(array $state): array
    {
        $subject = $state['subject_block']['subject']['value'] ?? '';
        $definition = $state['definition_block']['definition']['value'] ?? '';
        $errors = [];

        if (empty(trim($subject))) {
            $errors['subject_block'] = 'Please provide a subject';
        }

        if (empty(trim($definition))) {
            $errors['definition_block'] = 'Please provide a definition';
        }

        return [
            'subject' => trim($subject),
            'definition' => trim($definition),
            'errors' => $errors,
        ];
    }

    /**
     * Check if validation has errors
     */
    public function hasErrors(array $validationResult): bool
    {
        return ! empty($validationResult['errors']);
    }
}
