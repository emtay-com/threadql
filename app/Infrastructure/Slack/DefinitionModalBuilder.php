<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Builds Slack modal views for definition creation
 */
class DefinitionModalBuilder
{
    /**
     * Build a definition request modal view
     *
     * @param int $queryId The query ID to include in callback
     * @param string $subject The initial subject value
     * @return array The modal view structure
     */
    public function buildDefinitionModal(int $queryId, string $subject): array
    {
        return [
            'type' => 'modal',
            'callback_id' => "request_definition_modal_{$queryId}",
            'title' => $this->buildPlainText('Add Definition'),
            'submit' => $this->buildPlainText('Save'),
            'close' => $this->buildPlainText('Cancel'),
            'blocks' => [$this->buildSubjectInput($subject), $this->buildDefinitionInput()],
        ];
    }

    /**
     * Build subject input block
     */
    private function buildSubjectInput(string $initialValue): array
    {
        return [
            'type' => 'input',
            'block_id' => 'subject_block',
            'label' => $this->buildPlainText('Subject'),
            'element' => [
                'type' => 'plain_text_input',
                'action_id' => 'subject',
                'initial_value' => $initialValue,
            ],
        ];
    }

    /**
     * Build definition input block
     */
    private function buildDefinitionInput(): array
    {
        return [
            'type' => 'input',
            'block_id' => 'definition_block',
            'label' => $this->buildPlainText('Definition'),
            'element' => [
                'type' => 'plain_text_input',
                'action_id' => 'definition',
                'multiline' => true,
                'placeholder' => $this->buildPlainText('e.g., member with status 3'),
            ],
        ];
    }

    /**
     * Build plain text structure
     */
    private function buildPlainText(string $text): array
    {
        return [
            'type' => 'plain_text',
            'text' => $text,
        ];
    }
}
