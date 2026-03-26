<?php

declare(strict_types=1);

namespace App\Services\Llm\Builders;

use App\Enums\MessageRole;

/**
 * Builder for constructing message collections for LLM prompts.
 * Provides a fluent interface for building message arrays with proper roles.
 */
class MessageCollectionBuilder
{
    /**
     * @var array<int, array{role: string, content: string}>
     */
    private array $messages = [];

    /**
     * Add a system message
     */
    public function addSystemMessage(string $content): self
    {
        if (! empty($content)) {
            $this->messages[] = [
                'role' => MessageRole::SYSTEM->value,
                'content' => $content,
            ];
        }

        return $this;
    }

    /**
     * Add a user message
     */
    public function addUserMessage(string $content): self
    {
        if (! empty($content)) {
            $this->messages[] = [
                'role' => MessageRole::USER->value,
                'content' => $content,
            ];
        }

        return $this;
    }

    /**
     * Add an assistant message
     */
    public function addAssistantMessage(string $content): self
    {
        if (! empty($content)) {
            $this->messages[] = [
                'role' => MessageRole::ASSISTANT->value,
                'content' => $content,
            ];
        }

        return $this;
    }

    /**
     * Add a message with a specific role
     */
    public function addMessage(string $role, string $content): self
    {
        if (! empty($content)) {
            $this->messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $this;
    }

    /**
     * Add a definitions message (user message with formatted definitions)
     *
     * @param array<int, array{subject: string, definition: string}> $definitions
     */
    public function addDefinitionsMessage(array $definitions): self
    {
        if (empty($definitions)) {
            return $this;
        }

        $content = 'Here is the definition, please prepare the query now.';
        $definitionLines = [];

        foreach ($definitions as $definition) {
            $definitionLines[] = $definition['subject'].' => '.$definition['definition'];
        }

        $content .= "\n\n".implode("\n", $definitionLines);

        return $this->addUserMessage($content);
    }

    /**
     * Build and return the messages array
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function build(): array
    {
        return $this->messages;
    }

    /**
     * Get the count of messages
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Check if the collection is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->messages);
    }

    /**
     * Create a new builder instance
     */
    public static function create(): self
    {
        return new self;
    }

    /**
     * Reset the builder to initial state
     */
    public function reset(): self
    {
        $this->messages = [];

        return $this;
    }
}
