<?php

declare(strict_types=1);

namespace App\Prompt\Views;

use App\Enums\MessageRole;
use App\Infrastructure\Database\DatabaseDriver;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Lightweight object that holds an array of arguments and renders a Blade template.
 * Does not extend Laravel's View.
 */
abstract class BasePromptView implements Htmlable
{
    protected array $data = [];

    protected array $defaults;

    public function __construct(array $data = [], ?array $defaults = null)
    {
        $this->defaults = $defaults ?? app('prompt.defaults');
        $this->data = array_replace_recursive($this->defaults, $data);

        // Clean Slack mentions from user query text if present
        if (isset($this->data['user_query_text'])) {
            $this->data['user_query_text'] = $this->cleanSlackMentions($this->data['user_query_text']);
        }
    }

    /**
     * Convenience setter for merging additional data (chainable)
     */
    public function with(array $vars): static
    {
        $this->data = array_replace_recursive($this->data, $vars);

        return $this;
    }

    /**
     * Set definitions data (chainable)
     */
    public function setDefinitions(array $definitions): static
    {
        $this->data['definitions'] = $definitions;

        return $this;
    }

    /**
     * Set DDLs data (chainable)
     */
    public function setDdls(array $ddls): static
    {
        $this->data['ddls'] = $ddls;

        return $this;
    }

    /**
     * Set tables available data (chainable)
     */
    public function setTablesAvailable(array $tables): static
    {
        $this->data['tables_available'] = $tables;

        return $this;
    }

    /**
     * Set the database driver/dialect for SQL generation context (chainable)
     */
    public function setDatabaseDriver(DatabaseDriver $driver): static
    {
        $this->data['database_driver'] = $driver;
        $this->data['sql_dialect'] = $driver->sqlDialect();

        return $this;
    }

    /**
     * Set ledger data (chainable)
     */
    public function setLedger(array $steps): static
    {
        $this->data['ledger'] = $steps;

        return $this;
    }

    /**
     * Get the system prompt view name
     */
    abstract protected function viewName(): string;

    /**
     * Get the user message view name (or null if not needed)
     */
    abstract protected function userViewName(): ?string;

    /**
     * Render the complete prompt
     */
    public function render(): string
    {
        $system = view($this->viewName(), $this->data)
            ->render();

        $user = $this->userViewName()
            ? view($this->userViewName(), $this->data)
                ->render()
            : '';

        // Return combined system and user messages
        return trim($system.($user ? "\n\n".$user : ''));
    }

    /**
     * Render only the system message
     */
    public function renderSystem(): string
    {
        return view($this->viewName(), $this->data)
            ->render();
    }

    /**
     * Render only the user message
     */
    public function renderUser(): ?string
    {
        return $this->userViewName()
            ? view($this->userViewName(), $this->data)
                ->render()
            : null;
    }

    /**
     * Get messages as structured array
     */
    public function getMessages(): array
    {
        $messages = [];

        // Add system message
        $systemContent = $this->renderSystem();
        if (! empty($systemContent)) {
            $messages[] = [
                'role' => MessageRole::SYSTEM->value,
                'content' => $systemContent,
            ];
        }

        // Add user message if available
        $userContent = $this->renderUser();
        if (! empty($userContent)) {
            $messages[] = [
                'role' => MessageRole::USER->value,
                'content' => $userContent,
            ];
        }

        return $messages;
    }

    /**
     * Get the rendered content as HTML (implements Htmlable)
     */
    public function toHtml(): string
    {
        return $this->render();
    }

    /**
     * Clean Slack mentions from user query text
     */
    protected function cleanSlackMentions(string $text): string
    {
        // Strip Slack mention pattern: <@U01233456>
        $cleaned = preg_replace('/<@[A-Z0-9]+>/', 'gpt-4o', $text);

        return trim($cleaned);
    }

    /**
     * Get the current data array
     */
    public function getData(): array
    {
        return $this->data;
    }
}
